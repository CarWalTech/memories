# Unified Filter & Search Engine for Memories

## Context

> **Revised after the "Refactored Component File Locations" (`50cbc3f6`) + embedded-tag/rating commits.** A re-survey confirms the core feature set is unchanged from the initial draft — only file locations, line numbers, and some embedded-tag infra moved. Path/line references below are updated to current `HEAD`. Nothing in the target feature (date, filename, operators, saved filters) has been built yet; two useful new precedents appeared (a reusable pattern/regex filter trait, and relocated selectors) and are folded in.

Memories currently has two disconnected, partial pieces of filter/search UI:

1. **`Searchbar.vue`** (`src/components/header/Searchbar.vue`) — client-side Fuse.js fuzzy match over cluster names (people, places, tags, albums) already loaded into memory. It only navigates to a cluster; it never touches filenames and never filters the timeline.
2. **`FilterComponent.vue` / `FilterDropdownAction.vue`** — relocated to `src/components/modal/FilterComponent.vue` and `src/components/top-actions/FilterDropdownAction.vue` (the latter was renamed from `FilterDropdownButton.vue`). A dropdown already wired into `IndexTopMatter`, `CollabTagsTopMatter`, `FolderTopMatter`, `AlbumTopMatter`, `EmbeddedTagTopMatter` (all under `src/components/top-matter/`) supporting a minimum rating and an embedded-tags multiselect. **The collaborative-tags section is still dead code**: `showCollaborativeTagsFilter` defaults to `false`, no consumer passes `true`, and even if enabled the backend (`TagsBackend::transformDayQuery`, `lib/ClustersBackend/TagsBackend.php:60`) treats the whole `tags` request param as **one** literal tag name — a comma-joined multi-tag value never matches. Embedded tags, by contrast, already have a working multi-value AND filter (`transformEmbeddedTagsFilter`, `lib/Db/TimelineQueryFilters.php:29`). Supporting selectors also moved: `EmbeddedTagSelector.vue` and `RatingStars.vue` are now under `src/components/common/`.

All server-side filtering funnels through one mechanism: `DaysController::getTransformations()` (`lib/Controller/DaysController.php:92`) builds an array of `[$this->tq, 'transformXFilter', ...args]` tuples, which `TimelineQueryFilters::applyAllTransforms()` (`lib/Db/TimelineQueryFilters.php:91`) applies in sequence to one Doctrine `IQueryBuilder`, so every existing filter composes as a top-level AND. Date grouping (day/week/month/year) already exists as *display bucketing* (`groupMode`, `DaysController::getGroupMode`, `TimelineQueryDays.php:134-139` for bucket-id→dayid-range conversion) but there is no actual date **range filter**, and there is no filename/filepath search on media anywhere in the codebase.

**New since the draft — a reusable precedent:** `lib/Db/EmbeddedTagsQueryFilters.php` now has `transformPatternFilter()` (`:38`) — a cross-DB LIKE/REGEXP text filter with `isRegexPattern()` (`:106`) and `escapeLikePattern()` (`:115`) helpers and per-platform branches (MySQL/MariaDB `REGEXP`, Postgres `~`, SQLite/other `LIKE` fallback). It targets the embedded-tags dictionary tables (`et.tag`/`et.path`), not media, but is the exact shape the new filename/filepath/regex media filter should copy.

The goal: turn this into one complete, coherent filter system — filename/filepath, date range (with presets), and a real boolean (AND/OR/NOT) operator model for collaborative tags, embedded tags, people (Recognize + Face Recognition), and places, plus rating range, favorites, file type, camera EXIF, and saved filter presets — accessible from the existing `FilterDropdownAction` on every relevant view.

### ⚠️ Database reality (verified) — this instance runs PostgreSQL

`config.php` has `'dbtype' => 'pgsql'`, and the `memories.exif` column is plain `text` (migration `Version400604...:53`), not `jsonb`. Consequences that reshape the backend design:

- `shouldFilterExifBySQL()` (`lib/Db/TimelineQuery.php:58-85`) returns **false** on pgsql. So today **all** EXIF-based filtering (rating, embedded tags) runs in a **PHP post-processing path**, not SQL: `postProcessDaysWithFilters()` (`TimelineQueryDays.php:332`) for the days-list query, and an in-memory `array_filter` in `getDay()` (`:191-219`) for the per-day query. The only JSON SQL in the repo is MySQL-only (`JSON_EXTRACT`/`JSON_CONTAINS`, `TimelineQueryFilters.php:25,48`) and never executes on this box.
- **Decision (confirmed with user): implement native Postgres JSON SQL** so EXIF leaves compile to SQL on this instance, unifying the whole boolean compiler in SQL. This is a **prerequisite** — see new Section 0.
- Relational leaves (`tag`/`person`/`place`) are **not** EXIF and already work in SQL on Postgres today (plain joins) — only the EXIF leaves (`embeddedTag`/`rating`/`camera`) need the new pgsql JSON work.
- Two Postgres gotchas to bake in from the start: (a) the jsonb **`?` containment operator collides with PDO/Doctrine `?` placeholders** — must use the function form `jsonb_exists(col, :key)` / `jsonb_path_exists(...)` instead of `?`; (b) **`text::jsonb` throws on empty or malformed input** — guard every cast with `m.exif IS NOT NULL AND m.exif <> ''` (exif is written via `json_encode`, so non-empty values are valid JSON, but null/empty rows exist).

## Design decisions (confirmed with user)

- **Tag/people/place operators**: full nested boolean expression builder (not just a simple ALL/ANY + exclude list) — mixed AND/OR/NOT groups, and groups can combine different field types (e.g. `tag:Sunset OR person:Alice`).
- **Collaborative tags**: rebuild properly — real multi-tag backend support, not the current broken single-tag param.
- **Date filter**: range + granularity (day/week/month/year) *and* relative presets (Last 7/30 days, This month, This year).
- **Extra scope**: file type/camera EXIF filters, People/Places get the same operator model as tags, saved filters ("smart albums"), rating **range** (min+max) and a favorites toggle folded into the same panel.

## Architecture

### 0. Prerequisite — Postgres JSON EXIF filtering + a dialect helper

Before the boolean compiler can include EXIF leaves on this instance, EXIF filtering must work in SQL on Postgres. Do this first as a self-contained, independently-testable step:

1. **Add a small SQL-dialect helper** (e.g. static methods on a new `lib/Db/SQLExif.php`, or extend the existing `lib/Db/SQL.php` helper) that emits the correct JSON expression per `IDBConnection::getDatabaseProvider()`:
   - `jsonScalar($field)` → MySQL `JSON_EXTRACT(m.exif,'$.Field')`; Postgres `(m.exif::jsonb->>'Field')` (with the non-empty guard). Numeric compares cast: pg `(...)::numeric`.
   - `jsonArrayContains($field, $param)` → MySQL `JSON_CONTAINS(JSON_EXTRACT(m.exif,'$.Field'), JSON_QUOTE(:p))`; Postgres `jsonb_exists(m.exif::jsonb->'Field', :p)` (function form — **not** the `?` operator). Guard both with `m.exif IS NOT NULL AND m.exif <> ''`.
   This is the single choke point where every EXIF filter (rating, embedded tags, camera, and the compiler's EXIF leaves) gets its SQL, so per-DB differences live in exactly one place.
2. **Port the two existing EXIF filters** to use the helper so they run in SQL on Postgres too: `transformMinRatingFilter` (`TimelineQueryFilters.php:14`) and `transformEmbeddedTagsFilter` (`:29`). They currently early-return unless `shouldFilterExifBySQL()` — keep MySQL working, add the pg branch via the helper.
3. **Flip `shouldFilterExifBySQL()` to return `true` for `pgsql`** (`TimelineQuery.php:67-70`) — but **only after** steps 1–2, since flipping it routes rating/embedded-tag filtering away from the PHP path into the (now Postgres-capable) SQL transforms. SQLite/Oracle stay `false` (PHP path retained for them).
4. **PHP post-processing path becomes pgsql-unused** once flipped: `getDays()` (`:61`) will call `postProcessDays` instead of `postProcessDaysWithFilters`, and `getDay()`'s in-memory EXIF `array_filter` (`:191-194`) becomes redundant on pg. Leave `postProcessDaysWithFilters` in place for SQLite; the new `filterExpr` param ultimately **supersedes** the flat `minRating`/`embeddedTags`/`tags` params and their inconsistent PHP semantics (note: `getDay():193` uses OR/`>0` while `postProcessDaysWithFilters():391` uses AND/`===count` for the same `embeddedTags` — a pre-existing muddle the explicit operator tree replaces cleanly).

Verify this step alone (rating + embedded-tag filter still work on the live pg instance, now via SQL) before building anything on top of it.

### 1. Filter expression data model (new, shared front/back via JSON)

```ts
type FilterField = 'tag' | 'embeddedTag' | 'person' | 'place';
type FilterLeaf = { kind: 'leaf'; field: FilterField; value: string };
type FilterGroup = { kind: 'group'; op: 'and' | 'or' | 'not'; children: FilterNode[] };
type FilterNode = FilterLeaf | FilterGroup; // 'not' groups take exactly 1 child
```

This is deliberately minimal: AND/OR/NOT are the only primitives needed. "ANY of these" is `{op:'or', children:[...]}`; "NONE of these" is `{op:'not', children:[{op:'or', children:[...]}]}` — the UI offers ANY/NONE as one-click presets that build these shapes, so users never have to think in terms of NOT-of-OR.

- `value` for `tag` = systemtag numeric ID (stable across renames); `embeddedTag` = tag path string (matches existing `EmbeddedTagsBackend` convention); `person` = `"<uid>/<name-or-id>"` for Recognize (existing format, see `RecognizeBackend::transformDayQuery`, `lib/ClustersBackend/RecognizeBackend.php:70`) or the Face Recognition equivalent; `place` = `osm_id`.
- New TS type in `src/typings/data.d.ts` alongside `IFilters` (currently only `{minRating, tags, embeddedTags}` at `src/typings/data.d.ts:192-196`; gets replaced/extended, see Section 4).

### 2. Backend compiler — new `lib/Db/FilterExpression.php`

A small recursive compiler: `compile(array $node, IQueryBuilder $query, int &$paramCounter): string`, returning a parenthesized raw SQL boolean fragment (Nextcloud's `IQueryBuilder::andWhere()`/`expr()->orX()` already accept raw SQL strings mixed with expression objects — see `EmbeddedTagsBackend::applySQLPathMatch`, `lib/ClustersBackend/EmbeddedTagsBackend.php:465`, and `transformEmbeddedTagsFilter`, `lib/Db/TimelineQueryFilters.php:29`, for the existing precedent).

Leaf compilation. **Relational leaves** are each an `EXISTS (...)` subquery correlated on `m.fileid` (or `m.objectid` for tags — verified: `memories.objectid` exists, `TagsBackend` already joins on it), one pattern per field mirroring the join in each backend's `transformDayQuery`. **EXIF leaves** compile to a JSON predicate via the Section 0 dialect helper (no subquery — they read `m.exif` on the row):
- `tag` (relational, all DBs) → mirror `TagsBackend::transformDayQuery` (`lib/ClustersBackend/TagsBackend.php:58-69`): `EXISTS(SELECT 1 FROM systemtag_object_mapping stom WHERE stom.objecttype='files' AND stom.objectid=m.objectid AND stom.systemtagid=:id)`.
- `person` (relational, all DBs) → mirror `RecognizeBackend::transformDayQuery` (`lib/ClustersBackend/RecognizeBackend.php:60-116`) and the equivalent in `FaceRecognitionBackend.php` (`recognize_face_detections`/clusters).
- `place` (relational, all DBs) → mirror `PlacesBackend::transformDayQuery` (`lib/ClustersBackend/PlacesBackend.php:58-77`): `EXISTS` on `memories_places.osm_id`.
- `embeddedTag` (EXIF) → `jsonArrayContains()` across the four fields `Keywords/Subject/TagsList/HierarchicalSubject` OR'd together (same fields as `transformEmbeddedTagsFilter`/`EmbeddedTagsBackend::applySQLPathMatch`, `lib/ClustersBackend/EmbeddedTagsBackend.php:465`). Recursive path matching (tag + subtree) reuses the existing `Exif::embeddedTagPathMatches` semantics; keep the MySQL `JSON_SEARCH` prefix trick behind the dialect helper (pg: `jsonb_path_exists` with a `like_regex`, or expand to explicit prefix OR-terms). Extract `applySQLPathMatch` into the shared helper so browse + filter use one implementation.

Since relational leaves are pure SQL on every DB and EXIF leaves are now SQL on MySQL **and** Postgres (Section 0), the entire tree compiles to SQL on this instance — no SQL/PHP straddling. (SQLite/Oracle: EXIF leaves unsupported; the builder should surface that, but it's not this instance.)

Group compilation: `and`→`implode(' AND ', ...)`, `or`→`implode(' OR ', ...)`, `not`→`'NOT (' . compile(children[0]) . ')'`. Reject/clamp malformed trees defensively (max depth ~6, max leaf count ~50) since this is user-supplied JSON from a request param — not for UX, but because an unbounded tree is an easy way to build a pathological query. Parameter names must be unique across leaves (thread the `&$paramCounter`).

New transform method `transformFilterExprFilter(IQueryBuilder &$query, bool $aggregate, array $tree)` added to `TimelineQueryFilters.php`, wired into `DaysController::getTransformations()` behind a new `filterExpr` request param (JSON, URL-encoded), decoded and validated there before being pushed onto `$transforms`. This composes via the existing top-level `andWhere` exactly like every other filter — the *whole* expression tree is one AND'd condition alongside rating/date/filename/etc., so the user-facing semantics are: "(scalar filters) AND (tag/people/place expression)".

### 3. Simple scalar filters (also new `transformXFilter` methods in `TimelineQueryFilters.php`, no tree needed)

- **Filename / Filepath** (non-EXIF, all DBs): use a **correlated `EXISTS` subquery on `filecache`**, not direct `f.name`/`f.path` references — because the aggregate `getDays()` query does **not** join filecache as `f` (in non-compat/trigger mode `filterFilecache` filters via `cte_folders` on `m.parent`, no `f` alias; only `getDay()` joins `f` at `:166`). `EXISTS(SELECT 1 FROM filecache ff WHERE ff.fileid=m.fileid AND ff.name LIKE :p)` works identically in both query shapes. Reuse the regex/LIKE heuristics from `EmbeddedTagsQueryFilters::transformPatternFilter` (`lib/Db/EmbeddedTagsQueryFilters.php:38`, incl. `isRegexPattern()`/`escapeLikePattern()` and its pg `~` branch) — extract them to a shared location (~15 lines). Note `filecache.path` is storage-relative, not the human-visible path — say "matches folder path" in UI copy.
- **Date range** (non-EXIF, all DBs): convert requested start/end dates + granularity to a `dayid` range (dayid = `floor(localUnixTime/86400)`, set in `TimelineWrite.php:161`) and `andWhere(gte('m.dayid', ...), lte('m.dayid', ...))` — exactly the pattern already used for bucket-id ranges in `TimelineQueryDays.php:136-139`. Presets (Last 7/30 days, This month, This year) are computed client-side into a concrete start/end before being sent — no new backend concept needed. Works on `m.dayid` directly (on the row), no join needed.
- **File type** (non-EXIF, all DBs): for the simple photo/video split reuse `m.isvideo` (a real column on `memories`, verified; used by `transformVideoFilter`, `TimelineQueryFilters.php:78`) — no join. For specific formats (RAW/HEIC/…) use a correlated `EXISTS` on `filecache ff JOIN mimetypes mt ON ff.mimetype=mt.id WHERE ff.fileid=m.fileid AND mt.mimetype IN (...)` — again via EXISTS so it works in the aggregate query too (`mimetypes` is only joined in `getDay()` at `:132`, not `getDays()`).
- **Camera make/model/lens** (EXIF): via the Section 0 dialect helper `jsonScalar('Make'|'Model'|'LensModel')` with `ILIKE`/`=`. MySQL and Postgres both covered by the helper; guarded cast on pg.
- **Rating range** (EXIF): extend `transformMinRatingFilter` → `transformRatingRangeFilter($query, $aggregate, $min, $max)` (max=0/null = unbounded), via `jsonScalar('Rating')` numeric compare (helper handles the pg `::numeric` cast).
- **Favorites**: already works as a composable filter today (`fav` param, unconditional on route — `DaysController.php:106-108`); just needs a checkbox in the new filter panel instead of only being reachable via the `/favorites` route.

### 4. Frontend: replace `FilterComponent.vue` with the full panel

Extend `IFilters` (`src/typings/data.d.ts:192-196`) into the new shape (rating min/max, favorites, filename, filepath, date range + granularity + preset, fileType, camera fields, and one `FilterNode | null` for the tag/people/place expression tree). Rework `src/components/modal/FilterComponent.vue`:
- Keep the existing rating slider (`RatingStars`, now at `src/components/common/RatingStars.vue`), extend to a range.
- New expression-builder sub-component (e.g. `src/components/modal/FilterExpressionBuilder.vue`) — a recursive component rendering `FilterNode`, letting the user add leaves (tag/embedded-tag/person/place pickers — reuse `NcSelectTags`, `src/components/common/EmbeddedTagSelector.vue`, and new selectors for people/places modeled on the same pattern) and groups, with an AND/OR toggle per group and a NOT toggle per leaf/group, plus "Match Any"/"Match None" quick buttons that auto-build the OR/NOT-of-OR shapes described above.
- New date range picker + granularity selector + preset buttons.
- New filename/filepath text inputs, file type checkboxes, camera make/model/lens inputs (populate dropdown options from a lightweight new endpoint listing distinct cameras in the account, if feasible — otherwise free-text).
- Update `Timeline.vue`'s `getQuery()` (now `src/components/views/Timeline.vue`, `getQuery()` at ~`:784`, `filters` data at `:200-204`) to serialize the new filter shape: existing flat params (`minRating`→range, `tags`/`embeddedTags` flat AND) get replaced by the new `filterExpr` JSON param for the tag/people/place tree, plus new flat params for filename/filepath/date-range/fileType/camera/favorites. Update `hasFilters` (`Timeline.vue:382` — **note it currently omits `embeddedTags`, a pre-existing gap; fix it as part of this rewrite**) and `resetFilters` (~`:1780`) accordingly. `FilterDropdownAction.vue`'s (`src/components/top-actions/FilterDropdownAction.vue`) `hasActiveFilters` computed (~`:69`) and default `currentFilters`/`initialFilters` need the same shape update.
- Leave the existing single-tag `Tags`/`EmbeddedTags` cluster-browse routes (`ClusterView.vue`, `EmbeddedTagsController::sub()`) untouched — those are folder-style browsing of one tag at a time, a separate feature from this filter panel.

### 5. Saved filters ("smart albums")

Store as a JSON blob per user via the existing `OCP\Config\IUserConfig` mechanism already used elsewhere in this app (`Util::getUserLang`, `UtilController.php:124`; `Util.php:318,336,356,380`) — `setValueString($uid, 'memories', 'saved_filters', json_encode($presets))` / `getValueString(...)`. New small controller (or a couple of methods added to `OtherController.php`) exposing list/save/delete, following the existing route registration pattern in `appinfo/routes.php`. Frontend: a "Saved Filters" list in the filter panel (save current state under a name, click to re-apply, delete).

### 6. Searchbar (stretch, do last if time remains)

Optionally add filename matches to `Searchbar.vue`'s results (`src/components/header/Searchbar.vue:150`) via a small new lightweight endpoint (distinct from the timeline filter — this needs to search across the whole account by name and return enough to preview/navigate to a single file), so typing a filename in the header search jumps straight to that photo. This is additive and independent of the filter panel; skip it first if the plan needs to be trimmed for time, since the core ask is the timeline filter panel.

## Suggested build order

1. **Section 0** (Postgres JSON EXIF + dialect helper + flip flag) — verify existing rating/embedded-tag filters still work on the live pg instance, now via SQL, before anything else. This de-risks the whole feature.
2. Backend scalar filters (Section 3) + `FilterExpression` compiler (Section 2), each with a quick `occ`/curl check against the `/api/days` + `/api/days/{id}` endpoints.
3. Frontend panel rewrite (Section 4).
4. Saved filters (Section 5).
5. Searchbar (Section 6, stretch).

## Verification

- `npm run typecheck` after each frontend phase (fine to run freely).
- `make nextcloud-build` to deploy to the live instance (per project convention — plain `npm run build`/`production` does not update the running Nextcloud instance; **never run `npm run production`**, it's memory-heavy).
- **Postgres is the live DB** — every backend check below exercises the pg SQL path, which is the one that matters here. A fast way to check a filter without the UI: hit `/apps/memories/api/days?...&filterExpr=<url-encoded-json>` and `/api/days/{dayid}?...` directly (curl with a session cookie, or `occ`), and eyeball counts.
- Manually test in the browser (the user tests manually; **do not use Playwright/automated browser tools**):
  - **Section 0 regression**: existing minimum-rating and embedded-tag filters still return correct results on pg after the flag flip (they now run in SQL, not PHP).
  - Each new scalar filter (filename, filepath incl. a regex pattern, date range + each preset, file type incl. a specific format, camera make/model, rating range, favorites) alone and combined.
  - Expression builder: a case per operator — plain AND of 2 tags, plain OR of 2 tags, NOT of 1 tag, NONE-of-3 preset, and a **mixed EXIF+relational group** (e.g. `embeddedTag:Beach OR person:Alice`) which specifically exercises the pg jsonb + relational-EXISTS combination in one SQL statement.
  - Malformed/empty `m.exif` rows don't error the query (the `::jsonb` guard) — confirm a library with some un-indexed/EXIF-less files still filters without a 500.
  - Saved filters: save, reload page, re-apply, delete.
  - Confirm the dead `showCollaborativeTagsFilter` path and the superseded flat `tags`/`embeddedTags`/`minRating` params are fully replaced, not left dangling alongside the new UI.
