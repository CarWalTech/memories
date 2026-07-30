<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exceptions;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITags;

/**
 * Compiles and evaluates a user-supplied boolean filter expression tree over
 * every filterable field -- the AND/OR/NOT operator model for the Advanced
 * filter tab. A tree node is either:.
 *
 *   ['kind' => 'leaf', 'field' => FIELDS[...], 'op' => ?string, 'value' => string]
 *   ['kind' => 'group', 'op' => 'and'|'or'|'not', 'children' => [node, ...]]  ('not' takes exactly 1 child)
 *
 * Fields fall into four families (see the *_FIELDS constants below), each
 * with its own `op`/`value` shape:
 *   - Set-membership (tag/embeddedTag/recognize/facerecognition/place/
 *     extension/fileType): no `op`; each value is its own leaf, multiple
 *     values combined via and/or groups.
 *       - tag:             systemtag numeric ID (stable across renames), as a string
 *       - embeddedTag:     flattened tag path, e.g. "Family/Vacation/Beach" (see Exif::extractEmbeddedTags())
 *       - recognize:       "<uid>/<id-or-name>" -- numeric cluster id (-1 is Recognize's
 *                          "unclustered" bucket) if the frontend has one, else the cluster title
 *       - facerecognition: "<uid>/<id-or-name>" -- numeric person id if the frontend has
 *                          one, else the person's name (its own API only exposes a numeric
 *                          id for *unnamed* clusters; a named person's `cluster_id` field
 *                          is the name itself, see FaceRecognitionBackend::getClustersInternal())
 *       - place:           OSM id, as a string
 *       - extension:       lowercase file extension without the dot, e.g. "jpg"
 *       - fileType:        mimetype pattern, e.g. "image/%" or an exact mimetype
 *   - Range (rating/date): `op` is 'gte'|'lte'; value is numeric (rating) or
 *     "YYYY-MM-DD" (date, converted via Util::dateStringToDayId()). A range
 *     is expressed as AND(field gte a, field lte b).
 *   - Boolean (favorite): no `op`; value is 'true'|'false'.
 *   - Text (filename/filepath/camMake/camModel/camLens): `op` is one of
 *     'contains'|'notContains'|'startsWith'|'endsWith'|'equals'|'regex'
 *     ('regex' only valid for filename/filepath); value is free text.
 *
 * tag/place are always a numeric id: both are exact, deterministic relational
 * facts with no derived-text ambiguity, so they're safe to compile to SQL and
 * negate directly. recognize/facerecognition accept *either* an id or a name
 * for the same reason their own transformDayQuery() methods do (mirrored
 * here): is_numeric() decides which column to match on, exactly like the
 * single-cluster browsing code already does. date/fileType/extension/
 * favorite/filename/filepath (except regex on a dialect without a native
 * regex operator) are likewise exact relational/filecache facts -- see each
 * compile*Leaf() method.
 *
 * embeddedTag, rating, camMake, camModel, and camLens are different: EXIF
 * values require either real text processing (Exif::extractEmbeddedTags()
 * splits comma-joined values and infers hierarchical paths from '/'/'|'
 * separators, so a requested tag string can legitimately not appear verbatim
 * in storage -- see TimelineQueryFilters::transformEmbeddedTagsFilter() for a
 * fuller account) or dialect-limited JSON extraction (rating/camera are
 * stored in the `exif` JSON column, only queryable in SQL on MySQL/Postgres,
 * see SQL::exifSqlSupported()). These fields are therefore treated as
 * *uncertain* in SQL -- ALWAYS, regardless of dialect, so that
 * transformFilterExprFilter() never needs to gate on shouldFilterExifBySQL()
 * at all: compileSqlSuperset() never asserts one of these leaves' truth value
 * directly. Instead, for each leaf it substitutes the SQL constant that can
 * only ever *widen* the overall result, given the leaf's polarity (whether it
 * sits under an odd or even number of enclosing NOTs) -- TRUE for positive
 * polarity, FALSE for negative (a NOT wrapping an uncertain leaf must not
 * become a false NOT(TRUE)=FALSE, which would wrongly exclude every row that
 * could satisfy the real NOT). filename/filepath with op=regex are uncertain
 * only on dialects without a native regex operator (SQLite/Oracle), where
 * SQL::patternMatchColumn() would otherwise silently degrade to a literal
 * LIKE match that under-matches (not a safe superset) -- see
 * SQL::regexSupported().
 *
 * This keeps sibling exact leaves in the same AND/OR fully SQL-filterable
 * instead of collapsing the whole surrounding group to a no-op, while
 * guaranteeing the SQL condition is always a safe superset of the true
 * result. evaluate() is the authoritative check -- it is run unconditionally
 * against every SQL-narrowed candidate row (not just when an uncertain leaf
 * is present) before a match is treated as final -- see callers in
 * TimelineQueryDays.
 */
final class FilterExpression
{
    private const MAX_DEPTH = 6;
    private const MAX_LEAVES = 50;

    private const SET_FIELDS = ['tag', 'embeddedTag', 'recognize', 'facerecognition', 'place', 'extension', 'fileType'];
    private const RANGE_FIELDS = ['rating', 'date'];
    private const BOOL_FIELDS = ['favorite'];
    private const TEXT_FIELDS = ['filename', 'filepath', 'camMake', 'camModel', 'camLens'];
    private const FIELDS = [...self::SET_FIELDS, ...self::RANGE_FIELDS, ...self::BOOL_FIELDS, ...self::TEXT_FIELDS];

    private const RANGE_OPS = ['gte', 'lte'];
    private const TEXT_OPS = ['contains', 'notContains', 'startsWith', 'endsWith', 'equals', 'regex'];
    private const REGEX_ONLY_FIELDS = ['filename', 'filepath'];

    /** Fields that are ALWAYS uncertain in SQL, regardless of dialect -- see class docblock. */
    private const ALWAYS_UNCERTAIN_FIELDS = ['embeddedTag', 'rating', 'camMake', 'camModel', 'camLens'];

    private const OPS = ['and', 'or', 'not'];

    /**
     * Decode and validate the `filterExpr` request param (a URL-encoded JSON
     * tree), if present. Returns null if the param is absent so callers can
     * cheaply check "is a filter expression active" without a try/catch.
     * Throws on malformed JSON or a tree that fails validate() -- this is
     * meant to surface as a clear error response (via Util::guardEx()), not
     * to be silently swallowed, since a malformed filterExpr is either a
     * client bug or a UI feeding this endpoint a stale/incompatible shape.
     *
     * Called independently from both DaysController (to build the SQL
     * transform) and TimelineQueryDays (to decide whether a PHP verify pass
     * is needed and to run it) -- both have their own IRequest, so this is a
     * pure function of the request rather than something computed once and
     * threaded through, matching this trait's existing
     * getEmbeddedTagsFromRequest() pattern.
     */
    public static function fromRequest(IRequest $request): ?array
    {
        $raw = $request->getParam('filterExpr');
        if (!$raw) {
            return null;
        }

        $decoded = json_decode(urldecode((string) $raw), true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid filterExpr: not a valid JSON object');
        }

        self::validate($decoded);

        return $decoded;
    }

    /**
     * Validate the structure of a filter tree (depth, leaf count, known
     * field/op names, non-empty values). Throws on any violation. Does not
     * check whether referenced backends (Recognize/Face Recognition/Places)
     * are enabled -- that's checked lazily when a leaf of that type is
     * actually compiled, same as the rest of this app's ClustersBackend code.
     */
    public static function validate(array $node, int $depth = 0, int &$leafCount = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Filter expression exceeds maximum nesting depth');
        }

        $kind = $node['kind'] ?? null;

        if ('leaf' === $kind) {
            if (++$leafCount > self::MAX_LEAVES) {
                throw new \InvalidArgumentException('Filter expression exceeds maximum number of leaves');
            }

            $field = $node['field'] ?? null;
            if (!\in_array($field, self::FIELDS, true)) {
                throw new \InvalidArgumentException('Filter expression leaf has an unknown field');
            }

            if (!\is_string($node['value'] ?? null) || '' === $node['value']) {
                throw new \InvalidArgumentException('Filter expression leaf has an empty or invalid value');
            }

            self::validateLeafOp($field, $node['op'] ?? null, $node['value']);

            return;
        }

        if ('group' === $kind) {
            $op = $node['op'] ?? null;
            if (!\in_array($op, self::OPS, true)) {
                throw new \InvalidArgumentException('Filter expression group has an unknown operator');
            }

            $children = $node['children'] ?? null;
            if (!\is_array($children) || empty($children)) {
                throw new \InvalidArgumentException('Filter expression group has no children');
            }

            if ('not' === $op && 1 !== \count($children)) {
                throw new \InvalidArgumentException('Filter expression "not" group must have exactly one child');
            }

            foreach ($children as $child) {
                if (!\is_array($child)) {
                    throw new \InvalidArgumentException('Filter expression child is not an object');
                }

                self::validate($child, $depth + 1, $leafCount);
            }

            return;
        }

        throw new \InvalidArgumentException('Filter expression node has an unknown kind');
    }

    /**
     * Collect the distinct set of leaf field types referenced anywhere in the
     * tree. Used to fetch only the context data actually needed for
     * evaluate() (e.g. don't query Places tables if no "place" leaf exists).
     *
     * @return string[]
     */
    public static function fieldsUsed(array $node): array
    {
        $fields = [];
        self::collectFields($node, $fields);

        return array_keys($fields);
    }

    /**
     * Compile a safe-superset SQL boolean expression for this tree, suitable
     * for use in an andWhere() clause on $query (which must have the
     * `memories` table aliased as `m`) -- see class docblock for why this is
     * a *superset* (may over-match) rather than exact, and why every match
     * still needs evaluate() run against real per-row data before being
     * treated as final.
     */
    public static function compileSqlSuperset(array $node, IQueryBuilder $query, bool $positive = true): string
    {
        if ('leaf' === $node['kind']) {
            if (self::isUncertainLeaf($node, $query)) {
                // Uncertain in SQL -- substitute the constant that can only
                // ever widen the enclosing formula for this leaf's polarity.
                return $positive ? '1=1' : '1=0';
            }

            return self::compileExactLeaf($node, $query);
        }

        $childPositive = 'not' === $node['op'] ? !$positive : $positive;
        $compiled = array_map(
            static fn (array $child): string => self::compileSqlSuperset($child, $query, $childPositive),
            $node['children'],
        );

        return match ($node['op']) {
            'and' => '('.implode(' AND ', $compiled).')',
            'or' => '('.implode(' OR ', $compiled).')',
            'not' => 'NOT ('.$compiled[0].')',
            default => throw new \LogicException('Unreachable: validate() guarantees a known op'),
        };
    }

    /**
     * Authoritatively evaluate this tree against one candidate row's real
     * data (from buildContexts() plus the caller's own per-photo injections,
     * see TimelineQueryDays). Unlike compileSqlSuperset(), every field is
     * evaluated exactly here -- this is the final, correct answer, run
     * unconditionally for every field (not just the uncertain ones).
     */
    public static function evaluate(array $node, array $context): bool
    {
        if ('leaf' === $node['kind']) {
            $field = $node['field'];
            $value = $node['value'];
            $op = $node['op'] ?? null;

            return match ($field) {
                'tag' => \in_array((int) $value, $context['tag'] ?? [], true),
                'embeddedTag' => \in_array($value, $context['embeddedTag'] ?? [], true),
                'recognize' => \in_array($value, $context['recognize'] ?? [], true),
                'facerecognition' => \in_array($value, $context['facerecognition'] ?? [], true),
                'place' => \in_array((int) $value, $context['place'] ?? [], true),
                'extension' => self::matchExtension((string) ($context['filename'] ?? ''), $value),
                'fileType' => self::matchFileType((string) ($context['fileType'] ?? ''), $value),
                'favorite' => ($context['favorite'] ?? false) === ('true' === $value),
                'rating' => self::compareRange($context['rating'] ?? null, $op, (int) $value),
                'date' => self::compareRange($context['date'] ?? null, $op, (int) Util::dateStringToDayId($value)),
                'filename' => self::matchText((string) ($context['filename'] ?? ''), $op, $value),
                'filepath' => self::matchText((string) ($context['filepath'] ?? ''), $op, $value),
                'camMake' => self::matchText((string) ($context['camMake'] ?? ''), $op, $value),
                'camModel' => self::matchText((string) ($context['camModel'] ?? ''), $op, $value),
                'camLens' => self::matchText((string) ($context['camLens'] ?? ''), $op, $value),
                default => throw new \LogicException('Unreachable: validate() guarantees a known field'),
            };
        }

        return match ($node['op']) {
            'and' => self::allMatch($node['children'], $context),
            'or' => self::anyMatch($node['children'], $context),
            'not' => !self::evaluate($node['children'][0], $context),
            default => throw new \LogicException('Unreachable: validate() guarantees a known op'),
        };
    }

    /**
     * Fetch real per-fileid membership data for every field type referenced
     * in $fields (a subset of fieldsUsed()'s output) that requires an actual
     * database query, scoped to $fileids. Returns a map of fileid => context
     * array, where each context array has keys matching the field names
     * actually requested, each a list of values that photo matches
     * (systemtag ids, "uid/clusterid" strings, or OSM ids) -- exactly the
     * shape evaluate() expects.
     *
     * Every other field (embeddedTag, rating, date, favorite, filename,
     * filepath, extension, fileType, camMake, camModel, camLens) is *not*
     * filled here: its data is already available on each photo row fetched
     * by TimelineQueryDays (basename, path, mimetype, decoded exif, dayid,
     * isfavorite, embedded_tags), so the caller injects those directly into
     * the per-photo context instead of this method querying for them again.
     *
     * @param int[]    $fileids
     * @param string[] $fields
     *
     * @return array<int, array<string, array>>
     */
    public static function buildContexts(array $fileids, array $fields, IDBConnection $connection): array
    {
        $contexts = [];
        foreach ($fileids as $fileid) {
            $contexts[$fileid] = [];
        }

        if (empty($fileids)) {
            return $contexts;
        }

        if (\in_array('tag', $fields, true)) {
            self::fillTagContexts($contexts, $fileids, $connection);
        }

        if (\in_array('recognize', $fields, true)) {
            self::fillRecognizeContexts($contexts, $fileids, $connection);
        }

        if (\in_array('facerecognition', $fields, true)) {
            self::fillFaceRecognitionContexts($contexts, $fileids, $connection);
        }

        if (\in_array('place', $fields, true)) {
            self::fillPlaceContexts($contexts, $fileids, $connection);
        }

        return $contexts;
    }

    private static function collectFields(array $node, array &$fields): void
    {
        if ('leaf' === $node['kind']) {
            $fields[$node['field']] = true;

            return;
        }

        foreach ($node['children'] as $child) {
            self::collectFields($child, $fields);
        }
    }

    private static function allMatch(array $children, array $context): bool
    {
        foreach ($children as $child) {
            if (!self::evaluate($child, $context)) {
                return false;
            }
        }

        return true;
    }

    private static function anyMatch(array $children, array $context): bool
    {
        foreach ($children as $child) {
            if (self::evaluate($child, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-field/op validation invoked from validate()'s leaf branch.
     */
    private static function validateLeafOp(string $field, ?string $op, string $value): void
    {
        if (\in_array($field, self::TEXT_FIELDS, true)) {
            $allowed = \in_array($field, self::REGEX_ONLY_FIELDS, true) ? self::TEXT_OPS : array_diff(self::TEXT_OPS, ['regex']);
            if (!\in_array($op, $allowed, true)) {
                throw new \InvalidArgumentException("Filter expression leaf for field '{$field}' has an invalid op");
            }

            return;
        }

        if (\in_array($field, self::RANGE_FIELDS, true)) {
            if (!\in_array($op, self::RANGE_OPS, true)) {
                throw new \InvalidArgumentException("Filter expression leaf for field '{$field}' has an invalid op");
            }

            if ('rating' === $field && (!is_numeric($value) || (int) $value < 0 || (int) $value > 5)) {
                throw new \InvalidArgumentException('Filter expression rating leaf must be numeric and between 0 and 5');
            }

            if ('date' === $field && null === Util::dateStringToDayId($value)) {
                throw new \InvalidArgumentException('Filter expression date leaf must be a valid YYYY-MM-DD date');
            }

            return;
        }

        if (\in_array($field, self::BOOL_FIELDS, true)) {
            if (null !== $op) {
                throw new \InvalidArgumentException("Filter expression leaf for field '{$field}' must not have an op");
            }

            if (!\in_array($value, ['true', 'false'], true)) {
                throw new \InvalidArgumentException("Filter expression leaf for field '{$field}' must be \"true\" or \"false\"");
            }

            return;
        }

        // Set-membership fields (tag/embeddedTag/recognize/facerecognition/place/extension/fileType)
        if (null !== $op) {
            throw new \InvalidArgumentException("Filter expression leaf for field '{$field}' must not have an op");
        }
    }

    /**
     * Whether this leaf must be widened rather than compiled exactly -- see
     * class docblock for the exact/uncertain distinction.
     */
    private static function isUncertainLeaf(array $node, IQueryBuilder $query): bool
    {
        if (\in_array($node['field'], self::ALWAYS_UNCERTAIN_FIELDS, true)) {
            return true;
        }

        if (\in_array($node['field'], self::REGEX_ONLY_FIELDS, true) && 'regex' === ($node['op'] ?? null)) {
            return !SQL::regexSupported($query);
        }

        return false;
    }

    private static function compileExactLeaf(array $node, IQueryBuilder $query): string
    {
        return match ($node['field']) {
            'tag' => self::compileTagLeaf($query, $node['value']),
            'recognize' => self::compilePersonLeaf($query, $node['value'], 'recognize'),
            'facerecognition' => self::compilePersonLeaf($query, $node['value'], 'facerecognition'),
            'place' => self::compilePlaceLeaf($query, $node['value']),
            'extension' => self::compileExtensionLeaf($query, $node['value']),
            'fileType' => self::compileFileTypeLeaf($query, $node['value']),
            'favorite' => self::compileFavoriteLeaf($query, $node['value']),
            'date' => self::compileDateLeaf($query, $node['op'], $node['value']),
            'filename' => self::compileTextLeaf($query, 'flnf', 'name', $node['op'], $node['value']),
            'filepath' => self::compileTextLeaf($query, 'flpf', 'path', $node['op'], $node['value']),
            default => throw new \LogicException('Unreachable: compileSqlSuperset() only calls this for exact (non-uncertain) fields'),
        };
    }

    private static function compileTagLeaf(IQueryBuilder $query, string $value): string
    {
        if (!Util::tagsIsEnabled()) {
            throw Exceptions::NotEnabled('Tags');
        }

        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('systemtag_object_mapping', 'fx_stom')
            ->where($sub->expr()->eq('fx_stom.objecttype', $sub->expr()->literal('files')))
            ->andWhere($sub->expr()->eq('fx_stom.objectid', 'm.objectid'))
            ->andWhere($sub->expr()->eq('fx_stom.systemtagid', $query->createNamedParameter(self::toInt($value), IQueryBuilder::PARAM_INT)))
        ;

        return "EXISTS ({$sub->getSQL()})";
    }

    private static function compilePersonLeaf(IQueryBuilder $query, string $value, string $backend): string
    {
        [$uid, $identifier] = self::splitPersonValue($value);
        $isNumeric = is_numeric($identifier);

        $sub = $query->getConnection()->getQueryBuilder();

        if ('recognize' === $backend) {
            if (!Util::recognizeIsEnabled()) {
                throw Exceptions::NotEnabled('Recognize');
            }

            if ($isNumeric && -1 === (int) $identifier) {
                // Recognize's "unclustered faces" bucket: cluster_id -1 has no
                // real recognize_face_clusters row (see RecognizeBackend::
                // transformDayQuery()'s identical special case), so this must
                // match directly on recognize_face_detections's own columns
                // rather than joining a cluster row that doesn't exist.
                $sub->select($sub->expr()->literal(1))
                    ->from('recognize_face_detections', 'fx_rfd')
                    ->where($sub->expr()->eq('fx_rfd.file_id', 'm.fileid'))
                    ->andWhere($sub->expr()->eq('fx_rfd.user_id', $query->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
                    ->andWhere($sub->expr()->eq('fx_rfd.cluster_id', $query->createNamedParameter(-1, IQueryBuilder::PARAM_INT)))
                ;
            } else {
                $idField = $isNumeric ? 'fx_rfc.id' : 'fx_rfc.title';
                $idParam = $isNumeric
                    ? $query->createNamedParameter((int) $identifier, IQueryBuilder::PARAM_INT)
                    : $query->createNamedParameter($identifier, IQueryBuilder::PARAM_STR);

                $sub->select($sub->expr()->literal(1))
                    ->from('recognize_face_detections', 'fx_rfd')
                    ->innerJoin('fx_rfd', 'recognize_face_clusters', 'fx_rfc', $sub->expr()->eq('fx_rfd.cluster_id', 'fx_rfc.id'))
                    ->where($sub->expr()->eq('fx_rfd.file_id', 'm.fileid'))
                    ->andWhere($sub->expr()->eq('fx_rfc.user_id', $query->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
                    ->andWhere($sub->expr()->eq($idField, $idParam))
                ;
            }
        } else {
            if (!Util::facerecognitionIsInstalled() || !Util::facerecognitionIsEnabled()) {
                throw Exceptions::NotEnabled('Face Recognition');
            }

            $idField = $isNumeric ? 'fx_frp.id' : 'fx_frp.name';
            $idParam = $isNumeric
                ? $query->createNamedParameter((int) $identifier, IQueryBuilder::PARAM_INT)
                : $query->createNamedParameter($identifier, IQueryBuilder::PARAM_STR);

            $sub->select($sub->expr()->literal(1))
                ->from('facerecog_faces', 'fx_frf')
                ->innerJoin('fx_frf', 'facerecog_images', 'fx_fri', $sub->expr()->eq('fx_fri.id', 'fx_frf.image'))
                ->innerJoin('fx_frf', 'facerecog_persons', 'fx_frp', $sub->expr()->eq('fx_frp.id', 'fx_frf.person'))
                ->where($sub->expr()->eq('fx_fri.file', 'm.fileid'))
                ->andWhere($sub->expr()->eq('fx_frp.user', $query->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
                ->andWhere($sub->expr()->eq($idField, $idParam))
                ->andWhere($sub->expr()->eq('fx_fri.model', $query->createNamedParameter(self::faceRecognitionModel(), IQueryBuilder::PARAM_INT)))
            ;
        }

        return "EXISTS ({$sub->getSQL()})";
    }

    private static function compilePlaceLeaf(IQueryBuilder $query, string $value): string
    {
        if (SystemConfig::gisType() <= 0) {
            throw Exceptions::NotEnabled('Places');
        }

        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('memories_places', 'fx_mp')
            ->where($sub->expr()->eq('fx_mp.fileid', 'm.fileid'))
            ->andWhere($sub->expr()->eq('fx_mp.osm_id', $query->createNamedParameter(self::toInt($value), IQueryBuilder::PARAM_INT)))
        ;

        return "EXISTS ({$sub->getSQL()})";
    }

    private static function compileExtensionLeaf(IQueryBuilder $query, string $ext): string
    {
        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('filecache', 'fx_extf')
            ->where($sub->expr()->eq('fx_extf.fileid', 'm.fileid'))
            ->andWhere(SQL::textOpColumn($query, 'fx_extf.name', 'endsWith', '.'.$ext))
        ;

        return "EXISTS ({$sub->getSQL()})";
    }

    private static function compileFileTypeLeaf(IQueryBuilder $query, string $pattern): string
    {
        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('filecache', 'fx_ftf')
            ->innerJoin('fx_ftf', 'mimetypes', 'fx_ftmt', $sub->expr()->eq('fx_ftf.mimetype', 'fx_ftmt.id'))
            ->where($sub->expr()->eq('fx_ftf.fileid', 'm.fileid'))
            ->andWhere($sub->expr()->like('fx_ftmt.mimetype', $query->createNamedParameter($pattern, IQueryBuilder::PARAM_STR)))
        ;

        return "EXISTS ({$sub->getSQL()})";
    }

    private static function compileFavoriteLeaf(IQueryBuilder $query, string $value): string
    {
        $want = 'true' === $value;

        if (!Util::isLoggedIn()) {
            // No favorites system for anonymous/public-share access -- nothing can be a favorite.
            return $want ? '1=0' : '1=1';
        }

        $catSub = $query->getConnection()->getQueryBuilder();
        $catSub->select('id')
            ->from('vcategory', 'fx_fvc')
            ->where($catSub->expr()->andX(
                $catSub->expr()->eq('type', $catSub->expr()->literal('files')),
                $catSub->expr()->eq('uid', $query->createNamedParameter(Util::getUID())),
                $catSub->expr()->eq('category', $catSub->expr()->literal(ITags::TAG_FAVORITE)),
            ))
        ;

        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('vcategory_to_object', 'fx_fvco')
            ->where($sub->expr()->eq('fx_fvco.objid', 'm.fileid'))
            ->andWhere("fx_fvco.categoryid IN ({$catSub->getSQL()})")
        ;

        $exists = "EXISTS ({$sub->getSQL()})";

        return $want ? $exists : "NOT ({$exists})";
    }

    private static function compileDateLeaf(IQueryBuilder $query, string $op, string $value): string
    {
        $dayId = Util::dateStringToDayId($value);
        if (null === $dayId) {
            throw new \InvalidArgumentException('Invalid date filter value, expected YYYY-MM-DD');
        }

        $param = $query->createNamedParameter($dayId, IQueryBuilder::PARAM_INT);

        return match ($op) {
            'gte' => "m.dayid >= {$param}",
            'lte' => "m.dayid <= {$param}",
            default => throw new \LogicException('Unreachable: validate() guarantees gte|lte for date'),
        };
    }

    /**
     * Shared compiler for filename/filepath leaves against `filecache`
     * (aliased as $alias, correlated on m.fileid) -- op=regex is only ever
     * reached here when SQL::regexSupported() is true (see isUncertainLeaf()),
     * every other op is a plain dialect-independent LIKE via SQL::textOpColumn().
     */
    private static function compileTextLeaf(IQueryBuilder $query, string $alias, string $column, string $op, string $value): string
    {
        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select($sub->expr()->literal(1))
            ->from('filecache', $alias)
            ->where($sub->expr()->eq("{$alias}.fileid", 'm.fileid'))
            ->andWhere('regex' === $op
                ? SQL::patternMatchColumn($query, "{$alias}.{$column}", $value, true)
                : SQL::textOpColumn($query, "{$alias}.{$column}", $op, $value))
        ;

        return "EXISTS ({$sub->getSQL()})";
    }

    /**
     * @return array{0: string, 1: string} [uid, id-or-name] -- the second
     *                                     element may or may not be numeric,
     *                                     see class docblock
     */
    private static function splitPersonValue(string $value): array
    {
        $parts = explode('/', $value, 2);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \InvalidArgumentException('Invalid person filter value, expected "<uid>/<id-or-name>"');
        }

        return [$parts[0], $parts[1]];
    }

    private static function toInt(string $value): int
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Expected a numeric filter value');
        }

        return (int) $value;
    }

    private static function faceRecognitionModel(): int
    {
        return (int) \OC::$server->get(IAppConfig::class)->getValueString('facerecognition', 'model', (string) -1);
    }

    private static function compareRange(?int $actual, ?string $op, int $bound): bool
    {
        if (null === $actual) {
            return false;
        }

        return match ($op) {
            'gte' => $actual >= $bound,
            'lte' => $actual <= $bound,
            default => throw new \LogicException('Unreachable: validate() guarantees gte|lte'),
        };
    }

    private static function matchText(string $haystack, ?string $op, string $needle): bool
    {
        // validate() already guarantees a leaf value is never '', but its
        // type is plain `string`, not `non-empty-string` -- preg_match()
        // requires the latter for its pattern argument.
        /** @psalm-suppress ArgumentTypeCoercion */
        return match ($op) {
            'contains' => false !== stripos($haystack, $needle),
            'notContains' => false === stripos($haystack, $needle),
            'startsWith' => 0 === stripos($haystack, $needle),
            'endsWith' => self::strEndsWithCI($haystack, $needle),
            'equals' => 0 === strcasecmp($haystack, $needle),
            'regex' => 1 === @preg_match($needle, $haystack),
            default => throw new \LogicException('Unreachable: validate() guarantees a known text op'),
        };
    }

    private static function strEndsWithCI(string $haystack, string $needle): bool
    {
        if ('' === $needle) {
            return true;
        }

        $len = \strlen($needle);

        return $len <= \strlen($haystack) && 0 === strcasecmp(substr($haystack, -$len), $needle);
    }

    private static function matchExtension(string $filename, string $ext): bool
    {
        return '' !== $ext && 0 === strcasecmp(pathinfo($filename, PATHINFO_EXTENSION), $ext);
    }

    private static function matchFileType(string $mimetype, string $pattern): bool
    {
        // Mirror compileFileTypeLeaf()'s SQL LIKE semantics: '%' is a wildcard, everything else is literal.
        $regex = '/^'.str_replace('%', '.*', preg_quote($pattern, '/')).'$/i';

        return 1 === preg_match($regex, $mimetype);
    }

    /**
     * @param array<int, array<string, array>> $contexts byref
     * @param int[]                             $fileids
     */
    private static function fillTagContexts(array &$contexts, array $fileids, IDBConnection $connection): void
    {
        $query = $connection->getQueryBuilder();
        $query->select('fx_stom.objectid', 'fx_stom.systemtagid')
            ->from('systemtag_object_mapping', 'fx_stom')
            ->where($query->expr()->eq('fx_stom.objecttype', $query->expr()->literal('files')))
            ->andWhere($query->expr()->in('fx_stom.objectid', $query->createNamedParameter(
                array_map('strval', $fileids),
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
        ;

        foreach ($query->executeQuery()->fetchAll() as $row) {
            $fileid = (int) $row['objectid'];
            $contexts[$fileid]['tag'][] = (int) $row['systemtagid'];
        }
    }

    /**
     * @param array<int, array<string, array>> $contexts byref
     * @param int[]                             $fileids
     */
    private static function fillRecognizeContexts(array &$contexts, array $fileids, IDBConnection $connection): void
    {
        $query = $connection->getQueryBuilder();
        $query->select('fx_rfd.file_id', 'fx_rfd.user_id', 'fx_rfd.cluster_id', 'fx_rfc.title')
            ->from('recognize_face_detections', 'fx_rfd')
            // Left join: cluster_id -1 (unclustered) has no real cluster row,
            // but the detection itself is still a valid context entry.
            ->leftJoin('fx_rfd', 'recognize_face_clusters', 'fx_rfc', $query->expr()->eq('fx_rfd.cluster_id', 'fx_rfc.id'))
            ->where($query->expr()->in('fx_rfd.file_id', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
        ;

        foreach ($query->executeQuery()->fetchAll() as $row) {
            $fileid = (int) $row['file_id'];
            // Both representations, since a leaf's value may carry either.
            $contexts[$fileid]['recognize'][] = "{$row['user_id']}/{$row['cluster_id']}";
            if (!empty($row['title'])) {
                $contexts[$fileid]['recognize'][] = "{$row['user_id']}/{$row['title']}";
            }
        }
    }

    /**
     * @param array<int, array<string, array>> $contexts byref
     * @param int[]                             $fileids
     */
    private static function fillFaceRecognitionContexts(array &$contexts, array $fileids, IDBConnection $connection): void
    {
        $model = self::faceRecognitionModel();

        $query = $connection->getQueryBuilder();
        $query->select('fx_fri.file', 'fx_frp.user', 'fx_frp.id', 'fx_frp.name')
            ->from('facerecog_faces', 'fx_frf')
            ->innerJoin('fx_frf', 'facerecog_images', 'fx_fri', $query->expr()->andX(
                $query->expr()->eq('fx_fri.id', 'fx_frf.image'),
                $query->expr()->eq('fx_fri.model', $query->createNamedParameter($model, IQueryBuilder::PARAM_INT)),
            ))
            ->innerJoin('fx_frf', 'facerecog_persons', 'fx_frp', $query->expr()->eq('fx_frp.id', 'fx_frf.person'))
            ->where($query->expr()->in('fx_fri.file', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
        ;

        foreach ($query->executeQuery()->fetchAll() as $row) {
            $fileid = (int) $row['file'];
            // Both representations, since a leaf's value may carry either
            // (see class docblock: named persons' own API only exposes the
            // name as their effective "id").
            $contexts[$fileid]['facerecognition'][] = "{$row['user']}/{$row['id']}";
            if (!empty($row['name'])) {
                $contexts[$fileid]['facerecognition'][] = "{$row['user']}/{$row['name']}";
            }
        }
    }

    /**
     * @param array<int, array<string, array>> $contexts byref
     * @param int[]                             $fileids
     */
    private static function fillPlaceContexts(array &$contexts, array $fileids, IDBConnection $connection): void
    {
        $query = $connection->getQueryBuilder();
        $query->select('fx_mp.fileid', 'fx_mp.osm_id')
            ->from('memories_places', 'fx_mp')
            ->where($query->expr()->in('fx_mp.fileid', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
        ;

        foreach ($query->executeQuery()->fetchAll() as $row) {
            $fileid = (int) $row['fileid'];
            $contexts[$fileid]['place'][] = (int) $row['osm_id'];
        }
    }
}
