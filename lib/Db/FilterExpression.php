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

/**
 * Compiles and evaluates a user-supplied boolean filter expression tree over
 * collaborative tags, embedded (EXIF/XMP) tags, Recognize/Face Recognition
 * people, and Places -- the AND/OR/NOT operator model for the unified filter
 * panel. A tree node is either:.
 *
 *   ['kind' => 'leaf', 'field' => 'tag'|'embeddedTag'|'recognize'|'facerecognition'|'place', 'value' => string]
 *   ['kind' => 'group', 'op' => 'and'|'or'|'not', 'children' => [node, ...]]  ('not' takes exactly 1 child)
 *
 * Leaf value formats:
 *   - tag:             systemtag numeric ID (stable across renames), as a string
 *   - embeddedTag:      flattened tag path, e.g. "Family/Vacation/Beach" (see Exif::extractEmbeddedTags())
 *   - recognize:        "<uid>/<numeric cluster id>" (cluster id -1 is Recognize's "unclustered" bucket)
 *   - facerecognition:  "<uid>/<numeric person id>"
 *   - place:            OSM id, as a string
 *
 * Deliberately numeric-id-only for tag/recognize/facerecognition/place (never
 * a name/title): ids are stable and unambiguous, so these four are exact,
 * deterministic relational facts with no derived-text ambiguity, and are
 * therefore safe to compile to SQL and negate directly.
 *
 * embeddedTag is different: Exif::extractEmbeddedTags() does real text
 * processing (splitting comma-joined values, inferring hierarchical paths
 * from '/' or '|' separators) before a stored EXIF value becomes a tag, so a
 * requested tag string can legitimately not appear verbatim in storage (see
 * TimelineQueryFilters::transformEmbeddedTagsFilter() for a fuller account,
 * and the live-data regression this class's design is validated against).
 * embeddedTag is therefore treated as *uncertain* in SQL: compileSqlSuperset()
 * never asserts an embeddedTag leaf's truth value directly. Instead, for each
 * leaf it substitutes the SQL constant that can only ever *widen* the overall
 * result, given the leaf's polarity (whether it sits under an odd or even
 * number of enclosing NOTs) -- TRUE for positive polarity, FALSE for negative
 * (a NOT wrapping an uncertain leaf must not become a false NOT(TRUE)=FALSE,
 * which would wrongly exclude every row that could satisfy the real NOT).
 * This keeps sibling exact leaves in the same AND/OR fully SQL-filterable
 * instead of collapsing the whole surrounding group to a no-op, while
 * guaranteeing the SQL condition is always a safe superset of the true
 * result. evaluate() is the authoritative check every SQL-narrowed candidate
 * row must still pass before a match is treated as final -- see callers in
 * TimelineQueryDays.
 */
final class FilterExpression
{
    private const MAX_DEPTH = 6;
    private const MAX_LEAVES = 50;
    private const FIELDS = ['tag', 'embeddedTag', 'recognize', 'facerecognition', 'place'];
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

            if (!\in_array($node['field'] ?? null, self::FIELDS, true)) {
                throw new \InvalidArgumentException('Filter expression leaf has an unknown field');
            }

            if (!\is_string($node['value'] ?? null) || '' === $node['value']) {
                throw new \InvalidArgumentException('Filter expression leaf has an empty or invalid value');
            }

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
            if ('embeddedTag' === $node['field']) {
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
     * data (from buildContexts()). Unlike compileSqlSuperset(), every field
     * is evaluated exactly here -- this is the final, correct answer.
     */
    public static function evaluate(array $node, array $context): bool
    {
        if ('leaf' === $node['kind']) {
            $field = $node['field'];
            $value = $node['value'];

            return match ($field) {
                'tag' => \in_array((int) $value, $context['tag'] ?? [], true),
                'embeddedTag' => \in_array($value, $context['embeddedTag'] ?? [], true),
                'recognize' => \in_array($value, $context['recognize'] ?? [], true),
                'facerecognition' => \in_array($value, $context['facerecognition'] ?? [], true),
                'place' => \in_array((int) $value, $context['place'] ?? [], true),
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
     * in $fields (a subset of fieldsUsed()'s output), scoped to $fileids.
     * Returns a map of fileid => context array, where each context array has
     * keys matching the field names actually requested, each a list of
     * values that photo matches (systemtag ids, flattened embedded tag
     * paths, "uid/clusterid" strings, or OSM ids) -- exactly the shape
     * evaluate() expects. embeddedTag is populated by the caller (it's
     * already available from postProcessDayPhoto()'s embedded_tags field,
     * with no extra query needed), not by this method.
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

    private static function compileExactLeaf(array $node, IQueryBuilder $query): string
    {
        return match ($node['field']) {
            'tag' => self::compileTagLeaf($query, $node['value']),
            'recognize' => self::compilePersonLeaf($query, $node['value'], 'recognize'),
            'facerecognition' => self::compilePersonLeaf($query, $node['value'], 'facerecognition'),
            'place' => self::compilePlaceLeaf($query, $node['value']),
            default => throw new \LogicException('Unreachable: compileSqlSuperset() only calls this for exact (non-embeddedTag) fields'),
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
        [$uid, $clusterId] = self::splitPersonValue($value);

        $sub = $query->getConnection()->getQueryBuilder();

        if ('recognize' === $backend) {
            if (!Util::recognizeIsEnabled()) {
                throw Exceptions::NotEnabled('Recognize');
            }

            $sub->select($sub->expr()->literal(1))
                ->from('recognize_face_detections', 'fx_rfd')
                ->where($sub->expr()->eq('fx_rfd.file_id', 'm.fileid'))
                ->andWhere($sub->expr()->eq('fx_rfd.user_id', $query->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
                ->andWhere($sub->expr()->eq('fx_rfd.cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ;
        } else {
            if (!Util::facerecognitionIsInstalled() || !Util::facerecognitionIsEnabled()) {
                throw Exceptions::NotEnabled('Face Recognition');
            }

            $sub->select($sub->expr()->literal(1))
                ->from('facerecog_faces', 'fx_frf')
                ->innerJoin('fx_frf', 'facerecog_images', 'fx_fri', $sub->expr()->eq('fx_fri.id', 'fx_frf.image'))
                ->where($sub->expr()->eq('fx_fri.file', 'm.fileid'))
                ->andWhere($sub->expr()->eq('fx_frf.person', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
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

    /**
     * @return array{0: string, 1: int} [uid, numeric cluster/person id]
     */
    private static function splitPersonValue(string $value): array
    {
        $parts = explode('/', $value, 2);
        if (2 !== \count($parts) || !is_numeric($parts[1])) {
            throw new \InvalidArgumentException('Invalid person filter value, expected "<uid>/<numeric id>"');
        }

        return [$parts[0], (int) $parts[1]];
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
        $query->select('fx_rfd.file_id', 'fx_rfd.user_id', 'fx_rfd.cluster_id')
            ->from('recognize_face_detections', 'fx_rfd')
            ->where($query->expr()->in('fx_rfd.file_id', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
        ;

        foreach ($query->executeQuery()->fetchAll() as $row) {
            $fileid = (int) $row['file_id'];
            $contexts[$fileid]['recognize'][] = "{$row['user_id']}/{$row['cluster_id']}";
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
        $query->select('fx_fri.file', 'fx_frp.user', 'fx_frf.person')
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
            $contexts[$fileid]['facerecognition'][] = "{$row['user']}/{$row['person']}";
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
