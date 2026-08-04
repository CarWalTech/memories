<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryFolders
{
    protected IDBConnection $connection;

    /**
     * Get the previews inside a given TimelineRoot.
     * The root folder passed to this function must already be populated
     * with the mount points recursively, if this is desired.
     *
     * @param TimelineRoot $root      The root to use for the query
     * @param bool         $recursive Whether to look inside subfolders. Passing false
     *                                skips the recursive folder CTE entirely and reads
     *                                only the folder's direct children through
     *                                memories_pdf_idx -- see getRootPreviewsFast().
     */
    public function getRootPreviews(TimelineRoot $root, bool $recursive = true): array
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all photos
        $query->select('m.fileid', 'f.etag')->from('memories', 'm');

        // JOIN with the filecache table
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // WHERE these photos are in the user's requested folder
        $query = $this->filterFilecache($query, $root, $recursive, false);

        // MAX 4
        $query->setMaxResults(4);

        // FETCH tag previews
        $rows = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post-process
        foreach ($rows as &$row) {
            $row['fileid'] = (int) $row['fileid'];
        }

        return $rows;
    }

    /**
     * Get up to 4 previews for a folder, avoiding the recursive folder CTE
     * when possible.
     *
     * Folder listings ask for previews once per subfolder, and each recursive
     * call re-runs the whole CTE_FOLDERS_ALL walk (with its per-folder
     * .nomedia/.nomemories NOT EXISTS pair). For the overwhelmingly common
     * case -- a folder that directly contains photos -- the four previews can
     * come from a single indexed lookup on the folder's own children instead.
     *
     * Falls back to the full recursive query whenever the cheap pass can't
     * produce four previews, so folders whose photos live only in
     * sub-subfolders (e.g. a year folder of month folders) still work.
     *
     * Previews are cosmetic and neither path has an ORDER BY, so which four
     * photos come back was already arbitrary; only the cost differs.
     *
     * @param TimelineRoot $root The root, already rebased onto the folder
     */
    public function getRootPreviewsFast(TimelineRoot $root): array
    {
        // A .nomedia/.nomemories marker means "no previews from here". The
        // recursive CTE applies this in its base case; the non-recursive path
        // below has no CTE, so mirror it here as one indexed subquery rather
        // than touching the filesystem.
        $query = $this->connection->getQueryBuilder();
        $marker = $query->select($query->expr()->literal(1))
            ->from('filecache', 'f2')
            ->where($query->expr()->eq('f2.parent', $query->createNamedParameter($root->getOneId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->in('f2.name', $query->createNamedParameter(['.nomedia', '.nomemories'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        if (false !== $marker && null !== $marker) {
            return [];
        }

        $previews = $this->getRootPreviews($root, false);
        if (\count($previews) >= 4) {
            return $previews;
        }

        return $this->getRootPreviews($root, true);
    }

    /**
     * Add etag for a field in a query.
     *
     * @param IQueryBuilder $query The query to add the etag to
     * @param mixed         $field The field to add the etag for
     * @param string        $alias The alias to use for the etag
     */
    public static function selectEtag(IQueryBuilder &$query, mixed $field, string $alias): void
    {
        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select('etag')
            ->from('filecache', 'etag_f')
            ->where($sub->expr()->eq('etag_f.fileid', $field))
            ->setMaxResults(1)
        ;
        $query->selectAlias(SQL::subquery($query, $sub), $alias);
    }
}
