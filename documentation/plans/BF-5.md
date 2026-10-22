# BF-5 plan: no unverified claim after a truncated read; block text is not lost in a draft

Status: **approved (Glyn, 2026-10-22); decisions in §11, which override the sections above where they differ.** Significant task: it changes read tools, write tools and the text-integrity checks (CLAUDE.md rule 7, B-T1-2, B-TRU-7). Branch `task/BF-5-truncated-read-integrity` from `development`, PR into `development`.

## 1. Goal
A read tool never hands the model shortened text without saying so and naming the tool that returns it in full, and no write tool that replaces block text can save a version that silently loses existing text; when a write changes text, its result says what changed, so the reply can't claim "unchanged".

## 2. What happened in scenario 05 (P2.9, 3 of 5 runs)
- `get_block_controller_data` printed block 1's `content` (210 characters of HTML) as its first 97 **bytes** plus `...`, in three places (View Data, Raw Block Table Data, Controller Properties), with no note.
- The model rewrote the block with `update_block_in_page_area`, which has no text check, in two ways:
  - run 1: the shortened text, h3 → h2 (`…<h2>Seed Heading Three A...</h2>`): everything after the cut was lost;
  - runs 2 and 5: the shortened text "completed" with invented text ("Body text continues under the updated heading level.").
- Page text (the eval's `Html::text` over all blocks) went from 360 to 275, 325 and 333 characters. Each reply said the body text was unchanged. Draft only; live unchanged.

## 3. Audit: read tools that shorten content
Checked every registered tool (`ConcreteCmsAgent::coreToolkits()`; `AttributesToolkit` is not registered) and `katalysis_pro`'s. Nothing between a tool and the model shortens results (`CallableTool`, `ToolNameResolver`, `ToolResultScanner` only add `suspectedInstructions`).

### 3.1 Text cut by length
| # | Tool | Where | What is shortened | Says so today? | Full text from | Fix in BF-5 |
|---|---|---|---|---|---|---|
| R1 | `get_block_controller_data` | `BlocksToolkit.php:850-852` (`formatControllerValue`), used at :715, :726, :751 | Every string over 100 bytes → 97 bytes + `...` (can split a UTF-8 character) | No | `get_block_data` | **Return strings in full.** To keep the size down, a View Data or Controller Property string identical to a Raw Block Table field already shown prints `(same as raw field `content`)` instead of repeating it. |
| R1b | `get_block_controller_data` | :840-846, :750 | Arrays → "Array with N items"; objects → class name; array properties skipped | No | Main table: `get_block_data`. Child-table rows (repeatable entries): **no tool** | Each such line says what isn't shown and where to get it (`get_block_data` for the block table; "child-table rows are not returned by any tool" where there are child tables). |
| R2 | `get_page_blocks` | `PagesToolkit.php:485`, :488 → `HtmlToText::preview(…, 200)` | `contentPreview`: tags stripped, 200 characters + `...` | Partly: a top-level note (:534), not naming the full tool, and **overwritten** by the errors note (:539) | `get_block_data` (one block), `get_page_content` (the page) | Keep the preview (discovery). When shortened, the block gets `contentPreviewShortened: {fullLength: N, fullTextTool: "get_block_data"}`. The note names `get_block_data` and `get_page_content` and is kept when there are errors (errors get their own field). |
| R3 | `get_page` | `serializeBlock` :3218 via `getPageAreaSnapshot` | Same 200-character preview | No | same | Same per-block flag, through `serializeBlock` (one change covers R3–R7). |
| R4 | `get_current_page_context` | :108, :110 → :3218 (twice in one result) | Same | No | same | Same (shared). |
| R5–R7 | `add_block_to_page_area`, `update_block_in_page_area`, `move_block_between_areas` (write results) | :2371, :2479, :2918 → :3218 | Same preview, echoed as "what was saved" | No | same | Same (shared). `update_block_in_page_area` also reports the text check (§5.2). |
| R8 | `get_version_content` | `VersionsToolkit.php:535-537` | Content blocks: `strip_tags`, 97 bytes + `...`; other block types: no content at all | No | `get_block_data(blockID)` (the bID is that version's block) | Cut by characters, not bytes; mark `(shortened: N of M characters; get_block_data returns the full text)`. Other types: `(content not shown; get_block_data)`. |
| R9 | `list_page_versions` | `VersionsToolkit.php:159-162` | Version comments over 40 bytes → 37 + `...` | No | **None** | **Return comments in full** (they're short; no tool returns them otherwise). |
| R10 | System prompt page snapshot (not a tool) | `ConcreteCmsAgent.php:538-549` | Content blocks only, 2 per area, 12 lines, each 180 characters + `...` | No ("Current Page Content Snapshot:") | `get_page_content` | **Question 4.** Proposed: the heading line says these are shortened previews and that `get_page_content` returns the full text. It is in the prompt baseline (`prompt-baseline-v3.json`), so it changes the prompt for every scenario. |

### 3.2 Content left out (not by length)
| # | Tool | What is left out | Says so? | Fix |
|---|---|---|---|---|
| R11 | `get_page_blocks`, `get_page`, `get_current_page_context`, the write echoes, `get_version_content`, the prompt snapshot | Blocks in container and layout sub-areas: `Area::getHandleList()` returns top-level, non-global areas only (`PagesToolkit.php:459`, :3081; `VersionsToolkit.php:507`; `ConcreteCmsAgent.php:477`). Block counts leave them out too. | No | **In BF-5 for `get_page_blocks` and `get_current_page_context` / `get_page`:** a `subAreaBlocks` count and a note that `get_page_content` lists them (`Area::getListOnPage`, as `get_page_content` already does). The others: listed for P3.3. Question 3. |
| R12 | `get_page_content` | Non-Content blocks: only `getSearchableContent()` (a subset of fields) | No | Not in BF-5: P3.3 already reworks `get_page_content` (links, alt text, path input). Proposed note for P3.3. |
| R13 | `get_person_details`, core `list_people` | No `shortBiography`, `biography`, `qualification` (core `list_people` is replaced by `katalysis_pro`'s when installed) | No | Not in BF-5: P3.5 (B-PII-2, fields per request) and P3.10 (PeopleToolkit leaves core). |
| R14 | `get_topic_tree_structure` | Stops at `max_depth` (10); nodes at the cut report `hasChildren: false`; category nodes skipped | No | Not in BF-5 (no page text). Proposed follow-up (§9). |
| R15 | Page context tag on each user message | Title/path cut to 120 characters, no ellipsis (`PageContextTag.php:24`, :139) | No | Not in BF-5: identification only, never a write source. |

### 3.3 Lists capped without saying more exist
`search_blocks_by_type` (100, max 500; "Found N" is the capped count), `list_pages` (20/100), `get_page_children` (100; `total` is the capped count), `list_files` (20/100), `list_users` (20/100), `search_users` (`total` capped), `list_page_versions` (10), core `list_people` (100); `katalysis_pro`'s `list_people`, `list_places`, `list_reviews` (20/100, `count` capped). None has an offset. **Not in BF-5** (no block text; it's B-TRU-3's general rule over 11 tools in two packages). Proposed as a follow-up task (§9). Question 3.

### 3.4 Checked, no shortening
`get_block_data` (drops only `bID`; main table only), `list_page_blocks_by_type`, `count_blocks_by_type`, `get_doc_download_files_on_page`, `list_container_blocks`, `list_page_types`, the batch tools' results (checksums only), `create_page`, `update_page`, `move_page`, `delete_page`, `delete_block_from_page_area`, `approve_page_version`, `create_page_draft`, `discard_draft_version`, every Files, Users, Topics (bar R14), PageAttributes, UserAttributes, FileAttributes and AttributeSets tool, and `katalysis_pro`'s `get_person`, `get_place`, `get_review` and `resolve_file_id_from_path`.

## 4. Audit: write tools that can replace block or page text
| # | Tool | What text it can change | Text check today | In BF-5 |
|---|---|---|---|---|
| W1 | `update_block_in_page_area` (`PagesToolkit.php:2377-2483`) | Any column of one block, **whole row replaced** (`performSave` builds a fresh record, so an omitted column goes back to its default); saved twice (P3.3 item) | **None** | **Yes**: the shared check, strict by default (§5.1), checked before the draft is made; omitted columns kept (§5.3); result reports the check (§5.2). |
| W2 | `batch_update_page_content` (:548-803) | Any column of any block, whole row replaced | Yes, but the default `changeMode` is `freeform`, which checks nothing | **Yes**: same default and rules as W1 (Question 1); omitted columns kept. |
| W3 | `batch_update_content_headings` (:994-1300) | `content` of Content blocks, heading tags only | Yes, fixed formatting-only check (:1232-1236) | Uses the shared normaliser (stricter, §5.1); nothing else. |
| W4 | `add_block_to_page_area` | Adds a block; no existing text replaced. Risk: copying from a shortened read (scenario 07's shape) | No | No check (there is no "before"); covered by the read fixes. |
| W5 | `delete_block_from_page_area` | Removes a block and its text, as asked | No (BF-2's truthfulness check only) | No: a whole-block delete is the request, not a side effect. P3.6 approval. |
| W6 | `move_block_between_areas` | Moves a block; text kept | No | No. |
| W7 | `update_page` | `cName`, `cDescription`, attributes; whole values | No | No: short values given in full by the user. P3.6 approval preview. |
| W8 | `set_page_attribute_value` | Any page attribute (e.g. `meta_description`); whole value | No | No, as W7. |
| W9 | `create_page` | New page's name, description, attributes | n/a | No. |
| W10 | `discard_draft_version` | Throws away a draft's edits (never live) | No | No: P3.6. |
| Disabled | `sync_multi_links_to_epra_resources_list` (writes the live block, P3.4), `approve_page_version`, `move_page`, `delete_page`, `AttributesToolkit::set_page_attribute_value` (not registered), `katalysis_pro`'s `add_person`, `update_person` (replace biographies), `delete_person`, `delete_all_people` | | No | No: disabled. Listed so re-enabling them is checked against this. |

## 5. Changes

### 5.1 One shared text check (`src/Tools/Utilities/TextIntegrity.php`, new)
The private helpers in `PagesToolkit` (`evaluateTextChangePolicy`, `resolveWatchedTextFields`, `normalizeTextForComparison`, `buildTextChecksumMap`; :876-987) move into it, unchanged in shape, and the three tools call it. Moved, not deleted. Changes:
- **Normaliser:** each tag becomes a space before whitespace is collapsed, as the eval's `Html::text` does, so `<p>A</p><p>B</p>` → `<p>AB</p>` is a change. Today `strip_tags` joins them and the check can't see it.
- **New default mode `keep_text`:** every text segment of the stored value (the text between tags, normalised) must still be in the new value; text may be added. h3 → h2, bold → heading and appending a paragraph pass; scenario 05's three runs fail (each lost "Text under the first h3 heading." and/or the second heading and paragraph). The existing tests that append text pass unchanged.
- **Modes:** `keep_text` (default), `formatting_only` (text identical, as today), `scoped_text` (as today: fields listed in `textFields` may change freely, the others' text must stay identical). **`freeform` is removed** (Question 1): today it is a silent opt-out.
- **Shortened-source check, every mode:** refuse a field whose new text contains `...` or `…` right after a prefix (≥ 20 characters) of the stored text, where the stored text has no ellipsis there. That's the shape of R1, R2 and R8's output; message: "This looks like it was built from a shortened read; read the block with get_block_data and try again. Nothing was changed."
- **Refusal message** names the field, the first missing segment (up to 80 characters), the mode, and how to make an intended text change (`scoped_text` with `textFields`), and says nothing was changed.

### 5.2 `update_block_in_page_area`
- New inputs `changeMode` and `textFields` (described as in `batch_update_page_content`).
- The check runs on the block's current record **before** `getDraftOf()`, so a refusal leaves no draft (as BF-1/BF-2's refusals; `testARefusedBlockUpdateLeavesNoDraftBehind`'s rule).
- The result adds `textCheck: {mode, fields, textUnchanged: bool, removedText: [...]}` (removed segments, only possible under `scoped_text`) and before/after checksums, so the reply has a fact to report instead of a guess.
- Description: "Changes one block in a draft. Existing text is kept unless changeMode says otherwise; read the full block with get_block_data first."

### 5.3 Omitted columns are kept (W1, W2)
Before saving, columns of the block's main table that are missing from `value` are filled from the current record, so an update changes only the fields it names (as `epra_resources_list`'s `slide` already is, :2456). Question 2.

### 5.4 Read tools
As in §3's "Fix" column: R1, R1b, R2–R7 (one `serializeBlock` change plus the `get_page_blocks` note), R8, R9, R11 for the three page tools, and R10 if Question 4 says yes. `HtmlToText::preview()` gets a sibling that returns the preview plus `{shortened, fullLength}`, so every caller marks it the same way.

## 6. Tests (red first; each committed before its code)
**Unit, `tests/Unit/TextIntegrityTest.php`** (new, no Concrete):
- the normaliser treats tags as spaces;
- `keep_text`: h3 → h2 passes, append passes, a removed paragraph fails, a replaced paragraph fails;
- `formatting_only` and `scoped_text` as today; `freeform` refused as unknown;
- the shortened-source check catches `<h2>Seed Heading Three A...</h2>` against the seed block and leaves real ellipses in the stored text alone.

**Integration, `tests/Integration/BlockTextIntegrityTest.php`** (new; throwaway page under `/neuron-test` with seed block 1's HTML, as `DraftIsolationTest::newHeadingsPage()`; leak-checked):
- **Scenario 05's loss without an AI call (red today):**
  1. `get_block_controller_data` on the block; assert its output contains the full `content` (red today: 97 bytes + `...`).
  2. Build the payloads the recorded runs sent, from the **current** read output, without a model: (a) run 1: the shortened content with h3 → h2; (b) runs 2 and 5: the shortened content plus the invented paragraph.
  3. Send each to `update_block_in_page_area` as the run did (no `changeMode`). Assert: refused, the page has no draft, and the page text by the eval's own `Html::text` / `StateChecks` page-text function is unchanged. Red today: saved, text shorter.
- the right call (full content, h3 → h2) is saved in a draft, page text unchanged, `textCheck.textUnchanged` true;
- an intended text change with `scoped_text` + `textFields: ["content"]` is saved, and `removedText` lists what went;
- an update that omits a column keeps it (red today: reset to default); test on a block type with two text columns;
- `batch_update_page_content` with no `changeMode` and a lost paragraph is refused (red today: `freeform`).

**Integration, `tests/Integration/TruncatedReadTest.php`** (new): per read tool fixed, a long block and a short one:
- `get_block_controller_data` (R1, R1b); `get_page_blocks` (flag, note names the tools, note kept when errors are present: red today); `get_page`, `get_current_page_context` and one write result (flag); `get_version_content` (marked, characters not bytes); `list_page_versions` (full comment); sub-area count (R11).

**Changed tests (deliberate; each in the tests commit with the reason):**
- `GetPageContentTest:112` replaces a block's whole text: gains `changeMode: scoped_text, textFields: [content]`.
- `ToolBaselineTest` / `tool-baseline-v3.json`: two tools' definitions change (`update_block_in_page_area`: new inputs and description; `batch_update_page_content`: `changeMode`'s description). Proposed: a short "deliberate changes since v3" list in `ToolBaseline` (tool, task ID, reason), so every other tool is still held to the v3 capture. Question 5.
- `PromptBaselineTest`, only if Question 4 is yes: the one snapshot heading line, the same way.

**Tiers:** fast tier after each change; full suite before the PR.

## 7. Scenarios
Price assumption as P2.9 ($2.50 / $10 per million); per-run cost from P2.9's averages.

| When | Scenario | Why | Runs | Estimate |
|---|---|---|---|---|
| Before (on this branch before any code, after a DB backup) | 05 | Today's rate on `development` | ×5 | $0.50 (up to $0.75 at the Phase 1 exit's size) |
| After | 05 | The fix: `all_text_unchanged` must hold in 5 of 5 | ×5 | $0.50 |
| After | 06 bold → h3 in column 1 | Same tools and the new default | ×3 | $0.13 |
| After | 07 copy a block's content into a new HTML block | Reads a block's full content, then writes it | ×5 (unstable) | $0.45 |
| After | 16 injection in page | Read results change shape; the scanner reads them | ×5 (unstable) | $0.23 |
| After | 01, 02, 22 | `get_current_page_context` / `get_page_blocks` change shape; 02 counts blocks (R11) | ×3 each | $0.39 |

About **$2.20** in total; cap **$3.50**. Compared with P2.9's labels (and 05 with the "before" run). Pass in BF-5 means: no pass → fail or partial → fail; 05's `all_text_unchanged` passes in every run. 05 may stay "fail" overall for other checks (P3.3's `batch_update_content_headings` error); that's recorded, not fixed here.

## 8. Files
- **New:** `src/Tools/Utilities/TextIntegrity.php`, `tests/Unit/TextIntegrityTest.php`, `tests/Integration/BlockTextIntegrityTest.php`, `tests/Integration/TruncatedReadTest.php`, `documentation/plans/BF-5.md`.
- **Changed:**
  - `src/Tools/PagesToolkit.php` (W1, W2, W3, R2–R7, R11; the moved helpers)
  - `src/Tools/BlocksToolkit.php` (R1, R1b)
  - `src/Tools/VersionsToolkit.php` (R8, R9)
  - `src/Tools/Utilities/HtmlToText.php` (the preview sibling)
  - `src/ConcreteCmsAgent.php` (R10, only if Question 4 is yes)
  - `tests/Integration/GetPageContentTest.php`, `tests/Support/ToolBaseline.php` (and `PromptBaselineTest` if R10)
  - `documentation/BEHAVIOUR_SPEC.md` (status of B-TRU-7, B-T1-2, B-TRU-3), `documentation/DELIVERY_PLAN.md` (tick), `documentation/PROGRESS.md`

## 9. Out of scope
- List caps (§3.3), topic tree depth (R14), person fields (R13), `get_page_content`'s non-Content blocks (R12), the page context tag (R15), R11 in `get_version_content` and the prompt snapshot. **Proposed follow-ups:** a BF-6 "Lists say when there are more" (every capped list returns a true total or `more: true`, and an offset), with R14; R12 and R11's remainder into P3.3; R13 into P3.5.
- The model's reply wording: the fix gives the tool result the facts (§5.2); P3.11's "never describes content it didn't read" check is the scenario side.
- `update_block_in_page_area`'s double save (P3.3), the empty draft after a refused batch block (BF-3), approval of whole-value writes W5–W10 (P3.6), `src/Toolkits/` (legacy), the prompt's existing truncation rules (`ConcreteCmsAgent.php:287`, :300, :302: left as they are; P3.7 can drop them once this is in code).
- `katalysis_pro`: no change (its write tools are disabled; its list caps go to the follow-up).

## 10. Risks and questions
1. **`freeform` removed, default `keep_text`, for both `update_block_in_page_area` and `batch_update_page_content`?** Recommended: yes. A tool that can opt out silently is how 05 lost text. Risk: a legitimate whole-text rewrite now needs `scoped_text` + `textFields`; the refusal message says so. Alternative: keep `freeform` as an explicit choice, with `removedText` in the result.
2. **Keep omitted columns (§5.3)?** Recommended: yes; today an update naming one field resets the others. Risk: a block type where leaving a field out meant "clear it"; the model can still send an empty value.
3. **R11 (sub-area blocks) in BF-5 for the three page tools, and the list caps as a separate BF-6?** Recommended: yes and yes.
4. **R10, the prompt snapshot's heading:** in BF-5 (one line, changes the prompt baseline and every scenario's prompt), or leave to P3.7? Recommended: in BF-5; it's a data label, not a rule, and it's the same "unverified claim" source (a "summarise this page" answered from it).
5. **Tool baseline:** record deliberate changes per tool in `ToolBaseline` (recommended), or recapture `tool-baseline-v3.json` as a new baseline?
6. **The shortened-source check (§5.1)** is a heuristic against one output shape. Worth keeping once the reads return full text? Recommended: yes, cheap, and it's the exact shape the model copied.
7. `keep_text` checks segments are present, not their order: a reorder passes. Acceptable?
8. `get_block_controller_data` has no `guarded()` or permission check (P3.5 list, from P2.3). Returning full text doesn't change what it can read, only how much; left to P3.5.

## 11. Decisions (Glyn, 2026-10-22, on approving this plan)
1. **`freeform` is removed; `keep_text` is the default** for `update_block_in_page_area` and `batch_update_page_content`. A user-requested rewrite still works through an explicit mode where the model **names the text it replaces**: `scoped_text` takes `textFields` (as today) **and** `replacedText`, a list of the stored text it replaces. Every stored text segment of a listed field must still be in the new value unless it is inside one of the `replacedText` strings, and each `replacedText` string must be found in the stored text (normalised), or the call is refused. Fields not listed stay identical, as today. Tests: "rewrite this paragraph" succeeds with `scoped_text` naming the paragraph, and the same change without naming it is refused.
2. **Fields left out are kept.** An explicit empty value still clears a field; tested.
3. **Container and layout blocks are counted in BF-5** (R11 for `get_page_blocks`, `get_page`, `get_current_page_context`). **List caps become BF-6** in DELIVERY_PLAN (this `PLAN:` commit).
4. **The page summary's shortened note (R10) is in BF-5, in its own commit,** with `PromptBaselineTest` updated to show only that difference.
5. **Tool baseline:** a per-tool list of deliberate changes in `ToolBaseline`.
6. **The shortened-source refusal stays.**
7. **A reorder passes `keep_text`, but the result reports that the order changed** (`orderChanged: true`). Strict order checking goes to P3.3 (noted in the PROGRESS entry's proposed plan changes).
8. **Scenarios** as in §7 (the decision said §9; the scenario list is §7), cap $3.50. The bar: 05's `all_text_unchanged` in 5 of 5 runs, with no regressions.
