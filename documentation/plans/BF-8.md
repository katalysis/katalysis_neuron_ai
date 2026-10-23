# BF-8 plan: EPRA field renaming applies only to EPRA blocks

Status: **approved** (Glyn, 2026-10-23): the interim scope; `epra_resources_list` only, with a PROGRESS note asking for a check on the EPRA site before any deploy there; no new scenario; 05 and 07 ×3, cap $1.50. Base `development`, branch `task/BF-8-epra-aliases`. Significant: it changes what the block write tools save.

## 1. Goal
The block write tools pass a block's fields to its controller exactly as given, unless the block is an EPRA block that the renaming was written for. A core or `katalysis_pro` block's `title`, `name`, `description` (and the other renamed fields) can be set again.

## 2. What the renaming does today
Found by reading the code; the red tests in §5 prove it on the dev site before anything changes.

**Where:** `PagesToolkit::normalizeCustomBlockPayload()` (`src/Tools/PagesToolkit.php:2599`). It takes the block type handle as its first argument and **never reads it**. It runs for every block type, in three write tools:

| Tool | Where it calls it |
|---|---|
| `add_block_to_page_area` | `PagesToolkit.php:2412` |
| `update_block_in_page_area` | `PagesToolkit.php:2546` |
| `batch_update_page_content` | `checkBatchItem()`, `PagesToolkit.php:832` |

No other tool calls it. (`sync_multi_links_to_epra_resources_list` builds its own EPRA rows and is disabled; `BlocksToolkit::extractBestTitleFromRow()` only reads, and isn't changed.)

**What it does, in order:**
1. **Repeatable rows renamed to `slide`:** if the payload has no `slide`, the first of `entries`, `items`, `resources`, `slideItems`, `resourceItems`, `rows`, `slides`, `children` or `listItems` that is an array is moved to `slide` and the original key removed.
2. **Each `slide` row** gets the field renaming (3), then `normalizeRepeatableLinkedRecord()` (copies `resolvedLink`, `file`, `page`, `document`, `image` objects into `link_*` / `Image` IDs and fills `link_Title`) and `inferRepeatableLinkContract()` (normalises `link` and guesses it from which `link_*` field is set).
3. **Field renaming, recursively through the whole payload** (`normalizeBlockFieldAliasesRecursive()`, :2758), at every depth, top level included. The alias is removed; its value goes to the target only if the target isn't already set:

   | Renamed from | To |
   |---|---|
   | `title`, `name`, `heading` | `Title` |
   | `description`, `summary` | `precis` |
   | `link_title`, `linkTitle` | `link_Title` |
   | `link_url`, `linkUrl` | `link_URL` |
   | `link_relative_url`, `linkRelativeUrl` | `link_Relative_URL` |
   | `document_id`, `documentId` / `document_title`, `documentTitle` | `link_Document_ID` / `link_Document_Title` |
   | `page_id`, `pageId` / `file_id`, `fileId` / `image_id`, `imageId` | `link_Page` / `link_File` / `Image` |
   | `link_type`, `linkType` | `link` |

   Every target is a column of `btEpraResourcesListEntries` (`loadEpraResourceEntries()`, :2631).

Separately, two places already check the handle: for `epra_resources_list` only, an update with no `slide` reloads the stored rows (:833, :2547). That stays.

**What breaks today** (block fields from each `db.xml` and `save()`):

| Block | Field the tools can't set | Why |
|---|---|---|
| core `hero_image`, `feature`, `feature_link`, `image`, `video`, `youtube`, `google_map`, `rss_displayer`, `search`, `tags`, `topic_list`, `date_navigation` | `title` | renamed to `Title`; `save()` never sees it |
| core `testimonial` | `name` | renamed to `Title` |
| core `faq` | `title`, `description`, `linkTitle` (parallel arrays) | renamed to `Title`, `precis`, `link_Title` |
| core `image_slider` | `title`, `description`, `linkType` (parallel arrays) | renamed |
| `katalysis_pro` `katalysis_heading_group` | `heading` | renamed to `Title` |
| `katalysis_pro` `katalysis_feature_box` | `title`, `linkType` | renamed |
| `katalysis_pro` `katalysis_feature_list`, `katalysis_advanced_gallery` | **all their entries** | `save()` reads `entries`, which is moved to `slide`; inside the rows `linkTitle`, `title`, `description` are renamed too |
| `katalysis_pro` `katalysis_places`, `katalysis_people`, `katalysis_reviews`, `katalysis_modal` | `title` | renamed |

BF-5 found the `title` case only (PROGRESS, BF-5 entry); the entries case in `katalysis_pro` is new. The tools report success in all of these: the field is dropped silently (B-TRU, the "success for a change it never made" pattern of P1.13).

## 3. Which block types it should apply to, and where that list comes from
**`epra_resources_list` only.** The evidence:
- every rename target is a `btEpraResourcesListEntries` column, and `slide` is that block's `save()` key (EXTENSIONS.md's descriptor example: `"table": "btEpraResourcesListEntries"`, `"save_key": "slide"`, fields `Title`, `precis`, `link.*`)
- the two existing handle checks name only `epra_resources_list`
- `doc_download`, the other EPRA block this package knows of, is only read (`get_doc_download_files_on_page`); no write path gives it special handling

**Where the list lives (interim):** one constant in `PagesToolkit`, `EPRA_PAYLOAD_BLOCK_HANDLES = ['epra_resources_list']`, used by `normalizeCustomBlockPayload()` and by the two existing checks, with a comment that it is EPRA knowledge in core, kept only until P3.4/P3.10 move it out.

**I can't check the EPRA site's block list from here:** no EPRA block is installed on the dev site, and the EPRA site's copy of this package is identical to ours (PROGRESS, P0 diff), so its own blocks aren't in this workspace. The aliases `slides` and `slideItems` hint that a slider block was once in mind. **Question 1** (§7).

## 4. Move it out, or scope it now
**Moving it out through `ExternalToolkitRegistry`** would need:
- a new extension point in this package. `ExternalToolkitRegistry` registers tools only; it has no way to change another tool's payload. It would need something like `registerBlockPayloadNormalizer(string $blockHandle, callable $normalize)`, called by the three write tools, with its own tests (a normalizer that throws, two for one handle, the text check after it)
- the ~250 lines of EPRA code (:2599–:2860) moved into the EPRA package, which **isn't in this workspace and has no agreed home yet** (DELIVERY_PLAN §7, decision 3: "a separate package or the EPRA theme package")
- a release of both together, tested on the EPRA site, since until then that site loses the renaming

And P3.10 replaces exactly this with descriptors (EXTENSIONS.md: "map clean field names … to what `save()` expects … This replaces all the alias guessing"; P3.10.2 and P3.10.7's shim). A normalizer hook now would be a public API made to be thrown away in P3.10, which the shim would then have to carry.

**Recommendation: scope it now, as the interim fix BF-8 allows,** and leave the move to P3.4 (EPRA out) with P3.10 (the descriptor that replaces it). It fixes every non-EPRA block today, it keeps the EPRA site's behaviour exactly as it is (the only block the renaming was meant for still gets it), and it needs no decision 3. I'll say so in the PROGRESS entry and the code comment.

**The change** (small, in place, no code moved or deleted, rule 1):
- `normalizeCustomBlockPayload()`: return the payload unchanged unless the handle is in `EPRA_PAYLOAD_BLOCK_HANDLES`. All three steps of §2 are skipped for other blocks, not only the field renaming: the `entries` → `slide` move is what breaks `katalysis_feature_list`, and the link guessing only makes sense for `slide` rows.
- the two `=== 'epra_resources_list'` checks use the constant.
- no tool description changes (none mentions the aliases), so the tool baseline and the prompt are unchanged.

## 5. Tests (red first)
New class `tests/Integration/BlockFieldNamesTest.php` (BF-8), in the style of `BlockTextIntegrityTest`: a throwaway page under `/neuron-test`, the tools run as `neuron_editor`, pages deleted in `tearDown()`, leak check as usual. Each test reads the newest version's block row.

Red before the change:
1. `add_block_to_page_area`, core `hero_image` with `title` and `body`: the title is saved
2. `update_block_in_page_area`, core `search` with `title` (the test BF-5 couldn't write): the title changes, `buttonText` is kept
3. `update_block_in_page_area`, core `testimonial` with `name` and `paragraph`: the name is saved
4. `batch_update_page_content`, core `feature` with `title` and `paragraph`: the title is saved
5. core `faq`: an update sets an entry's `title`, `linkTitle` and `description` (the parallel-array form `save()` takes)
6. `katalysis_pro` `katalysis_feature_list`: `add_block_to_page_area` with `entries` saves the entries (row count and `linkTitle`), skipped if `katalysis_pro` isn't installed, as its other tests are

Green before and after (so the scope is proved, not only the fix):

7. `content` and `html` blocks unchanged (already covered by `BlockTextIntegrityTest` and the scenarios; no new test)
8. **the EPRA path still renames:** there is no `epra_resources_list` block type on the dev site, so this one calls `normalizeCustomBlockPayload()` through `ReflectionMethod` (as `PanelVisibilityTest` does for the controller's private `injectChatPanel`) with the EPRA handle and a payload using `entries`, `title`, `description` and `linkUrl`, and checks it comes back as `slide` rows with `Title`, `precis`, `link_URL` and an inferred `link`. And the same payload with `hero_image` comes back unchanged (red before)

`BlockTextIntegrityTest`'s comment about the alias (:198) is updated to say BF-8 fixed it; the test itself is unchanged.

**Tiers:** fast after each change, full before the PR, JS safety tests (unchanged code, run as `/finish-task` requires).

**Scenarios:** the change touches the write tools, so the scenario rule applies. No scenario uses a block type with a renamed field: 05, 06, 23 and 24 write `content` blocks, 07 moves `html` → `content`. So the expected result is **no change**, and the runs are a regression check only:
- `05-h3-to-h2 --repeat=3` and `07-move-content-between-block-types --repeat=3`, after the change
- **estimate about $0.90** (BF-5's 05 ×5 + 07 ×5 run was $1.46); **cap $1.50**
- no new scenario: this is a tool fix the PHPUnit tests cover directly (rule 5). **Question 2.**

**Manual check (optional, Glyn's choice):** in the browser, "set the hero image's title to …" on a `/neuron-test` page, then check the block in edit mode. Not needed for the merge.

Before the scenario run: `documentation/fixtures/backup_dev_db.sh BF-8`.

## 6. Files
- `documentation/plans/BF-8.md` (this plan)
- `documentation/DELIVERY_PLAN.md`: in this `PLAN:` commit, a P3.3 item from BF-6 (Glyn, in starting BF-8; below); last commit, the BF-8 tick
- `tests/Integration/BlockFieldNamesTest.php` (new)
- `tests/Integration/BlockTextIntegrityTest.php` (the one comment at :198)
- `src/Tools/PagesToolkit.php` (the constant, the early return, the two checks)
- `documentation/BEHAVIOUR_SPEC.md`: only if a B-T1/B-TRU item's status names this; I'll check and list it, or say none
- `documentation/PROGRESS.md` (entry and Current position)

**The P3.3 item** (Glyn's wording, added under "from BF-6"):
> in BF-6's scenario 15 run 4 the model paged `list_pages` one item at a time (`limit: 1` at offsets 0 to 9: 10 calls, 146,332 input tokens, against about 38,600 for the other runs). `ListWindow`'s "more" note should suggest a larger page when the requested limit is small, and P3.3 should consider a minimum page size

## 7. Out of scope
- moving the EPRA code out (P3.4 / P3.10), and `EXTENSIONS.md` (not changed)
- the other alias guessing that isn't EPRA-specific (there is none outside `normalizeCustomBlockPayload()`)
- `BlocksToolkit`'s read-side title guessing and `isDocDownloadBlockType()` (reads only; P3.4)
- `sync_multi_links_to_epra_resources_list` (disabled; P3.4)
- `katalysis_pro`: no change there; its blocks are only tested

## 8. Risks and questions
- **Question 1: other EPRA blocks that take `slide` rows?** If the EPRA site has, say, a slider that relied on the `slides` / `slideItems` → `slide` rename, it needs its handle in the constant. I can't see the EPRA site's blocks from here. If you know the list, I'll use it; otherwise `epra_resources_list` only, and the PROGRESS entry asks for a check on the EPRA site before the next deploy there.
- **Question 2: a scenario too?** I don't propose one (§5). If you want the model's side covered (it sends `title` for a core block and the reply says it was set), it would be a new scenario with a new fixture block, about $0.15 a run.
- **Risk: a site using another block that took `slide` by luck.** Only through the alias lists, and no block in core, `katalysis_pro` or `katalysis_pro_theme` reads `slide` (checked: `grep` over their controllers). A third-party block that reads `entries` (as `katalysis_pro`'s two do) is fixed, not broken.
- **Risk: the model was relying on the renaming.** Only for EPRA blocks, which keep it.
- Test 5 (`faq`) depends on how its `save()` takes parallel arrays through `UpdatePageBlockCommand`; if that path can't set it for a reason unrelated to BF-8, I'll drop test 5 and say so rather than widen the task.
