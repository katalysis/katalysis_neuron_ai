# BF-2 plan: global areas through an ordinary page; the pageName lookup in set_page_attribute_value

Status: **approved (Glyn, 2026-10-21); decisions in §11** (revised for Glyn's clarification: stack and global-area editing is the goal, and BF-1's refusal of stacks is a temporary safe default). Significant task: it changes write tools and a safety rule (B-WRT-5). Branch `task/BF-2-global-areas-pagename` from `development`, PR into `development`, then a sync PR into `integration/neuron4` (Phase 2 sync rule). Before P2.3. **Stack writes are not enabled in BF-2.**

## 1. Goal
Close the gap where the block tools reach a stack through a page's global area, past BF-1's refusal (until stack editing is enabled as its own task). Make `delete_block_from_page_area` report truthfully. Make `set_page_attribute_value` find a page by name, case-insensitively, without a SQL error.

## 2. Findings (investigated 2026-10-21 on `development`, Concrete 9.5.5)

### 2.1 How Concrete versions stacks when an editor changes a global area
Read from the core controllers for edit mode (`backend/user_interface/block.php`, `dialog/page/add_block.php`, `backend/page/arrange_blocks.php`) and from `Version::approve()` / `discard()`:
- **Editing, adding or moving a block in a global area in edit mode** gets the stack from the area's handle and calls `getVersionToModify()` on it:
  - if the stack's newest version is a new, unapproved one, that version is reused, whoever made it;
  - otherwise it is cloned into a **new unapproved stack version**.

  The page's own version to modify is then **related** to it (`relateVersionEdits`, table `CollectionVersionRelatedEdits`). Nothing is live yet.
- **Approval comes through the page:** when that page version is approved (Publish in the toolbar, or Page Versions), `Version::approve()` also approves every related stack version **for which the approver has "Approve page versions" on the stack**. Otherwise the stack version stays unapproved. The change then shows on **every page that displays the global area**. Discarding the page draft deletes the related stack versions, with the same permission check.
- **Through the dashboard:** Stacks & Global Areas also edits a new unapproved stack version, and "Approve changes" (`approve_stack`, through the stack approval workflow, needing "Approve page versions" on the stack) publishes it.
- No block type here has `btIncludeAll` (the one path in `AddBlockToPageCommandHandler` that writes without a new version): the query returns none.

### 2.2 What our block tools do, pointed at a global area through a page (throwaway check)
By reading the code, then a throwaway check (`c5:exec`, as the superuser). For each tool, the check made a new global area (a stack with an approved content block), plus pages A and B that show it. It ran the tool on page A, then approved page A's draft. Everything was deleted afterwards. The probe left 5 `Stacks` rows and 10 `PageSummaryTemplates` rows (deleting a stack's page doesn't remove its `Stacks` row); I removed them, checking each by name and ID. DB backup first: `~/Herd/backups/prodb_before_BF-2_20261021_222308.sql`.

Our tools use the same core paths (`GetBlockToEditTrait`, `AddBlockToPageCommand`), so they behave like edit mode:

| Tool (on page A, global area) | Reported | Stack right after | After page A's draft is approved |
|---|---|---|---|
| `update_block_in_page_area` | success | **new unapproved stack version** with the change, related to page A's draft; live unchanged | the stack version goes live, so the change shows **on every page** |
| `add_block_to_page_area` | success | new unapproved stack version with the block | live, on every page |
| `move_block_between_areas` (global → Main) | success | new unapproved stack version **without the block**; the block is copied into page A's draft | the global area is empty on every page |
| `delete_block_from_page_area` | **`deleted: true`, but nothing was deleted** (the command looked in page A's draft) | unchanged | unchanged; page A was left with an empty draft. **A false success report.** |
| `batch_update_page_content` | refused ("Block ID remapping failed") | unchanged | unchanged, but **page A was left with an empty draft** |
| `batch_update_content_headings` | not run; by reading, it lists only page A's own blocks, so it never sees a global area's | | |

**Pointed at a stack directly** (`pageID` = the stack's page): today BF-1 refuses it, since a stack is a system page. Without that refusal, `PageDraft::of()` uses `getVersionToModify()`, so the edit would land in an unapproved stack version with no related page version, live only after someone approves it in the dashboard.

So **no path writes a stack live.** Every path makes or reuses an unapproved stack version, exactly as edit mode does. The problems for us:
1. It goes past BF-1's stack refusal (a temporary safe default).
2. The assistant says nothing about the change appearing on every page.
3. It may land in someone else's pending stack version.
4. `delete` reports a success it didn't have.

### 2.3 Permissions on stacks (this dev site)
- Stacks inherit their permissions from their parent: `/!stacks` and `/!stacks/header-navigation` both inherit from the home page (cID 1).
- Checked with `Checker`:

  | User | View stack | Edit contents | Approve versions | Add blocks to its Main area | View Dashboard → Stacks |
  |---|---|---|---|---|---|
  | superuser | yes | yes | yes | yes | yes |
  | neuron_editor | yes | no | no | no | no |
  | neuron_chat_editor | yes | no | no | no | no |

- Our tools check the **block** (`canEditBlock`, `canDeleteBlock`) or the **page's area** (`canAddBlock` on the page's `GlobalArea` object), never the stack page's own permissions. For the editors, the stack block's `canEditBlock` is false. I haven't checked `canAddBlock` on the page's global area object for an editor.
- Global areas appear on 20 pages here, none under `/neuron-test`.

### 2.4 `set_page_attribute_value`, `pageName`: the case-insensitive fallback always fails
- Same code in `PageAttributesToolkit` and `AttributesToolkit`.
- After `filterByName($pageName)` (a `LIKE %name%` match, not exact, despite the comment), the next steps call `$pl->getQueryObject()->where('lower(cName) = ?', [...])` and `where('lower(cName) LIKE ?', [...])`. Doctrine's `where()` takes predicates, so the array becomes `(Array)` in the SQL, and the query fails with "Invalid parameter number".
- `where()` would also **replace** `PageList`'s own conditions (active, approved, not a system page, site tree), and `cName` isn't in that query (the name is `cv.cvName`).
- So any name that isn't a substring match throws a SQL error instead of "No page found". And because the first step is a substring match, an exact name can be refused as ambiguous.

## 3. Changes

### 3.1 Global areas refused until stack editing is enabled (the BF-1 stack refusal, applied through pages)
- `SystemPageGuard::refuseGlobalArea(Page $page, string $areaHandle)`: refuses when `Area::get($page, $areaHandle)` is a global area. The message:
  - the area is a global area, shown on every page that displays it;
  - the assistant can't edit global areas or stacks yet;
  - nothing was changed;
  - the user can edit it in edit mode or under Dashboard → Stacks & Global Areas.
- Called **before any draft is made**, in:
  - `update_block_in_page_area` and `delete_block_from_page_area` (`areaHandle`)
  - `add_block_to_page_area` (`areaHandle`)
  - `move_block_between_areas` (`fromAreaHandle` and `toAreaHandle`)
  - `batch_update_page_content` (each entry's `areaHandle`, before the draft; the whole call is refused)
- `batch_update_content_headings`: no change. A test confirms it never touches a global area.
- `PagesToolkit` overrides the trait's `getBlockToWorkWith()` and `getBlockToEdit()` to refuse a global area too, so a future tool that uses them can't skip it.
- **One switch for the future:** the stack refusal (BF-1's `isSystemPage()` check on a stack page) and the global-area refusal both go through one method, `SystemPageGuard::stackEditingEnabled()`, which returns `false` in BF-2. The stack-editing task (§9) turns it on, with its conditions. System single pages (dashboard, account, login and the like) don't depend on it: refused permanently.
  - To tell them apart, `SystemPageGuard` treats a page as a stack when `Stack::getByID()` finds it (decision 11.4; it checks the page type, so multilingual copies are covered). Page type defaults (master collections) are refused permanently (§12).

### 3.2 `delete_block_from_page_area` reports only what happened
After the delete command, check that the block is gone from the draft. If not, return an error ("Block N was not deleted from page P") instead of `deleted: true`. **Recommended in BF-2** (small, red first).

### 3.3 `pageName` lookup (both toolkits)
One shared helper, `src/Tools/Utilities/PageByName.php`, used by both toolkits. Each step keeps `PageList`'s own conditions, using `filterByName()` or `andWhere()` with a bound parameter on `cv.cvName`:
1. exact name;
2. exact name, ignoring case;
3. contains the text, ignoring case.

The first step that finds pages wins. Several pages give today's "Multiple pages found …". None gives "No page found matching name …". System pages stay out (`PageList`), and the user's view permission still applies (no `ignorePermissions()`, as today).
- **Deliberate behaviour change:** an exact name now wins over pages that merely contain it.

### 3.4 Not in BF-2
- `batch_update_page_content` leaving an empty draft after a remap failure is a general error-path gap; with 3.1 the global-area case is refused before the draft. **Proposed as a separate small task.**
- Enabling stack or global-area writes (§9).

## 4. Tests (red first)
- **`tests/Integration/GlobalAreaWriteRefusalTest.php`** (new), as the superuser, on a throwaway global area and pages from a new `tests/Support/GlobalAreaFixture.php`. The fixture deletes the pages, the stack page, its `Stacks` row, the pages' `Areas` rows and any `PageSummaryTemplates` rows; the probe showed `Page::delete()` leaves those behind, and the P1.12 leak check proves the fixture doesn't.
  - One case per tool and area input. Each asserts the refusal message, that the stack has no new version and its live version is unchanged, that page A has no draft, and that there are no related-edit rows.
  - `batch_update_content_headings` leaves the global area alone.
  - Coverage: every write tool with an area input is in the list.
  - The trait overrides refuse a global area.
  - The same cases as `neuron_editor`, to record whether permissions alone would have stopped it. That answers the open point in 2.3.
- **`SystemPageWriteRefusalTest`** (BF-1) gains a case: a stack page is refused with the "not yet" message, and a dashboard page with the permanent one. `stackEditingEnabled()` is false.
- **Delete truthfulness:** a delete whose block isn't in the draft reports an error, not `deleted: true`. Red today.
- **`PageByNameTest`** (integration):
  - exact name;
  - case-insensitive exact;
  - contains the text;
  - several matches → "Multiple pages found";
  - none → "No page found" (red today: a SQL error);
  - a system page's name → not found;
  - a page the user can't view → not found.
- **`set_page_attribute_value` with a lower-case `pageName`,** in both toolkits, sets the attribute in a draft. Red today.

## 5. Scenarios (regression, after implementation)
- `10-update-page-attribute` ×3: about $0.15.
- `07-move-content-between-block-types` ×3: about $0.30.

That's about $0.45 at the runner's estimate, compared with the Phase 1 exit run. No new scenario: no `/neuron-test` page shows a global area.

## 6. Files
- **New:** `src/Tools/Utilities/PageByName.php`, `tests/Integration/GlobalAreaWriteRefusalTest.php`, `tests/Support/GlobalAreaFixture.php`, `tests/Integration/PageByNameTest.php`, `documentation/plans/BF-2.md`.
- **Changed:**
  - `src/Tools/Utilities/SystemPageGuard.php`
  - `src/Tools/PagesToolkit.php` (the block tools' checks, the two trait overrides, the delete check)
  - `src/Tools/PageAttributesToolkit.php`, `src/Tools/AttributesToolkit.php` (the `pageName` lookup)
  - `tests/Integration/SystemPageWriteRefusalTest.php`
  - `documentation/BEHAVIOUR_SPEC.md`, `documentation/DELIVERY_PLAN.md`, `documentation/PROGRESS.md`

## 7. Manual checks (Glyn, browser, as admin, on a page that shows a global area such as Header Navigation)
- "Change the text in the header navigation" → refused: a global area, not editable by the assistant yet. Afterwards, the page has no new draft in Page Versions, and the stack has no new version in Dashboard → Stacks & Global Areas.
- "Set the meta title of the page called 'headings' to Test" (lower case) → set in a draft of Headings.

## 8. Proposed wording

### 8.1 BEHAVIOUR_SPEC B-WRT-5 (in the `PLAN:` commit)
> | B-WRT-5 | **Writes to system pages are refused.** (a) **System single pages** (the dashboard, account and login pages, error pages, the trash and drafts containers: pages Concrete marks as system pages that are not stacks) are refused **permanently**, even when Concrete's permissions would allow the write. (b) **Stacks and global areas** (whose content lives on a stack) are refused **until stack editing is enabled** (task P3.6a). Then they are edited as an editor would edit them: only in an unapproved stack version, only with the user's own Concrete permissions on the stack, and with a notice that the change will appear on every page that shows that area. The assistant may read all of them and use them as the current page. The refusal says why, so the assistant can tell the user. | **Partly (BF-1, BF-2).** (a) and the direct-stack part of (b) since BF-1; global areas reached through a page since BF-2. Stack editing: not enabled → P3.6a. |

### 8.2 DELIVERY_PLAN BF-2 entry
> - [ ] BF-2 **Global areas through an ordinary page; `set_page_attribute_value` finds pages by name** (significant; its own PR into `development`, then synced into `integration/neuron4`; before P2.3). Found after BF-1: the block tools reached a global area's stack through the page that shows it, in an unapproved stack version tied to the page's draft, whose approval publishes the change on every page. `delete_block_from_page_area` reported deleting a block it hadn't. The `pageName` lookup's case-insensitive steps always failed with a SQL error. Global areas are refused until P3.6a enables stack editing. Plan: [plans/BF-2.md](plans/BF-2.md).

## 9. Proposed new task: P3.6a, stack and global-area editing
**Recommendation: in Phase 3, right after P3.6 (approval), with P3.5's permission work done first.** Not earlier:
- P2 is the Neuron 4 move, and P3.3 converts the block tools into `Tool` subclasses, so stack editing written now would be rewritten twice.
- The notice that a change affects every page fits naturally into P3.6's approval preview.
- Until then, BF-1 and BF-2's refusals are the safe default.

Proposed entry:
> - P3.6a **Stack and global-area editing.** The assistant edits, adds and moves content in stacks and global areas the way an editor does in edit mode or under Dashboard → Stacks & Global Areas:
>   - **draft only:** changes go into an unapproved stack version (`getVersionToModify()`), never live. Through a page, the stack version is related to the page's draft, as Concrete does, so publishing the page publishes it (Concrete approves it only for someone with "Approve page versions" on the stack). Directly, it's approved under Stacks & Global Areas. The assistant never approves either.
>   - **the user's permissions:** `canEditPageContents` on the stack page and the block or area checks on the stack's own area, not only the page's. A user who can't edit the stack is refused with the reason.
>   - **a notice:** before the change (P3.6 approval preview) and in the report, the assistant says it will appear on every page that shows the area, with the count and a few example pages, and that it reaches the site when the page (or the stack) is published.
>   - **a pending stack version someone else started:** say so, rather than silently adding to it, and ask.
>   - turn on `SystemPageGuard::stackEditingEnabled()`, keep system single pages refused, and update B-WRT-5 to "met".
>   - tests per tool on a throwaway global area (BF-2's fixture); scenarios: edit a global area through a page (draft, notice, page B unchanged until the page draft is approved), and an editor without stack permission refused.
>   - also: `GlobalAreaWriteRefusalTest`'s refusal cases become checks that a stack version is made and a notice given.

## 10. Questions
1. §9 timing and scope: P3.6a after P3.6, as proposed?
2. 3.2 (delete truthfulness) in BF-2? Recommended yes.
3. 3.4 (batch leaves an empty draft on a remap failure) as a separate small task? Recommended.
4. 3.1 tells a stack from a system single page by `Stack::getByID()` or a `/!stacks` path: acceptable, or do you know of other stack locations on client sites (stacks in folders still live under `/!stacks`)?

## 11. Decisions (Glyn, 2026-10-21, on approving this plan)
1. **P3.6a** comes right after P3.6, with P3.5 first. Added to its scope: **never add to another user's pending stack version without warning; refuse or warn, with a test.**
2. **The `delete_block_from_page_area` fix (3.2) is in BF-2.**
3. **BF-3, a separate small task:** find every write tool that leaves an empty draft when it fails (not only batch update) and propose a fix. Added to DELIVERY_PLAN in this `PLAN:` commit.
4. **Detection:** a stack is whatever `Stack::getByID()` finds (it checks the page type, so multilingual stack copies are covered), not a `/!stacks` path. Also confirm that page type defaults (master collections) are refused by the system-page check, and add a test.
5. **Upstream:** the leftover rows after `Page::delete()` go into `documentation/upstream/` as another draft core issue.
6. **Scenarios** 10 ×3 and 07 ×3 (about $0.45) approved after implementation. Then the PR into `development` and stop; after Glyn merges, the sync PR into `integration/neuron4` as for BF-1.

## 12. Found while confirming decision 11.4 (before implementation)
- **Page type defaults are not refused today.** They are master collections (`cIsTemplate = 1`, `isMasterCollection()` true), and Concrete doesn't mark them as system pages (`cIsSystemPage = 0` for all five on this site). So BF-1's check lets them through.
- Worse, `getVersionToModify()` returns a master collection **itself**, not a new version, so a write to page type defaults is **live** (and defaults blocks can be set to apply to every page of that type).
- **Fix in BF-2:** `SystemPageGuard::refuse()` also refuses a master collection, **permanently**, with its own message. Tested on a throwaway page flagged as a master collection for the test, so the red run can't change real defaults.
- **Leftover rows:**
  - `PageSummaryTemplates`: confirmed by a repro on 9.5.5. Approving a page version adds the page's summary-template row, and `Page::delete()` leaves it.
  - `Stacks`: the row is left only when a stack's page is deleted with `Page::delete()`. `Stack::delete()` removes it (checked), so that one is our probe's misuse, noted in the draft as a lesser point.

  The draft core issue covers `PageSummaryTemplates`. The test fixture deletes stacks with `Stack::delete()` and removes `PageSummaryTemplates` rows itself.
