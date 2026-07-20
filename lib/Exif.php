<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

final class Exif
{
    /**
     * Read-mode values for how descriptive fields (see SIDECAR_DESCRIPTIVE_FIELDS)
     * are merged between a sidecar file and the embedded file, when both exist.
     * Structural fields and Orientation are unaffected by the read mode -- they
     * always come from the embedded file (see class-level docs above).
     */
    public const READ_MODE_SIDECAR_ONLY = 'sidecar_only'; // sidecar exclusive if it exists, else embedded
    public const READ_MODE_PREFER_SIDECAR = 'prefer_sidecar'; // sidecar wins per-field, embedded fills gaps
    public const READ_MODE_PREFER_FILE = 'prefer_file'; // embedded wins per-field, sidecar fills gaps
    public const READ_MODE_FILE_ONLY = 'file_only'; // sidecar ignored entirely

    /**
     * Write-mode values for where a user's metadata edits are stored.
     * Orientation always goes to the embedded file regardless of write mode
     * (see SIDECAR_DESCRIPTIVE_FIELDS docs above).
     */
    public const WRITE_MODE_SIDECAR_ONLY = 'sidecar_only'; // descriptive fields to sidecar (created if needed) only
    public const WRITE_MODE_FILE_ONLY = 'file_only'; // everything embedded in the file, no sidecar (legacy default)
    public const WRITE_MODE_FILE_AND_SIDECAR = 'file_and_sidecar'; // descriptive fields written to both
    private const FORBIDDEN_EDIT_MIMES = ['image/bmp', 'image/x-dcraw', 'video/MP2T']; // also update const.ts
    private const EXIFTOOL_TIMEOUT = 30000;
    private const EXIFTOOL_ARGS = ['-api', 'QuickTimeUTC=1', '-api', 'LargeFileSupport=1', '-n', '-json'];

    /**
     * Descriptive fields that come exclusively from a sidecar file when one exists
     * (no per-field fallback to the embedded value). Structural fields (MIMEType,
     * dimensions, video duration, ImageUniqueID, etc.) always come from the embedded
     * file since a sidecar can't carry them.
     *
     * Orientation is deliberately excluded: Nextcloud's preview/thumbnail generation
     * reads it straight from the file's bytes, not through this merged exif, so
     * routing rotation edits to a sidecar would desync previews from what the app
     * shows. Orientation is always written to (and read from) the embedded file.
     */
    private const SIDECAR_DESCRIPTIVE_FIELDS = [
        'DateTimeOriginal', 'CreateDate', 'ModifyDate', 'SubSecDateTimeOriginal',
        'OffsetTimeOriginal', 'OffsetTime',
        'GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef',
        'GPSCoordinates', 'GPSAltitude',
        'Keywords', 'Subject', 'TagsList', 'HierarchicalSubject',
        'Rating',
        // The caption/description (XMP dc:description). This is a user-editable, sidecar-owned
        // field: without it here, sidecar-only reads drop the value entirely (so a description
        // set in Immich and surfaced only via the bridge's generated sidecar never displays),
        // and writes get misrouted to the embedded file instead of the sidecar.
        'Description',
        // Title (XMP dc:title) and Label (XMP xmp:Label). Immich has no field for these, so the
        // Immich File Bridge persists them straight to the on-disk .xmp and preserves them on read.
        // Routing them through the sidecar (rather than embedding in the file) keeps them editable
        // and durable across the Memories→bridge→Immich round-trip.
        'Title', 'Label',
    ];

    private const READ_MODES = [
        self::READ_MODE_SIDECAR_ONLY,
        self::READ_MODE_PREFER_SIDECAR,
        self::READ_MODE_PREFER_FILE,
        self::READ_MODE_FILE_ONLY,
    ];

    private const WRITE_MODES = [
        self::WRITE_MODE_SIDECAR_ONLY,
        self::WRITE_MODE_FILE_ONLY,
        self::WRITE_MODE_FILE_AND_SIDECAR,
    ];

    /** Opened instance of exiftool when running in command mode */
    /** @var null|resource */
    private static $staticProc;

    /** @var null|resource[] */
    private static $staticPipes;

    /** Disable uisage of static process */
    private static bool $noStaticProc = false;

    public static function closeStaticExiftoolProc(): void
    {
        try {
            // Close I/O pipes
            if (self::$staticPipes) {
                fclose(self::$staticPipes[0]);
                fclose(self::$staticPipes[1]);
                fclose(self::$staticPipes[2]);
                self::$staticPipes = null;
            }

            // Close process
            if (self::$staticProc) {
                proc_terminate(self::$staticProc);
                proc_close(self::$staticProc);
                self::$staticProc = null;
            }
        } catch (\Exception $ex) {
        }
    }

    public static function restartStaticExiftoolProc(): void
    {
        self::closeStaticExiftoolProc();
        self::ensureStaticExiftoolProc();
    }

    public static function ensureStaticExiftoolProc(): void
    {
        if (self::$noStaticProc) {
            return;
        }

        if (!self::$staticProc) {
            self::initializeStaticExiftoolProc();
            usleep(500000); // wait if error

            /** @psalm-suppress NullArgument */
            if (!proc_get_status(self::$staticProc)['running']) {
                error_log('WARN: Failed to create stay_open exiftool process');
                self::$noStaticProc = true;
                self::$staticProc = null;
                self::$staticPipes = null;
            }

            return;
        }

        if (!proc_get_status(self::$staticProc)['running']) {
            self::$staticProc = null;
            self::$staticPipes = null;
            self::ensureStaticExiftoolProc();
        }
    }

    /**
     * Resolve a user's metadata read-mode preference (falls back to
     * READ_MODE_SIDECAR_ONLY if $userId is null, unset, or invalid).
     */
    public static function getUserReadMode(?string $userId): string
    {
        if (null === $userId) {
            return self::READ_MODE_SIDECAR_ONLY;
        }

        try {
            $userConfig = \OCP\Server::get(\OCP\Config\IUserConfig::class);
            $mode = $userConfig->getValueString($userId, Application::APPNAME, 'metadataReadMode', self::READ_MODE_SIDECAR_ONLY);
        } catch (\Exception) {
            return self::READ_MODE_SIDECAR_ONLY;
        }

        return \in_array($mode, self::READ_MODES, true) ? $mode : self::READ_MODE_SIDECAR_ONLY;
    }

    /**
     * Resolve a user's metadata write-mode preference (falls back to
     * WRITE_MODE_FILE_ONLY -- today's legacy default -- if $userId is null,
     * unset, or invalid).
     */
    public static function getUserWriteMode(?string $userId): string
    {
        if (null === $userId) {
            return self::WRITE_MODE_FILE_ONLY;
        }

        try {
            $userConfig = \OCP\Server::get(\OCP\Config\IUserConfig::class);
            $mode = $userConfig->getValueString($userId, Application::APPNAME, 'metadataWriteMode', self::WRITE_MODE_FILE_ONLY);
        } catch (\Exception) {
            return self::WRITE_MODE_FILE_ONLY;
        }

        return \in_array($mode, self::WRITE_MODES, true) ? $mode : self::WRITE_MODE_FILE_ONLY;
    }

    /**
     * Get exif data as a JSON object from a Nextcloud file.
     *
     * @return array<string, mixed>
     */
    public static function getExifFromFile(File $file, string $readMode = self::READ_MODE_SIDECAR_ONLY): array
    {
        try {
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        } catch (\Throwable $ex) {
            // https://github.com/pulsejet/memories/issues/820
            throw new \Exception("Failed to get local file: {$ex->getMessage()}");
        }

        // Check if path is valid
        if (!\is_string($path)) {
            throw new \Exception('Failed to get local file path');
        }

        // Check if file is readable
        if (!is_readable($path)) {
            throw new \Exception("File is not readable: {$path}");
        }

        $exif = self::getExifFromLocalPath($path);

        // We need to remove blacklisted fields to prevent leaking info
        unset($exif['SourceFile'], $exif['FileName'], $exif['ExifToolVersion'], $exif['Directory'], $exif['FileSize'], $exif['FileModifyDate'], $exif['FileAccessDate'], $exif['FileInodeChangeDate'], $exif['FilePermissions'], $exif['ThumbnailImage']);

        // Ignore zero dates
        $dateFields = [
            'DateTimeOriginal',
            'SubSecDateTimeOriginal',
            'CreateDate',
            'ModifyDate',
            'TrackCreateDate',
            'TrackModifyDate',
            'MediaCreateDate',
            'MediaModifyDate',
        ];
        foreach ($dateFields as $field) {
            if (\array_key_exists($field, $exif) && \is_string($exif[$field]) && str_starts_with($exif[$field], '0000:00:00')) {
                unset($exif[$field]);
            }
        }

        // Merge in sidecar data per the requested read mode. Fail open on any
        // error so a corrupt/unreadable sidecar can't break indexing or the
        // info panel. READ_MODE_FILE_ONLY skips the sidecar lookup entirely.
        if (self::READ_MODE_FILE_ONLY !== $readMode && ($sidecar = self::getSidecarFile($file))) {
            try {
                $sidecarExif = self::getSidecarExif($sidecar);
                $exif = self::mergeSidecarExif($exif, $sidecarExif, $readMode);
            } catch (\Throwable $ex) {
                error_log("WARN: failed to read sidecar exif for {$file->getPath()}: {$ex->getMessage()}");
            }
        }

        return $exif;
    }

    /**
     * Get the XMP sidecar file for a given file, if one exists.
     * Sidecar naming convention: <full filename>.xmp (e.g. IMG_1234.jpg.xmp),
     * chosen to avoid collisions between RAW+JPEG pairs sharing a basename.
     */
    public static function getSidecarFile(File $file): ?File
    {
        try {
            $parent = $file->getParent();
        } catch (\Throwable) {
            return null;
        }

        $name = $file->getName().'.xmp';
        if (!$parent->nodeExists($name)) {
            return null;
        }

        $node = $parent->get($name);

        return $node instanceof File ? $node : null;
    }

    /**
     * Get exif data as a JSON object from a local file path.
     *
     * @return array<string, mixed>
     */
    public static function getExifFromLocalPath(string $path): array
    {
        if (null !== self::$staticProc) {
            self::ensureStaticExiftoolProc();

            return self::getExifFromLocalPathWithStaticProc($path);
        }

        return self::getExifFromLocalPathWithSeparateProc($path);
    }

    /**
     * Parse date from exif format and throw error if invalid.
     *
     * @param array<string, mixed> $exif
     */
    public static function parseExifDate(array $exif): \DateTime
    {
        // Get date from exif
        $exifDate = $exif['DateTimeOriginal'] ?? $exif['CreateDate'] ?? null;

        // For videos, prefer CreateDate for timezone (QuickTimeUTC=1)
        if (preg_match('/^video\/\w+/', (string) ($exif['MIMEType'] ?? null))) {
            $exifDate = $exif['CreateDate'] ?? $exifDate;
        }

        // Check if we have a date
        if (null === $exifDate || empty($exifDate) || !\is_string($exifDate)) {
            throw new \Exception('No date found in exif');
        }

        // Get timezone from exif
        try {
            $tzStr = $exif['OffsetTimeOriginal']
                ?? $exif['OffsetTime']
                ?? $exif['LocationTZID']
                ?? throw new \Exception();

            /** @psalm-suppress ArgumentTypeCoercion */
            $exifTz = new \DateTimeZone((string) $tzStr);
        } catch (\Exception) {
            $exifTz = null;
        }

        // Force UTC if no timezone found
        $parseTz = $exifTz ?? new \DateTimeZone('UTC');

        // https://github.com/pulsejet/memories/pull/397
        // https://github.com/pulsejet/memories/issues/485

        $formats = [
            'Y:m:d H:i', // 2023:03:05 18:58
            'Y:m:d H:iO', // 2023:03:05 18:58+05:00
            'Y:m:d H:i:s', // 2023:03:05 18:58:17
            'Y:m:d H:i:sO', // 2023:03:05 10:58:17+05:00
            'Y:m:d H:i:s.u', // 2023:03:05 10:58:17.000
            'Y:m:d H:i:s.uO', // 2023:03:05 10:58:17.000Z
        ];

        /** @var \DateTime $dt */
        $parsedDate = null;

        foreach ($formats as $format) {
            if ($parsedDate = \DateTime::createFromFormat($format, $exifDate, $parseTz)) {
                break;
            }
        }

        // If we couldn't parse the date, throw an error
        if (!$parsedDate) {
            throw new \Exception("Invalid date: {$exifDate}");
        }

        // Epoch timestamp
        $timestamp = $parsedDate->getTimestamp();

        // Filter out dates before 1800 A.D.
        if ($timestamp < -5364662400) { // 1800 A.D.
            throw new \Exception("Date too old: {$exifDate}");
        }

        // Filter out January 1, 1904 12:00:00 AM UTC
        // Exiftool returns this as the date when QuickTimeUTC is set and
        // the date is set to 0000:00:00 00:00:00
        if (-2082844800 === $timestamp) {
            throw new \Exception("Blacklisted date: {$exifDate}");
        }

        // Force the timezone to be the same as parseTz
        if ($exifTz) {
            $parsedDate->setTimezone($exifTz);
        }

        return $parsedDate;
    }

    /**
     * Get the date taken from either the file or exif data if available.
     *
     * @param array<string, mixed> $exif
     */
    public static function getDateTaken(File $file, array $exif): \DateTime
    {
        try {
            return self::parseExifDate($exif);
        } catch (\Exception) {
        } catch (\ValueError) {
        }

        // Fall back to modification time
        $dt = new \DateTime('@'.$file->getMtime());

        // Set timezone to system timezone
        $tz = SystemConfig::get('default_timezone') ?: getenv('TZ') ?: date_default_timezone_get();

        try {
            $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Exception) {
            throw new \Error("FATAL: system timezone is invalid (TZ): {$tz}");
        }

        return $dt;
    }

    /**
     * Convert time to local date in UTC.
     */
    public static function forgetTimezone(\DateTime $date): \DateTime
    {
        return new \DateTime($date->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    /**
     * Get image dimensions from Exif data.
     *
     * @param array<string, mixed> $exif
     *
     * @return int[]
     *
     * @psalm-return list{int, int}
     */
    public static function getDimensions(array $exif): array
    {
        $width = $exif['ImageWidth'] ?? 0;
        $height = $exif['ImageHeight'] ?? 0;

        // Check if image is rotated and we need to swap width and height
        $rotation = $exif['Rotation'] ?? 0;
        $orientation = $exif['Orientation'] ?? 0;
        if (\in_array($orientation, [5, 6, 7, 8], true) || \in_array($rotation, [90, 270], true)) {
            return [$height, $width];
        }

        if ($width <= 0 || $height <= 0 || $width > 100000 || $height > 100000) {
            return [0, 0];
        }

        return [$width, $height];
    }

    /**
     * Get the Approximate Unique ID (AUID) from parameters.
     *
     * @param int $epoch the date taken as a unix timestamp (seconds)
     * @param int $size  the file size in bytes
     */
    public static function getAUID(int $epoch, int $size): string
    {
        return md5($epoch.$size);
    }

    /**
     * Get the Basename approximate Unique ID (BUID) from parameters.
     *
     * @param string $basename      the basename of the file
     * @param mixed  $imageUniqueID EXIF field
     * @param int    $size          the file size in bytes (fallback)
     */
    public static function getBUID(string $basename, mixed $imageUniqueID, int $size): string
    {
        $sfx = "size={$size}";
        if (null !== $imageUniqueID && \strlen((string) $imageUniqueID) >= 4) {
            $sfx = "iuid={$imageUniqueID}";
        }

        return md5($basename.$sfx);
    }

    /**
     * Extract embedded tags from EXIF data.
     *
     * @param array $exif    EXIF data
     * @param bool  $flatten Whether to flatten the tags into a single array (hierarchy is represented by /)
     *
     * @return array Array of tag paths
     */
    public static function extractEmbeddedTags(array $exif, bool $flatten = false): array
    {
        $embeddedTags = [];
        $tagSet = []; // For deduplication

        // Helper function to ensure we have an array
        $ensureArray = static function ($value) {
            if (empty($value)) {
                return [];
            }

            return \is_array($value) ? $value : [$value];
        };

        // Helper function to add tags with normalization and deduplication
        $addTags = static function ($tags, $separator = '/') use (&$embeddedTags, &$tagSet): void {
            foreach ($tags as $tag) {
                $tagPath = \is_array($tag) ? $tag : explode($separator, $tag);
                // Normalize to '/' and lowercase for deduplication
                $normalizedKey = strtolower(implode('/', $tagPath));

                if (!isset($tagSet[$normalizedKey])) {
                    $tagSet[$normalizedKey] = true;
                    $embeddedTags[] = $tagPath;
                }
            }
        };

        // Extract from TagsList (split by '/')
        if (!empty($exif['TagsList'])) {
            $tagsList = $ensureArray($exif['TagsList']);
            $addTags($tagsList, '/');
        }

        // Extract from HierarchicalSubject (split by '|')
        if (!empty($exif['HierarchicalSubject'])) {
            $hierarchicalSubject = $ensureArray($exif['HierarchicalSubject']);
            $addTags($hierarchicalSubject, '|');
        }

        // Extract from Keywords (might contain paths with '/' or '|')
        if (!empty($exif['Keywords'])) {
            $keywords = $ensureArray($exif['Keywords']);
            foreach ($keywords as $keyword) {
                // Keywords is not reliably a list tag (e.g. XMP-pdf:Keywords
                // is a scalar): a writer assigning several values to it may
                // have joined them with ", ", so split that back apart first
                // -- otherwise two unrelated tags read back glued into one
                // garbled hierarchical path.
                foreach (preg_split('/,\s*/', $keyword) as $part) {
                    if ('' === $part) {
                        continue;
                    }

                    // Keywords might contain paths with '/' or '|' separator
                    if (str_contains($part, '/')) {
                        $addTags([$part], '/');
                    } elseif (str_contains($part, '|')) {
                        $addTags([$part], '|');
                    } else {
                        $addTags([[$part]], '/');
                    }
                }
            }
        }

        // Extract from Subject (as individual tags)
        if (!empty($exif['Subject'])) {
            $subject = $ensureArray($exif['Subject']);
            foreach ($subject as $tag) {
                $addTags([[$tag]], '/');
            }
        }

        // Filter out component tags
        $embeddedTags = self::filterComponentTags($embeddedTags);

        if ($flatten) {
            $embeddedTags = array_map(static function ($tag) {
                return implode('/', $tag);
            }, $embeddedTags);
        }

        return $embeddedTags;
    }

    /**
     * Check whether a flattened embedded tag path (as returned by
     * extractEmbeddedTags($exif, true), e.g. "Family/Vacation/Beach") lies
     * at or under a browsed tag path. Used for the embedded tags folder-style
     * browsing view, both for the PHP-side EXIF filter fallback (non-MySQL
     * DBs) and to keep semantics identical to the SQL-side matching.
     *
     * @param string $tagPath    full path of an embedded tag on a photo
     * @param string $browsePath path currently being browsed
     * @param bool   $recursive  whether tags anywhere under $browsePath count, not just exact matches
     */
    public static function embeddedTagPathMatches(string $tagPath, string $browsePath, bool $recursive): bool
    {
        if ('' === $browsePath) {
            return true;
        }

        if (0 === strcasecmp($tagPath, $browsePath)) {
            return true;
        }

        return $recursive && 0 === strncasecmp($tagPath, $browsePath.'/', \strlen($browsePath) + 1);
    }

    /**
     * Get the list of MIME Types that are allowed to be edited.
     */
    public static function allowedEditMimetypes(): array
    {
        return array_diff(array_merge(Application::IMAGE_MIMES, Application::VIDEO_MIMES), self::FORBIDDEN_EDIT_MIMES);
    }

    /**
     * Set exif data using raw json.
     *
     * @param string               $path to local file
     * @param array<string, mixed> $data exif data
     *
     * @throws \Exception on failure
     */
    public static function setExif(string $path, array $data): void
    {
        $data['SourceFile'] = $path;
        $raw = json_encode([$data], JSON_UNESCAPED_UNICODE);
        $cmd = array_merge(self::getExiftool(), [
            '-overwrite_original_in_place', '-n',
            '-api', 'LargeFileSupport=1',
            '-json=-', $path,
        ]);

        try {
            $output = Util::execSafe2($cmd, self::EXIFTOOL_TIMEOUT, $raw, true, true);
            $stdout = $output[0];
            $stderr = $output[1];
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }

        if (null !== $stderr && str_contains($stderr, 'Error')) {
            error_log("Exiftool error: {$stderr}");

            throw new \Exception('Could not set exif data: '.$stderr);
        }
        if (null === $stdout || str_contains($stdout, 'Error')) {
            $stdout = $stdout ?? $stderr ?? 'Error: Unknown cmd fail';
            error_log("Exiftool error: {$stdout}");

            throw new \Exception('Could not set exif data: '.$stdout);
        }

        error_log('MEMORIES_DEBUG: setExif exiftool stdout='.trim((string) $stdout).' stderr='.trim((string) $stderr));
    }

    /**
     * Set exif data using a raw array.
     *
     * @param array<string, mixed> $data      exif data
     * @param string               $writeMode one of the WRITE_MODE_* constants, controlling
     *                                        where descriptive fields (dates, GPS, tags,
     *                                        rating) get stored. Orientation always goes to
     *                                        the embedded file regardless of write mode,
     *                                        since preview generation reads it from the
     *                                        file's bytes, not through this merged exif.
     */
    public static function setFileExif(File $file, array $data, string $writeMode = self::WRITE_MODE_FILE_ONLY): void
    {
        error_log('MEMORIES_DEBUG: setFileExif path='.$file->getPath().' writeMode='.$writeMode.' data='.json_encode($data, JSON_UNESCAPED_UNICODE));

        // Tracks whether writeEmbedded() ran on $file itself (not a sidecar)
        // during this call -- if so, its bytes (and therefore mtime) already
        // changed via the write, and touch() below must be skipped: on
        // non-local storages (e.g. a WebDAV mount) touch() re-checks
        // file_exists() right after we just wrote the file, and if that
        // stat races with the backend (any eventual-consistency lag), core's
        // DAV storage falls back to file_put_contents($path, '') -- silently
        // truncating the file we just wrote.
        $wroteEmbeddedFile = false;

        switch ($writeMode) {
            case self::WRITE_MODE_SIDECAR_ONLY:
                $sidecarData = array_intersect_key($data, array_flip(self::SIDECAR_DESCRIPTIVE_FIELDS));
                $embeddedData = array_diff_key($data, $sidecarData);

                if ($sidecarData) {
                    self::setSidecarExif($file, $sidecarData);
                }
                if ($embeddedData) {
                    self::writeEmbedded($file, $embeddedData);
                    $wroteEmbeddedFile = true;
                }

                break;

            case self::WRITE_MODE_FILE_AND_SIDECAR:
                $sidecarData = array_intersect_key($data, array_flip(self::SIDECAR_DESCRIPTIVE_FIELDS));
                if ($sidecarData) {
                    self::setSidecarExif($file, $sidecarData);
                }

                // Full data (including Orientation) still goes to the file too.
                self::writeEmbedded($file, $data);
                $wroteEmbeddedFile = true;

                break;

            case self::WRITE_MODE_FILE_ONLY:
            default:
                self::writeEmbedded($file, $data);
                $wroteEmbeddedFile = true;

                break;
        }

        // Touch the file so its mtime reflects this update -- but only when
        // writeEmbedded() didn't just run on it. Critical in sidecar mode:
        // the main file's bytes may not have changed on their own, but
        // TimelineWrite::processFile compares mtime against the previously
        // indexed row to decide whether to re-read exif -- the touch must
        // land before the event dispatch below, or the listener sees the
        // pre-touch mtime, assumes nothing changed, and skips the reindex
        // entirely (this DB cache is what info()/tags read from, so a
        // skipped reindex means the edit never appears). When the file WAS
        // just rewritten, its mtime is already fresh from that write, so
        // touching it again is both unnecessary and (per above) unsafe.
        if (!$wroteEmbeddedFile) {
            $file->touch();
        }

        // Dispatch NodeWrittenEvent to trigger processing by other apps
        try {
            $eventDispatcher = \OCP\Server::get(IEventDispatcher::class);
            $eventDispatcher->dispatchTyped(new NodeWrittenEvent($file));
        } catch (\Exception) {
            // Not our problem
        }
    }

    public static function getBinaryExifProp(string $path, string $prop): string
    {
        $cmd = array_merge(self::getExiftool(), [$prop, '-n', '-b', $path]);

        try {
            return Util::execSafe($cmd, self::EXIFTOOL_TIMEOUT) ?? '';
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }
    }

    public static function getExifWithDuplicates(string $path): array
    {
        return self::getExifFromLocalPathWithSeparateProc($path, ['-U', '-G4']);
    }

    /**
     * Merge descriptive sidecar fields into the embedded exif per the read mode.
     * $sidecarExif is already restricted to SIDECAR_DESCRIPTIVE_FIELDS by getSidecarExif().
     *
     * @param array<string, mixed> $embedded
     * @param array<string, mixed> $sidecarExif
     *
     * @return array<string, mixed>
     */
    private static function mergeSidecarExif(array $embedded, array $sidecarExif, string $readMode): array
    {
        switch ($readMode) {
            case self::READ_MODE_PREFER_FILE:
                // Embedded wins per-field; sidecar only fills fields missing from embedded.
                return array_merge($sidecarExif, $embedded);

            case self::READ_MODE_PREFER_SIDECAR:
                // Sidecar wins per-field; embedded fills fields missing from sidecar.
                return array_merge($embedded, $sidecarExif);

            case self::READ_MODE_SIDECAR_ONLY:
            default:
                // Sidecar is exclusive for descriptive fields, no per-field fallback.
                foreach (self::SIDECAR_DESCRIPTIVE_FIELDS as $field) {
                    unset($embedded[$field]);
                }

                return array_merge($embedded, $sidecarExif);
        }
    }

    /**
     * Read exif from a sidecar file, restricted to the descriptive-field allowlist.
     *
     * @return array<string, mixed>
     */
    private static function getSidecarExif(File $sidecar): array
    {
        $path = $sidecar->getStorage()->getLocalFile($sidecar->getInternalPath());
        if (!\is_string($path) || !is_readable($path)) {
            throw new \Exception('Sidecar file not readable');
        }

        $exif = self::getExifFromLocalPath($path);

        return array_intersect_key($exif, array_flip(self::SIDECAR_DESCRIPTIVE_FIELDS));
    }

    /**
     * Filter out tags that are components of hierarchical tags
     * For example, if we have "Country/Italy", don't also show "Country" or "Italy".
     */
    private static function filterComponentTags(array $tags): array
    {
        if (empty($tags)) {
            return $tags;
        }

        // Step 1: Collect all tags into normalized collections
        $flatTags = []; // Single-part tags (no separator)
        $hierarchicalTags = []; // Multi-part tags (length > 1)
        $hierarchicalTagParts = []; // All parts from hierarchical tags

        foreach ($tags as $tag) {
            $normalized = array_map('strtolower', $tag);

            if (1 === \count($normalized)) {
                // Single-part tag
                $flatTags[$normalized[0]] = $tag;
            } else {
                // Multi-part hierarchical tag
                $hierarchicalTags[] = $tag;

                // Add all parts to hierarchicalTagParts
                foreach ($normalized as $part) {
                    $hierarchicalTagParts[$part] = true;
                }
            }
        }

        // Step 2: Remove hierarchical tags that are a strict ancestor-prefix of
        // a longer hierarchical tag. Tools like Lightroom/digiKam write every
        // ancestor segment of an assigned tag as its own entry (e.g. "A",
        // "A/B", "A/B/C" all describe the *same* assigned tag "A/B/C"), so
        // the shorter prefixes are not independently assigned tags.
        foreach ($hierarchicalTags as $i => $tag) {
            $normalized = array_map('strtolower', $tag);
            foreach ($hierarchicalTags as $j => $other) {
                if ($i === $j) {
                    continue;
                }

                $otherNormalized = array_map('strtolower', $other);
                if (\count($otherNormalized) > \count($normalized)
                    && \array_slice($otherNormalized, 0, \count($normalized)) === $normalized) {
                    unset($hierarchicalTags[$i]);

                    break;
                }
            }
        }

        // Step 3: Combination - remove flat tags that are parts of hierarchical tags
        $result = [];

        // Add flat tags that are NOT components of hierarchical tags
        foreach ($flatTags as $flatTagKey => $originalTag) {
            if (!isset($hierarchicalTagParts[$flatTagKey])) {
                $result[] = $originalTag;
            }
        }

        // Add all (remaining) hierarchical tags
        foreach ($hierarchicalTags as $hierarchicalTag) {
            $result[] = $hierarchicalTag;
        }

        return $result;
    }

    /**
     * Write exif data directly into the given file (today's default behavior).
     *
     * @param array<string, mixed> $data exif data
     */
    private static function writeEmbedded(File $file, array $data): void
    {
        // Get path to local file so we can skip reading
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!$path) {
            throw new \Exception('Failed to get local file path');
        }

        error_log('MEMORIES_DEBUG: writeEmbedded file='.$file->getPath().' localPath='.$path.' isLocal='.($file->getStorage()->isLocal() ? '1' : '0').' data='.json_encode($data, JSON_UNESCAPED_UNICODE));

        // Set exif data
        self::setExif($path, $data);

        // Update remote file if not local
        if (!$file->getStorage()->isLocal()) {
            error_log('MEMORIES_DEBUG: writeEmbedded uploading '.filesize($path).' bytes back to '.$file->getPath());
            $file->putContent(fopen($path, 'r')); // closes the handler
            error_log('MEMORIES_DEBUG: writeEmbedded upload complete for '.$file->getPath());
        }
    }

    /**
     * Write descriptive exif data to the XMP sidecar file for $file,
     * creating the sidecar if it does not already exist.
     *
     * @param array<string, mixed> $data exif data (already restricted to descriptive fields)
     */
    private static function setSidecarExif(File $file, array $data): void
    {
        error_log('MEMORIES_DEBUG: setSidecarExif file='.$file->getPath().' data='.json_encode($data, JSON_UNESCAPED_UNICODE));

        // A bare XMP file has no embedded IPTC/JPEG container to anchor the
        // generic "Keywords" tag to, so ExifTool resolves it to the scalar
        // XMP-pdf:Keywords property instead of a proper list. Writing more
        // than one value there silently joins them into one comma-separated
        // string, which a later read then mis-parses as a single (garbled)
        // hierarchical tag, permanently merging unrelated tags together.
        // TagsList/HierarchicalSubject/Subject already carry the same data
        // via tags that are genuinely list-typed, so just drop it here.
        unset($data['Keywords']);

        // Let this throw naturally (write path, not fail-open like reads)
        $parent = $file->getParent();
        $name = $file->getName().'.xmp';

        if ($parent->nodeExists($name)) {
            $sidecar = $parent->get($name);
            if (!$sidecar instanceof File) {
                throw new \Exception("Sidecar path exists but is not a file: {$name}");
            }

            self::writeEmbedded($sidecar, $data);

            return;
        }

        // Guard sidecar creation: two concurrent edits on a file with no sidecar
        // yet must not both observe "missing" and both try to create the node.
        $lockingProvider = \OCP\Server::get(\OCP\Lock\ILockingProvider::class);
        $lockKey = 'memories/sidecar/'.$file->getId();
        $lockingProvider->acquireLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);

        try {
            // Re-check inside the lock in case another request just created it
            if ($parent->nodeExists($name)) {
                $sidecar = $parent->get($name);
                if ($sidecar instanceof File) {
                    self::writeEmbedded($sidecar, $data);

                    return;
                }
            }

            // Write to a local temp path first (exiftool creates a standalone XMP
            // file from scratch when the target doesn't exist), then create the
            // Nextcloud node from the result.
            $tmpPath = BinExt::getTmpPath().'/memories-sidecar-'.bin2hex(random_bytes(8)).'.xmp';

            try {
                self::setExif($tmpPath, $data);

                if (!is_readable($tmpPath)) {
                    throw new \Exception('Exiftool did not produce a sidecar file');
                }

                $parent->newFile($name, file_get_contents($tmpPath));
            } finally {
                @unlink($tmpPath);
            }
        } finally {
            $lockingProvider->releaseLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private static function getExiftool(): array
    {
        return BinExt::getExiftool();
    }

    /**
     * Initialize static exiftool process for local reads.
     */
    private static function initializeStaticExiftoolProc(): void
    {
        self::closeStaticExiftoolProc();
        self::$staticPipes = [];
        self::$staticProc = proc_open(array_merge(self::getExiftool(), ['-stay_open', 'true', '-@', '-']), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], self::$staticPipes);
        stream_set_blocking(self::$staticPipes[1], false);
    }

    private static function getExifFromLocalPathWithStaticProc(string $path): array
    {
        // This function should not be called if there is no static process
        if (!self::$staticPipes) {
            throw new \Error('[BUG] No static pipes found');
        }

        // Create arguments for exiftool
        $args = implode("\n", self::EXIFTOOL_ARGS);
        fwrite(self::$staticPipes[0], "{$path}\n{$args}\n-execute\n");
        fflush(self::$staticPipes[0]);

        // The output of exiftool's stay_open process ends with this token
        $readyToken = "\n{ready}\n";

        try {
            $buf = Util::readOrTimeout(self::$staticPipes[1], self::EXIFTOOL_TIMEOUT, $readyToken);

            // The output buffer should always contain the ready token
            // (this is the point of readOrTimeout)
            $tokPos = strrpos($buf, $readyToken);
            if (false === $tokPos) {
                throw new \Error('[BUG] No ready token found in output buffer');
            }

            // Slice everything before the ready token
            $buf = substr($buf, 0, $tokPos);

            return self::processStdout($buf);
        } catch (\Exception) {
            error_log("ERROR: Exiftool may have crashed, restarting process [{$path}]");
            self::restartStaticExiftoolProc();

            throw new \Exception('Nothing to read from Exiftool');
        }
    }

    private static function getExifFromLocalPathWithSeparateProc(string $path, array $extraArgs = []): array
    {
        $cmd = array_merge(self::getExiftool(), self::EXIFTOOL_ARGS, $extraArgs, [$path]);

        try {
            $stdout = Util::execSafe($cmd, self::EXIFTOOL_TIMEOUT) ?? '';
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }

        return self::processStdout($stdout);
    }

    /** Get json array from stdout of exiftool */
    private static function processStdout(string $stdout): array
    {
        $json = json_decode($stdout, true);
        if (!$json) {
            throw new \Exception('Failed to parse exiftool output as JSON');
        }

        if (!\is_array($json) || !\count($json)) {
            throw new \Exception('Exiftool output is not an array with at least one element');
        }

        return $json[0];
    }
}
