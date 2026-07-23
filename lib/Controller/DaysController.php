<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Controller;

use OCA\Memories\ClustersBackend;
use OCA\Memories\Db\FilterExpression;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;

final class DaysController extends GenericApiController
{
    #[NoAdminRequired]
    #[PublicPage]
    public function days(): Http\Response
    {
        return Util::guardEx(function () {
            $list = $this->tq->getDays(
                $this->isRecursive(),
                $this->isArchive(),
                $this->getGroupMode(),
                $this->isGroupReverse(),
                $this->getTransformations(),
            );

            // Preload some day responses
            $this->preloadDays($list);

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * @param int[] $dayIds
     */
    #[NoAdminRequired]
    #[PublicPage]
    public function day(array $dayIds): Http\Response
    {
        return Util::guardEx(function () use ($dayIds) {
            // Run actual query
            $list = $this->tq->getDay(
                $dayIds,
                $this->isRecursive(),
                $this->isArchive(),
                $this->isHidden(),
                $this->getGroupMode(),
                $this->getSortKey(),
                $this->isSortReverse(),
                $this->getMinRating(),
                $this->getEmbeddedTags(),
                $this->getCollabTags(),
                $this->getTransformations(),
            );

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    #[PublicPage]
    public function dayGet(string $id): Http\Response
    {
        // Split at commas and convert all parts to int
        return $this->day(array_map(static fn ($p) => (int) $p, explode(',', $id)));
    }

    /**
     * Get transformations depending on the request.
     */
    private function getTransformations(): array
    {
        $transforms = [];

        // Add clustering transforms
        $clusterTs = ClustersBackend\Manager::getTransforms($this->request);
        $transforms = array_merge($transforms, $clusterTs);

        // Other transforms not allowed for public shares
        if (!Util::isLoggedIn()) {
            return $transforms;
        }

        // Filter only favorites
        if ($this->request->getParam('fav')) {
            $transforms[] = [$this->tq, 'transformFavoriteFilter'];
        }

        // Filter only videos
        if ($this->request->getParam('vid')) {
            $transforms[] = [$this->tq, 'transformVideoFilter'];
        }

        // Filter geological bounds
        if ($bounds = $this->request->getParam('mapbounds')) {
            $transforms[] = [$this->tq, 'transformMapBoundsFilter', $bounds];
        }

        // Rating range filter - only if SQL filtering is enabled
        $minRating = $this->getMinRating();
        $maxRating = $this->getMaxRating();
        if ($this->tq->shouldFilterExifBySQL() && ($minRating > 0 || $maxRating > 0)) {
            $transforms[] = [$this->tq, 'transformRatingRangeFilter', $minRating, $maxRating];
        }

        // Embedded tags filter - only if SQL filtering is enabled
        if ($this->tq->shouldFilterExifBySQL() && ($embeddedTags = $this->getEmbeddedTags())) {
            if (!empty($embeddedTags)) {
                $transforms[] = [$this->tq, 'transformEmbeddedTagsFilter', $embeddedTags];
            }
        }

        // Filename / filepath filters - plain filecache lookups, no EXIF/dialect dependency
        if ('' !== ($filename = trim((string) $this->request->getParam('filename', '')))) {
            $transforms[] = [$this->tq, 'transformFilenameFilter', $filename];
        }
        if ('' !== ($filepath = trim((string) $this->request->getParam('filepath', '')))) {
            $transforms[] = [$this->tq, 'transformFilepathFilter', $filepath];
        }

        // Date range filter (dayId range resolved from plain YYYY-MM-DD dates)
        [$dateStartDayId, $dateEndDayId] = $this->getDateRangeFilter();
        if (null !== $dateStartDayId || null !== $dateEndDayId) {
            $transforms[] = [$this->tq, 'transformDateRangeFilter', $dateStartDayId, $dateEndDayId];
        }

        // File type filter (mimetype patterns, e.g. "image/%" or a specific format)
        if (!empty($mimePatterns = $this->getFileTypePatterns())) {
            $transforms[] = [$this->tq, 'transformFileTypeFilter', $mimePatterns];
        }

        // Camera make/model/lens filter - only if SQL filtering is enabled
        $camMake = trim((string) $this->request->getParam('camMake', ''));
        $camModel = trim((string) $this->request->getParam('camModel', ''));
        $camLens = trim((string) $this->request->getParam('camLens', ''));
        if ($this->tq->shouldFilterExifBySQL() && ('' !== $camMake || '' !== $camModel || '' !== $camLens)) {
            $transforms[] = [
                $this->tq, 'transformCameraFilter',
                '' !== $camMake ? $camMake : null,
                '' !== $camModel ? $camModel : null,
                '' !== $camLens ? $camLens : null,
            ];
        }

        // Tag/embeddedTag/recognize/facerecognition/place boolean expression
        // filter. Unlike the two filters above, this is NOT gated on
        // shouldFilterExifBySQL(): tag/recognize/facerecognition/place leaves
        // are plain relational joins with no EXIF/dialect dependency at all,
        // and embeddedTag leaves never attempt real SQL matching in the first
        // place (they always compile to a safe TRUE/FALSE placeholder, see
        // FilterExpression) -- so this works identically on every database.
        if ($filterExpr = FilterExpression::fromRequest($this->request)) {
            $transforms[] = [$this->tq, 'transformFilterExprFilter', $filterExpr];
        }

        // Limit number of responses for day query
        if ($limit = $this->request->getParam('limit')) {
            $transforms[] = [$this->tq, 'transformLimit', (int) $limit];
        }

        // Add extra fields for native callers
        if (Util::callerIsNative()) {
            $transforms[] = [$this->tq, 'transformNativeQuery'];
        }

        return $transforms;
    }

    /**
     * Preload a few "day" at the start of "days" response.
     *
     * @param array $days the days array (modified in place)
     */
    private function preloadDays(array &$days): void
    {
        // Do not preload anything for native clients.
        // Since the contents of preloads are trusted, clients will not load locals.
        if (Util::callerIsNative() || $this->noPreload()) {
            return;
        }

        // Construct map of dayid-day
        $totalCount = 0;
        $drefMap = [];
        foreach ($days as &$day) {
            if ($count = (int) $day['count']) {
                $totalCount += max($count, 10); // max 5 days
            }

            $dayId = (int) $day['dayid'];
            $drefMap[$dayId] = &$day;

            if ($totalCount >= 50) { // should be enough
                break;
            }
        }

        if (!$totalCount) {
            return;
        }

        // Preload photos for these days
        $details = $this->tq->getDay(
            array_keys($drefMap),
            $this->isRecursive(),
            $this->isArchive(),
            $this->isHidden(),
            $this->getGroupMode(),
            $this->getSortKey(),
            $this->isSortReverse(),
            $this->getMinRating(),
            $this->getEmbeddedTags(),
            $this->getCollabTags(),
            $this->getTransformations(),
        );

        // Load details into map byref
        foreach ($details as $photo) {
            $dayId = (int) $photo['dayid'];
            if (!($drefMap[$dayId] ?? null)) {
                continue;
            }

            // Only include photos that are in the fileIds array (if it exists)
            $dayData = $drefMap[$dayId];
            if (isset($dayData['fileIds']) && !empty($dayData['fileIds'])) {
                $photoFileId = (int) $photo['fileid'];
                if (!\in_array($photoFileId, $dayData['fileIds'], true)) {
                    continue;
                }
            }

            if (!($drefMap[$dayId]['detail'] ?? null)) {
                $drefMap[$dayId]['detail'] = [];
            }

            $drefMap[$dayId]['detail'][] = $photo;
        }
    }

    private function isRecursive(): bool
    {
        return null === $this->request->getParam('folder') || $this->request->getParam('recursive');
    }

    private function isArchive(): bool
    {
        return null !== $this->request->getParam('archive');
    }

    private function isHidden(): bool
    {
        return null !== $this->request->getParam('hidden');
    }

    private function noPreload(): bool
    {
        return null !== $this->request->getParam('nopreload');
    }

    private function getGroupMode(): string
    {
        $mode = (string) $this->request->getParam('groupMode', 'day');

        return \in_array($mode, ['day', 'week', 'month', 'year'], true) ? $mode : 'day';
    }

    private function getSortKey(): string
    {
        $key = (string) $this->request->getParam('sortKey', 'created');

        return \in_array($key, ['created', 'updated', 'name'], true) ? $key : 'created';
    }

    private function isGroupReverse(): bool
    {
        return null !== $this->request->getParam('groupReverse');
    }

    private function isSortReverse(): bool
    {
        return null !== $this->request->getParam('sortReverse');
    }

    private function getMinRating(): int
    {
        return (int) $this->request->getParam('minRating') ?? 0;
    }

    private function getMaxRating(): int
    {
        return (int) $this->request->getParam('maxRating', 0);
    }

    private function getEmbeddedTags(): array
    {
        $embeddedTagsParam = $this->request->getParam('embeddedTags');
        if ($embeddedTagsParam) {
            // Decode URI-encoded string before splitting
            $decoded = urldecode($embeddedTagsParam);

            return explode(',', $decoded);
        }

        return [];
    }

    private function getCollabTags(): bool
    {
        return null !== $this->request->getParam('collabTags');
    }

    /**
     * Resolve the `dateStart`/`dateEnd` request params (plain "YYYY-MM-DD"
     * dates, e.g. an HTML date input's native value -- deliberately not
     * dayIds or timestamps, so the frontend never has to know about the
     * dayid=floor(localUnixTime/86400) convention or replicate it) into a
     * dayId range, using the exact same "treat the date as if it were
     * already UTC" convention TimelineWrite uses when computing dayid for a
     * photo (see its "assume everything happens in Greenwich" comment) --
     * this must match that convention exactly, or date-range filtering would
     * be silently off by a day for some users.
     *
     * @return array{0: ?int, 1: ?int} [startDayId, endDayId], either may be null
     */
    private function getDateRangeFilter(): array
    {
        return [
            $this->dateStringToDayId((string) $this->request->getParam('dateStart', '')),
            $this->dateStringToDayId((string) $this->request->getParam('dateEnd', '')),
        ];
    }

    private function dateStringToDayId(string $date): ?int
    {
        $date = trim($date);
        if ('' === $date) {
            return null;
        }

        // The "!" prefix resets every field the format doesn't specify
        // (H:i:s and beyond) to the Unix epoch's defaults, i.e. midnight --
        // so this is always exactly midnight on the given date, in UTC.
        $dt = \DateTime::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (false === $dt) {
            return null;
        }

        return intdiv($dt->getTimestamp(), 86400);
    }

    /**
     * Resolve the `fileType` request param into a list of mimetype LIKE
     * patterns. Accepts a comma-separated list of either a wildcard prefix
     * (e.g. "image/%" for every image, "video/%" for every video) or an
     * exact mimetype (e.g. "image/x-canon-cr2" for a specific RAW format).
     *
     * @return string[]
     */
    private function getFileTypePatterns(): array
    {
        $param = (string) $this->request->getParam('fileType', '');
        if ('' === $param) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', urldecode($param)))));
    }
}
