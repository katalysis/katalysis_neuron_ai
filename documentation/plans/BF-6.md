# BF-6 plan: lists say when there are more

Status: **approved** (Glyn, 2026-10-23, with the decisions in §8). Base `development`, branch `task/BF-6-list-totals`; a `katalysis_pro` branch of the same name from `themeintegrationtemplates`. Significant: it changes tool results and tool definitions the model sees.

## 1. Goal
Every list tool that stops short says so: it reports the true total of what the user may see, takes an `offset` to get the rest, and when there is more, says "showing 1–20 of 312; call again with offset 20, or narrow with …". No tool reports a capped count as a total (B-TRU-3).

## 2. What the code does today (corrects BF-5 §3.3)
BF-5's audit said these lists are capped. **Five of them aren't: they ignore `limit` and return everything.** Concrete's `ItemList::getResults()` runs the query without a limit; `setItemsPerPage()` is read only by the pagination objects (`concrete/src/Search/ItemList/ItemList.php:86`, `Database/ItemList.php:61`; no `setMaxResults` anywhere in `PageList`, `UserList`, `FileList` or `ItemList`). Found by reading the code; the red tests in §5 prove it on the dev site before anything changes.

| # | Tool | File | `limit` today (default / max) | What happens today | Count field today |
|---|---|---|---|---|---|
| L1 | `list_pages` | `PagesToolkit.php:128` | 20 / 100, **ignored** | Every page the user can view, matching the filters | none (bare list) |
| L2 | `get_page_children` | `PagesToolkit.php:2245` | 100 / 100, **ignored** | Every viewable child | `total` = count returned (true, by accident) |
| L3 | `list_files` | `FilesToolkit.php:388` | 20 / 100, **ignored** | Every viewable file | none (bare list) |
| L4 | `list_users` | `UsersToolkit.php:42` | 20 / 100, **ignored** | Every viewable user | none (bare list) |
| L5 | `search_users` | `UsersToolkit.php:185` | 20 / 100, **ignored** | Every viewable match | `total` = count returned (true, by accident) |
| L6 | `search_blocks_by_type` | `BlocksToolkit.php:186` | 100 / 500, applied in SQL | Capped | "Found N instance(s)" = **capped count** |
| L7 | `list_page_versions` | `VersionsToolkit.php:80` | 10 / none, applied | Capped, newest first | none |
| L8 | core `list_people` | `PeopleToolkit.php:45` | 100 / none, applied in SQL | Capped | `totalPeople` = **capped count** |
| L9 | `katalysis_pro` `list_people` | `KatalysisProEntitiesToolkit.php:73` | 20 / 100, applied in PHP after the search filter | Capped | `count` = **capped count** |
| L10 | `katalysis_pro` `list_places` | same, :517 | 20 / 100 | Capped | `count` = **capped count** |
| L11 | `katalysis_pro` `list_reviews` | same, :602 | 20 / 100 | Capped | `count` = **capped count** |
| T1 | `get_topic_tree_structure` | `TopicsToolkit.php:76` | `max_depth` 10 | Stops at the depth; a node at the cut reports `hasChildren: false` and `children: []` (BF-5 R14) | `totalTopics` = nodes shown |

Core `list_people` (L8) is shadowed by `katalysis_pro`'s whenever that package is installed (`ConcreteCmsAgent.php:141`), as on the dev site; it is still fixed, the same way.

No other tool in either package caps a list: I checked every `setMaxResults`, `setItemsPerPage`, `limitResults`, `LIMIT` and early `break` in `src/Tools/` and `katalysis_pro/src/KatalysisPro/Ai/`. The rest are single-row lookups, "did you mean" suggestions (10), or uncapped lists (`list_file_folders`, `list_page_types`, the attribute lists).

## 3. Design

### 3.1 Offset on every list (L1–L11); the tree says it was cut (T1)
**Recommendation: every list gets an `offset` input** (0-based, default 0), next to its `limit`. Not a page number: an offset maps directly to "call again with offset 20", needs no page size to agree between calls, and works the same over SQL and PHP-filtered lists. "Only say it was capped" would leave the model stuck when the user wants the rest and there is no filter to narrow by (all users, all versions of a page).

The tree gets no offset (a slice of a tree means nothing). It says where it was cut (§3.3).

### 3.2 What every list result carries
The tool's existing items key stays as it is where there is one (`children`, `users`, `people`, `places`, `reviews`). The three tools that return a bare list today (L1, L3, L4) return an object with `pages` / `files` / `users`, because a bare list can't carry a total. Every list then has:

- `total`: how many items match, counting only what the user may see (the same permission checks as today)
- `offset`, `returned` (items in this result), `more` (`true` when `offset + returned < total`)
- `note`, only when `more` is true, for example:
  - `list_pages`: "Showing 1–20 of 312. For more, call list_pages again with offset 20, or narrow with parentPath, pageType or includeDescendants=false."
  - `list_users`: "Showing 1–20 of 45. For more, call list_users again with offset 20, or use search_users."
  - an offset past the end: "offset 400 is past the end: there are 312."

The misleading count fields are fixed: `get_page_children.total`, `search_users.total` and core `list_people.totalPeople` become the true total; `katalysis_pro`'s `count` keeps meaning "items in this result" (as the generic wrapper at `KatalysisProEntitiesToolkit.php:770` uses it), and `total` is added beside it.

The two tools that return Markdown (L6, L7) keep returning Markdown (their envelope is P3.x's, not this task's) and say the same in text: "Found 412 instance(s) … showing 1–100." and the same "call again with offset 100" line.

One shared helper does the arithmetic and the wording: `src/Tools/Utilities/ListWindow.php` (clamp `limit` and `offset`, slice, build the fields and the note). `katalysis_pro` can't depend on this package, so it gets the same few lines privately, with the same wording.

### 3.3 The topic tree (T1)
- A node whose children were cut by `max_depth` reports `hasChildren: true`, `children: []` and `childrenNotShown: true`.
- The result gets `cutAtDepth: true` and a note when any node was cut: "Cut at depth 2: N topic(s) have children not shown. Call again with a higher max_depth, or get_topic_details for one topic."
- `totalTopics` stays "nodes shown" and is renamed in the description as such; I won't count the hidden nodes (that means walking the whole tree, which `max_depth` exists to avoid).

### 3.4 How each list gets its total
- **L1–L5 (Concrete item lists with a permission checker):** `getResults()` already loads and permission-checks every match today, so: take the whole permitted list, `total = count()`, slice by `offset`/`limit`. Cost is the same as today's calls (they already load everything); the result the model gets is smaller. Concrete's own `getTotalResults()` returns -1 when a permission checker is set, so it can't be used.
- **L6:** a `COUNT(*)` query with the same `WHERE`, plus `setFirstResult(offset)`. Keeps the 500 max.
- **L7:** `VersionList::getTotal()` and `get($limit, $offset)` (legacy `DatabaseItemList`).
- **L8:** `PeopleList::getTotalResults()` (no permission checker; a plain count) and `setFirstResult`.
- **L9–L11:** they already filter every row in PHP; count all matches, then slice.

**Stable order across calls:** where the sort can tie (`list_pages` by date modified, `list_files` by date added, `list_users` by date added, `search_blocks_by_type` by block modified date), add the ID as a second sort key so offset 20 continues where offset 0 stopped. A page changed between two calls can still move; the note doesn't promise otherwise.

### 3.5 Limits
No default or maximum changes, except that L1–L5's documented limits now actually apply, and core `list_people` (L8) gets the same 1–100 clamp as the others (today `limit: 5000` would return 5000). Tool descriptions gain one line each: "Returns total and more; use offset for the next items."

## 4. Files
**This package (one PR into `development`):**
- `documentation/plans/BF-6.md` (this plan)
- `src/Tools/Utilities/ListWindow.php` (new)
- `src/Tools/PagesToolkit.php` (L1, L2), `src/Tools/FilesToolkit.php` (L3), `src/Tools/UsersToolkit.php` (L4, L5), `src/Tools/BlocksToolkit.php` (L6), `src/Tools/VersionsToolkit.php` (L7), `src/Tools/PeopleToolkit.php` (L8), `src/Tools/TopicsToolkit.php` (T1)
- `tests/Unit/ListWindowTest.php` (new), `tests/Integration/ListTotalsTest.php` (new)
- `tests/Support/ToolBaseline.php`: `CHANGED` entries for the eight changed core tools (the mechanism BF-5 and BF-10 used)
- `tests/Integration/KatalysisProToolsTest.php`: a `CHANGED` list like `ToolBaseline`'s for the three `katalysis_pro` tools (**question 2**: this changes a passing test)
- docs: `documentation/BEHAVIOUR_SPEC.md` (B-TRU-3 status), `documentation/DELIVERY_PLAN.md` (tick), `documentation/PROGRESS.md`

**`katalysis_pro` (its own PR into `themeintegrationtemplates`):**
- `src/KatalysisPro/Ai/KatalysisProEntitiesToolkit.php` (L9–L11)

## 5. Tests (red first)
Commit order as in CLAUDE.md: plan, tests (red), code, docs, PROGRESS.

**Unit, `ListWindowTest`** (no Concrete): clamping of `limit` and `offset` (negative, zero, over the max, null); slicing; `more` at the boundary (`offset + returned == total` is false); the note's wording, the offset in it and the "past the end" case; no note when nothing more.

**Integration, `ListTotalsTest`**, one test (or a data-provider case) per tool. Each computes the expected total independently (its own `PageList`/`UserList`/`FileList`/SQL count with the same filters and permission check), so it assumes no fixed site state, and uses `limit: 1`, so it needs only two matching items:
- L1 `list_pages` with `parentPath: /neuron-test` (the seed has 8+ children); L2 `get_page_children` on `/neuron-test`; L3 `list_files` (skipped with a reason if the site has fewer than 2 files the admin can view; the seed adds one); L4 `list_users` (the seed adds 2 users); L5 `search_users` with `neuron_` (the seed's 2 users); L6 `search_blocks_by_type` `content` (the seed has many); L7 `list_page_versions` on a seed page with 2+ versions, or skipped with a reason; L8 core `list_people` (the seed's 3 test people); L9 `katalysis_pro` `list_people` with `search: "Test Person"` (3); L10–L11 skipped with a reason if the site has fewer than 2.
- For each: `returned == 1`, `total` equals the independent count, `more` is true, the note names `offset 1`; then `offset: 1` returns a different item; then `offset: total` returns none with the "past the end" note.
- **Red today:** L1–L5 return every item (`returned > 1`), L6–L11 have no `total` or report the capped count, and none accepts `offset` (`StrictInputTool` refuses it).
- T1: a test that creates a three-level topic tree, calls with `max_depth: 1`, checks `hasChildren: true`, `childrenNotShown: true`, `cutAtDepth: true` and the note, and deletes the tree. The P1.12 leak check proves it leaves nothing; if a topic tree delete leaves permission rows (as `Page::delete()` does), I stop and ask rather than add an exception.
- Nothing is written apart from T1's tree; no test calls the AI.

**Registry and baselines:** `ToolBaselineTest` fails red-first for the eight core tools until their `CHANGED` entries are added (same commit as the code); `ToolConversionRegistryTest` keeps checking every tool's schema, and its unknown-input test picks up `offset` as allowed.

**Tiers:** fast tier after every change, full suite and the JS safety tests before the PR (`/finish-task`).

**Scenarios** (rule: the task touches tools). The ones whose runs call these tools (from the P2.9 run files): 03 and 21 (`list_people`), 04 (`list_pages`, `get_page_children`, `get_topic_tree_structure`, `list_people`, `list_places`), 12 and 17 (`get_page_children`), 15 (`list_pages`), 18 (`list_files`). Repeats as at P2.9: **03, 04, 17, 18 ×3; 12, 15, 21 ×5** = 27 runs, once, after the code, compared with each scenario's last recorded label (P2.9 exit, or a later BF run where PROGRESS records one). P2.9 averaged about $0.063 a run; these call more tools, so **estimate about $2.20, cap $3.50**. Both packages' branches checked out together for the run. The merge rule applies (no pass → fail, no partial → fail; pass → partial listed).

**Manual check** (Glyn, optional): in the panel, "list all pages" on the dev site: the reply says how many there are and offers more.

## 6. Out of scope
- **Prompt changes** (rule 5). `instructions()` line 345 mentions an `onlyVisible` input that `list_pages` doesn't declare (the handler takes it, so `StrictInputTool` refuses it) and `get_page_children` doesn't have: noted for P3.3 (decision 7), not fixed here.
- The envelopes of `search_blocks_by_type` and `list_page_versions` (Markdown strings, not `{ok, data}`): P3.x.
- Personal data in `list_people` results (emails): P3.5 / B-PII-2.
- Topic category nodes, which `get_topic_tree_structure` skips with their children (BF-5 R14's last part): P3.3 (decision 5).
- Uncapped lists getting a cap (`list_file_folders`, the attribute lists).
- `src/Toolkits/` (legacy).

## 7. Risks and questions
1. **The five uncapped lists change behaviour, not only wording.** Today `list_pages` gives the model every page; after BF-6, 20 and "312 in total". A scenario that relied on seeing everything in one call could change (15 and 04 call `list_pages`). The scenario run shows it; the note tells the model how to get the rest. Is that what you want, or should L1–L5 keep returning everything up to the max, with the total? I recommend the documented limits.
2. **Changing passing tests.** `ToolBaselineTest` already has `CHANGED` for exactly this. `KatalysisProToolsTest::testEveryToolIsDefinedAsOnNeuron3` has none, and will fail once `katalysis_pro`'s three tools take `offset`. I propose a `CHANGED` list there, in its own commit with the reason. OK?
3. **Merge order of the two PRs.** Whichever merges first leaves the other side's `KatalysisProToolsTest` red until the second merges (the test checks the definitions both ways). I propose merging them back to back: `katalysis_pro`'s first, this one straight after. Alternatively the test accepts either definition until both are in; I don't recommend that (it hides a mismatch).
4. **`search_blocks_by_type` checks no view permission** on the pages it lists, so its new `total` would count blocks on pages the user can't see (its rows already show their paths today). Fix it here (filter rows by `canViewPage` before counting and slicing; the SQL `LIMIT` then goes, as for L1–L5), or leave it to P3.5's permission work? I recommend fixing it here: an honest total shouldn't count what the user can't see. It adds one red-first test (as `neuron_chat_editor`, a page outside `/neuron-test` isn't counted).
5. **Topic categories** are skipped with everything under them. It's the same "the list looks complete but isn't" pattern. Include it (show categories as nodes) or leave it out? I recommend a follow-up, as it changes the tree's shape, not just its completeness.
6. **Large sites:** L1–L5 still load and permission-check every match to count them, as they do today. Fine on the dev site; on a site with many thousands of pages it is slow. A faster count needs Concrete's permission-aware pagination or a capped "more than N" total. Not proposed now; noted for the tool policy.

## 8. Decisions (Glyn, on approving the plan)
1. The five uncapped lists (L1–L5) enforce their documented limits, with the "more" note.
2. `KatalysisProToolsTest` gets an allowed-differences list, in its own commit.
3. The two PRs merge back to back, `katalysis_pro`'s first.
4. `search_blocks_by_type` is fixed here: it neither returns nor counts blocks on pages the user can't view. Red-first test with a user who lacks view rights.
5. Topic categories go to P3.3.
6. Large sites: use a count query for the total if Concrete's pagination gives one; otherwise note it for P3.3. **Checked:** it doesn't. With a permission checker, `PageList`/`UserList`/`FileList::getTotalResults()` return -1, and `PermissionablePagination` loads and checks up to 1,000 results in PHP (`concrete/src/Search/Pagination/PermissionablePagination.php`), which is what L1–L5 do already, but capped. A count query ignores permissions. So L1–L5 keep loading every permitted match; noted for P3.3 in the PROGRESS entry.
7. The `onlyVisible` mismatch goes to P3.3, not P3.7.
8. Scenarios as in §5, cap $3.50. Stop and ask before changing any other existing test. Open both PRs and stop.
