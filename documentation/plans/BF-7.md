# BF-7 plan: an AI edit behaves like a manual edit

Status: **approved (Glyn, 2026-10-22); decisions in §11, which override the sections above where they differ.** Significant task: it touches the write tools (a new refusal in every page write tool, the version state in their results), the agent (a tool-call listener), the chat response and the panel. Branch `task/BF-7-ai-edit-like-manual` from `development`, PR into `development`.

## 1. Goal
An assistant edit works like a manual edit in Concrete: it lands in the same unpublished version that edit mode shows, the editor sees it at once (the panel reloads the page), it goes live only when someone clicks Publish, and it is refused while another user has the page in edit mode.

## 2. Probe results (done first, before any design)
**Method.** Dev site, after a backup (`prodb_before_BF-7_20261022_165706.sql`). A `c5:exec` script in the scratchpad (not committed) replays, in one process, what each step does in Concrete 9.5.5, on throwaway pages under `/neuron-test` with two Content blocks and one approved version (deleted at the end: cIDs 19319–19326). Each step starts with `CacheLocal::flush()`, as a new request would.
- **Edit mode:** the `Pages` checkout columns that `User::loadCollectionEdit()` writes (the toolbar's Edit button), without its session steps.
- **A hand edit:** the block edit dialog's `submit()`: `GetBlockToEditTrait::getBlockToEdit()`, then `$b->update()`.
- **The dialog opening a block:** its `on_start()`: `Page::getByID($cID)` (RECENT), then `Block::getByID($bID, $page, $area)` with the ID the edit-mode page holds.
- **Publish:** the check-in panel's `submit()` with `action=publish`: `CollectionVersion::get($c, 'RECENT')`, an `ApprovePageRequest` for it, check-in, `trigger()`.
- **The assistant:** the real `update_block_in_page_area` tool (`formatting_only`, `<p>` → `<h2>`), acting as admin.

These are server-side replays, not browser runs: the browser checks in §7.3 confirm them.

| Probe | What happened |
|---|---|
| **A1** Admin in edit mode, no hand edit yet; the assistant edits block 1; admin publishes | The tool made v2 (unapproved, `cvIsNew=1`) and copied block 1 into it with a **new ID** (b28791 → b28793); block 2 kept its ID (shared with v1). The checkout was untouched. Live (v1) unchanged. **The block dialog for the old ID b28791 fails: Concrete throws "Access Denied"** (the block isn't in RECENT any more); b28792 opens. Publish approved v2: **the assistant's edit went live with the editor's Publish.** |
| **A2** Admin edits block 1 by hand, the assistant edits block 2, then the assistant edits block 1 again; publish | The hand edit made v2 (b28794 → b28796). The assistant **reused v2** (no second draft) and copied block 2 to a new ID (b28795 → b28797); the dialog for b28795 fails. Its second edit, on the hand-edited block, **kept the ID** b28796 (already v2's own). Publish: both hand and assistant edits live, in one version. |
| **B** `neuron_editor` in edit mode with a hand edit; the assistant, as admin, edits block 2; `neuron_editor` publishes | Concrete itself says the page is checked out by `neuron_editor` (`isCheckedOut()` true). **The tool wrote anyway, into `neuron_editor`'s v2**, and copied block 2 to a new ID, so `neuron_editor`'s editor can no longer open block 2 ("Access Denied"). **`neuron_editor`'s Publish then published the assistant's edit**, which they never saw. |
| **C2** Admin edits by hand, clicks Save Changes (not Publish), then the assistant edits | Save Changes clears v2's "new" flag, so the assistant made v3 from v2 (hand edit kept), as a second hand edit would. Live unchanged. |

**What the probes show:**
1. **The version is already shared, as BF-5's investigation said.** `PageDraft::of()` follows the same rule as edit mode (`getVersionToModify()`: reuse the newest version while it is "new", else clone it), and the tool's block path is Concrete's own `GetBlockToEditTrait`. Publish publishes the newest version, assistant edits included. No change is needed to how drafts are made.
2. **The gap is the editor's page.** It still holds the IDs it rendered. The first change to a block in a draft gives it a new ID (Concrete copies a block shared with the approved version), so until the page reloads, opening that block fails with "Access Denied", and saving an editor already open on it fails the same way (the save request looks the block up again). A reload shows RECENT with the new IDs (`ResponseFactory` shows editors the newest version).
3. **Another user's edit mode is not respected today** (probe B). Their Publish would publish the assistant's changes unseen.

## 3. The edit-mode check
- **New `src/Tools/Utilities/EditModeGuard.php`**, one shared check, next to `SystemPageGuard`: `EditModeGuard::refuse(Page $page)`.
- **The rule is Concrete's own** (`page_controls_footer.php`: `isCheckedOut() && !isCheckedOutByMe()`, which shows "Editing Unavailable. *name* is currently editing this page."):
  - refused when the page is checked out by a user other than the one the tool runs as, and the checkout is within `CHECKOUT_TIMEOUT` (300 s since `cCheckedOutDatetimeLastEdit`, which edit mode's heartbeat keeps fresh)
  - allowed for the same user, in any tab or on any page
  - a checkout with no last-edit time counts as current, as in Concrete
- **Read-only.** It reads the `Pages` row itself rather than calling `Page::isCheckedOut()`, which force-checks-in a timed-out page as a side effect and caches its answer on the page object (the page objects are cached for the request, `PageDraft`). It compares `cCheckedOutUID` with the acting user (`new User()`, as `guarded()` does, so tests' `setCustomRequestUser()` applies).
- **Message:** "*name* is editing this page in edit mode. Nothing was changed. Tell the user to try again when *name* has published or left edit mode." *name* is the user name, as Concrete's own notice shows it.
- **Where:** every write tool with a page input calls it right after `SystemPageGuard::refuse()`, before anything that could make a draft. That is every case in `SystemPageWriteRefusalTest::writeToolProvider()` except `create_page`'s parent (adding a child doesn't change the parent's version), with `move_page`'s page but not its new parent. The disabled tools get it too, as for the system-page refusal.
- **Superuser:** refused too, as Concrete refuses edit mode to them. Concrete offers them "Force Exit Edit Mode"; the assistant doesn't.

## 4. Per-turn tracking (brought forward from P3.6)
- **New `src/Chat/TurnChanges.php`**: a listener on Neuron's `ToolCalled`, held by the agent and subscribed in its constructor (`subscribe(ToolCalled::class, ...)`). This is P2.7 §5's design; `ToolCalled` fires in `ToolNode::executeSingleTool()`'s `finally`, so also after a tool error.
- **It records a call only when** the tool is a write (`ToolKind::isWrite()`) and succeeded (`ToolKind::succeeded()`, our envelope and v4's error shape).
- **Per page:**
  - `pageID`, `path` and `name`
  - the tools that changed it
  - the block IDs written, and `replacedBlockIDs` (old → new) where a copy gave a block a new ID, from the input `blockID` and the result's `blockID`

  The page ID comes from the tool's result where the tool reports one, else from its `pageID` input. A test per write tool checks the right page is named.
- **Duplicates:** keyed by tool call ID. Neuron may emit the event again on a retry (`src/Observability/AGENTS.md:28`). If a call has no ID, the key is the tool name plus its inputs.
- **Redaction:** every string it records (path, name, tool name) goes through `SecretRedactor::redact()`. It logs nothing itself.
- **Turn scope:** `startTurn()` resets it. It survives a failed turn: `handle()`'s `catch` can still read it.
- **Not in BF-7:** the failed turn's stored reply naming the tools that ran stays P3.6. `ERROR_REPLY` is unchanged: the scenario runner recognises it.

## 5. Chat response and panel
- **Response:** `send_message` adds `changes`, the list from §4 (an empty list when nothing changed), for a successful and a failed turn alike. Nothing else in the response changes.
- **Panel (`js/chat-panel.js`):** after a reply, if any change's `pageID` equals `getCurrentPageId()` (CCM_CID first, as BF-1):
  - **No block editor open:**
    1. Show a one-line notice under the reply: "Updated: reloading to show the change."
    2. Put the chat input's text in `sessionStorage`, so a half-typed message isn't lost.
    3. Reload after about 1.5 s with `window.location.reload()`. In edit mode the page stays checked out, and reloads in edit mode with the new IDs.
    4. After the reload, the chat restores from the server as it does now. The panel then shows "Reloaded to show the assistant's change." once, from a `sessionStorage` flag, and puts the input text back.
  - **A block editor open:** no reload. The notice says: "The assistant changed this page. Save or cancel your edit, then reload the page to see the change." It has a **Reload now** button.
    - Probe A1 shows a save can fail if the open block is one the assistant just changed. The notice adds: "If saving fails, cancel, reload and edit again."
- **How an open block editor is detected:** read from the page when the reply arrives, not from events, so an editor opened before the panel loaded counts too. The selectors come from Concrete 9.5.5's `concrete/js/cms.js`, which this check reads:
  - **inline editing** (the Content block and others edited in place, and blocks being added inline): an element with `.ccm-block-edit-inline-active` or `.ccm-area-edit-inline-active`. `cms.js` adds these classes when an inline edit or add starts, and `destroyInlineEditModeToolbars()` removes them.
  - **a block dialog** (block edit, design, permissions, add-block form): a visible jQuery UI dialog, `.ui-dialog` that is displayed. This catches every Concrete dialog, which is safe: it only puts off the reload.
  - **a detail panel with a form** (attributes, SEO, location, the Publish panel's version comment): a visible `.ccm-panel-detail`. Plain menu panels (dashboard, sitemap) don't count. The chat panel itself uses neither class.

  The browser checks confirm the selectors (§7.3). If one doesn't match, the fallback is the notice without a reload, never a reload over an open editor.
- **Other pages changed:** no reload. The reply already says what changed.

## 6. Wording: "on the page now, not live until you click Publish"
- **Concrete 9.5.5's labels, checked in the source:**
  - In edit mode, the check-in panel's button is **"Publish Changes"** (`Composer::getPublishButtonTitle()`). It reads "Publish Page" for a composer page draft, and "Submit to Workflow" when a workflow applies.
  - Out of edit mode, an unapproved newest version shows the notice "Page is Pending Approval." with an **"Approve Version"** button (`page_controls_footer.php`).
  - So "click Publish" alone doesn't match either label.
- **By rule 5, the wording goes in the tool result, not the prompt.** A shared helper, `src/Tools/Utilities/DraftState.php`, gives every tool that writes to a page draft a `versionState` in its result:
  - `status: "draft"`
  - `versionID`
  - `note`: "On the page now (version *N*, unpublished), not live until you click Publish Changes in edit mode, or Approve Version on the page. To undo it, ask me to change it back, or restore the previous version from Page Versions."
- **The tools:** the block tools, the two batch tools, `update_page`, `set_page_attribute_value` (both toolkits) and `create_page_draft`.
  - `create_page` gets its own note: the new page isn't published, and visitors get a 404 until someone approves it (P1.13). The label it shows is a browser check.
- The note matches scenarios 05's and 10's existing secondary reply checks: "unpublished" and "previous version". **No scenario expectation changes.** Whether the model passes it on is what the scenario runs measure.
- **No prompt change:** `PromptBaselineTest` is unchanged. Tool definitions don't change either, so `ToolBaseline` is unchanged.
- **The P3.7 item is taken over** (DELIVERY_PLAN already says so). BEHAVIOUR_SPEC B-WRT-3 and B-REP-1 (c) get a status update.

## 7. Tests
### 7.1 PHPUnit (red first; fast tier after each change, full before the PR)
- **New `tests/Integration/EditModeWriteRefusalTest.php`**, modelled on `SystemPageWriteRefusalTest`, on throwaway pages:
  - **another user's checkout:** each tool and page input is refused with the message, and the page's versions and blocks are unchanged (one case per tool)
  - **the same user's checkout:** the write is allowed
  - **a checkout older than `CHECKOUT_TIMEOUT`:** allowed, and the row is left as it was (read-only)
  - **registry level:** every write tool with a page input is either in the provider or in an explicit exempt list with its reason (`create_page`'s parent, `move_page`'s new parent)
- **New `tests/Integration/TurnChangesTest.php`**: a real agent turn with `FakeAIProvider` (no AI call) calling real write tools on a throwaway page:
  - the changed page and blocks, including old → new block IDs after a copy
  - a read tool and a refused write are not recorded
  - a `ToolCalled` delivered twice for one call ID is recorded once
  - the record survives a turn that fails after a tool round
  - a page name holding a configured key comes back redacted
- **`ChatControllerTest`:** `changes` is in `send_message`'s response, empty for a turn with no writes, and present after a failed turn that wrote.
- **New `tests/Integration/DraftStateResultTest.php`:** every draft-writing tool's result carries `versionState` with the right version ID, plus a registry check that every write tool with a page input is covered or exempt.
- **Existing tests:** none should need changes. If one does, it gets its own commit with the reason.
- **Panel:** no JS test setup (as BF-4 noted). Covered by the browser checks.

### 7.2 Scenarios (cost)
- **05 ×3 and 10 ×3, after the change**, compared with the recorded baseline and P2.9.
  - Estimate: 05 about $0.08 a run (BF-5's 05 ×5 was $0.40), 10 about $0.04 a run.
  - So about **$0.36**, cap **$1.00**.
- **Optional, before the change (your call):** 05 ×3 and 10 ×3 on `development`, about $0.36 more. P2.9's recorded runs already give a comparison.
- **Merge rule:** no pass → fail or partial → fail. The goal is for the secondary reply check (version state, undo) to pass more often. It is the model's choice to pass on the note, so it isn't guaranteed.
- **Question:** every write tool's result changes. Scenarios 07, 08 and 17 also write, so I'd add 07 ×3 and 17 ×3 (about $0.40) as a regression check. Not run unless you say so.

### 7.3 Browser checks for Glyn (about 10 minutes, after a backup)
1. **Out of edit mode**, on `/neuron-test/headings`, ask for a heading change.
   - The panel shows "Updated: reloading…", the page reloads with the change, and the chat is kept, with "Reloaded to show…".
   - The toolbar shows "Page is Pending Approval" and Approve Version.
   - Logged out (or in a private window), the live page is unchanged.
2. **In edit mode, no editor open**, same request.
   - The page reloads, still in edit mode, with the change.
   - Open the changed block: it opens (new ID).
   - Click Publish Changes: the change is live.
3. **In edit mode with an inline editor open** (click into a Content block, don't save), ask for a change to another block.
   - No reload, and the "save or cancel" notice with Reload now.
   - Save, then Reload now: both changes are there.
4. **The same, with a block dialog open** (for example a non-Content block's edit dialog, or Design), and with the attributes panel open.
5. **Another user editing:** log in as `neuron_editor` in another browser, enter edit mode on the page, then ask the assistant (as admin) for a change.
   - The reply says `neuron_editor` is editing and nothing was changed.
   - Page Versions shows no new version.
6. **The same user in two tabs:** edit mode in tab 1, ask in tab 2. It's allowed. Reload tab 1 to see it.
7. **The label after `create_page`:** open the new page and note the button that publishes it.

## 8. Files
| File | Change |
|---|---|
| `documentation/plans/BF-7.md` | this plan (`PLAN:` commit) |
| `documentation/DELIVERY_PLAN.md` | `PLAN:` commit: factual fix to BF-9's `ConcreteCmsAgent.php:657` → `:685` (`storeFailedTurn()` has moved). Last commit: the BF-7 tick |
| `src/Tools/Utilities/EditModeGuard.php` | new (§3) |
| `src/Tools/Utilities/DraftState.php` | new (§6) |
| `src/Chat/TurnChanges.php` | new (§4) |
| `src/Tools/PagesToolkit.php`, `PageAttributesToolkit.php`, `AttributesToolkit.php`, `VersionsToolkit.php` | the edit-mode check after each `SystemPageGuard::refuse()`; `versionState` in draft-write results |
| `src/ConcreteCmsAgent.php` | subscribe `TurnChanges`; `getTurnChanges()`; reset in `startTurn()` |
| `controllers/chat.php` | `changes` in `send_message`'s response |
| `js/chat-panel.js`, `css/chat-panel.css` | reload, notices, editor detection (§5) |
| `tests/Integration/EditModeWriteRefusalTest.php`, `TurnChangesTest.php`, `DraftStateResultTest.php` | new |
| `tests/Integration/ChatControllerTest.php` | `changes` cases |
| `tests/Unit/SourceFilesLoadTest.php` | the new files, if it lists them |
| `documentation/BEHAVIOUR_SPEC.md` | B-WRT-3, B-REP-1 (c) status; the edit-mode rule (a new B-WRT item, wording for you to approve) |
| `documentation/PROGRESS.md` | entry, current position (last commit) |

## 9. Out of scope
- How drafts are made (`PageDraft`): the probes show it already matches edit mode.
- P3.6:
  - approval
  - the failed turn's stored reply naming the tools that ran
  - showing someone else's edits already in the draft (P1.8a note)
- P3.3: stale-ID handling inside the model's own turn. The tool result already returns the new block ID (probe: `blockID` 28793 after editing b28791).
- Stacks and global areas (P3.6a; still refused).
- Live updates without a reload (Concrete's Mercure events), and a block-level lock on the block an editor has open (question 3).
- BF-3 (empty drafts after a failed write).

## 10. Risks and questions
1. **The wording (§6):** "On the page now (version *N*, unpublished), not live until you click Publish Changes in edit mode, or Approve Version on the page." Is that right, or shorter? It names both labels, because Concrete shows a different one in and out of edit mode.
2. **The undo line** names Page Versions. In edit mode, the check-in panel's "Discard Changes" would also throw away the editor's own edits in the same version, so I don't suggest it. Agree?
3. **An editor open on the block the assistant changes:**
   - The editor's save fails ("Access Denied", probe A1), and their unsaved text in that editor is lost.
   - The notice warns, but doesn't prevent it.
   - A stronger option for later: the panel sends the IDs of blocks with an editor open, and the tools refuse those blocks. Is the notice enough for BF-7?
4. **The superuser is refused** while someone else edits, as Concrete refuses edit mode. Agree?
5. **Reload timing:** about 1.5 s after the reply, so the notice can be read. Or reload at once?
6. **Scenarios:** confirm 05 ×3 and 10 ×3 after the change (about $0.36, cap $1.00). Should I also run 07 ×3 and 17 ×3 (about $0.40), and a "before" run (about $0.36)?
7. **Tracking scope:** only successful page writes are recorded. File and topic writes (not page versions) aren't in `changes`. Fine for BF-7?

## 11. Decisions (Glyn, approving the plan, 2026-10-22)
1. **Wording.** The tool result carries the full facts: version *N*, unpublished, live through "Publish Changes" in edit mode or "Approve Version" outside it, undo through Page Versions. The reply should be one short natural line, for example "Done. You can see it on the page now; it goes live when you publish." That guidance goes in the tool result's wording (or a tool description), not in a new prompt rule.
   - Consequence, noted for review: scenarios 05's and 10's secondary reply checks ask for "draft/unpublished/…" and "undo/…/previous version". A reply in the short form above matches neither, so those checks may stay partial. Not a regression by the merge rule; the regexes are not changed in BF-7 (that needs its own commit and your approval).
2. **Undo through Page Versions:** agreed.
3. **An editor open on the target block:** if the panel can reliably tell which block an editor is open on, it sends those block IDs with the message and the write tools refuse them, with a test. If it can't, keep the notice and add the prevention as a follow-up BF task. The PR says which.
4. **Superuser refused** while another user is editing: agreed.
5. **Reload after about 1.5 s:** fine.
6. **Scenarios:** 05, 10, 07 and 17, ×3 each, after the change only. Cap $1.50.
7. **Only page writes in `changes`:** fine.

## 12. Implementation notes
- **Open editor (decision 3): prevention built, no follow-up task.** The panel can name the block from Concrete 9.5.5's own markup: an inline edit marks the block's element (which carries `data-block-id`) with `.ccm-block-edit-inline-active`, and every block edit form (`form#ccm-block-form`, in a dialog or inline) posts to an action that Concrete's block controller ends with `&bID=<id>` (`controllers/backend/user_interface/block.php`, `action()`). The panel sends those IDs as `openBlockIDs`; the controller keeps positive integers only (at most 50) and they apply to the page context's page, for that turn only (`EditModeGuard::setOpenBlocks()`, cleared in `handle()`'s `finally`). Refused: `update_block_in_page_area`, `delete_block_from_page_area`, `move_block_between_areas`, `batch_update_page_content` (before any draft); `batch_update_content_headings` picks its own blocks, so it skips an open one and reports it with the failures. Adding a block is not refused (it doesn't touch an open block). The browser checks confirm the selectors.
- **`changes`** holds only results that carry `versionState`, so `create_page_draft` (no content change) and `discard_draft_version` (a Markdown result, no `versionState`) are not in it. The panel doesn't reload for them.
- **`versionState` only when something was written:** the two batch tools add it when at least one block changed. `batch_update_page_content`'s old `note` ("not auto-approved…") is replaced by it.
- **Existing test changed, approved by Glyn during the task:** `OutboundDataTest` (P2.7) asserted an agent has no Neuron listener at all; it now allows exactly the agent's own `TurnChanges` on `ToolCalled`. Its own commit, with the reason.
- **Dev site:** the probes' Publish steps left six workflow rows (`WorkflowProgressHistory` / `WorkflowRequestObjects` 207–212) for the deleted probe pages, and the first red run of `EditModeWriteRefusalTest` left its 50 throwaway pages and one workflow row (213), because its `tearDown()` called the class that didn't exist yet before deleting them. All removed with a one-off script (dry run first); the table counts are back to what they were before the probes. `tearDown()` now deletes the pages first.

## 13. After the scenario run and the browser checks (Glyn, 2026-10-23)
- **Browser checks:**
  - 1–4: work well.
  - 5–6: not run as written, because `neuron_editor` doesn't see the chat. Check 5 needs `neuron_editor` only to hold edit mode on a `/neuron-test` page, where the seed gives it edit rights. `EditModeWriteRefusalTest` covers the refusal.
  - 7: `create_page` makes the page, which offers a modal for approval.
- **Scenario 05:** no re-run. Its failures are the P3.3 headings bug.
- **Scenario 10:** the reply check accepts the agreed short form ("it goes live when you publish"). Undo wording is no longer required, because the tool result holds the undo route. Changed in its own commit, with the reason.
- **Scenario 07:** the check is kept. The suggested reply in the tool results (`DraftState::REPLY`) now says to describe what was actually done (for example, that the original block was kept), then add the one line about publishing. 07 ×3 re-run: 3 of 3 pass (about $0.28; BF-7 total about $1.30, cap $1.50).
- **Fixture drift seen on the way:** before the 07 re-run, `PromptBaselineTest` failed because `/neuron-test/headings` had an `exclude_nav: false` attribute value, most likely saved during browser check 4 (the attributes panel). The runner's fixture reset removed it, and the full suite passed afterwards. It was not a code change.

## 14. Browser check 6 failed: the cause and the fix (2026-10-23)
- **What happened (chat 3693; log 11:15:17–11:15:23):**
  - Admin, in edit mode in tab 1, edited block 31610 by hand at 11:14:56. That made version 2 (unpublished, `cvIsNew`) and copied the block into it as block 31662.
  - In tab 2 the model read the page with `get_page_content` and `get_page_blocks`, both with `version: "active"`, so it saw the published version and block 31610.
  - It called `update_block_in_page_area` with 31610 three times. The write tools edit the newest version, version 2, where 31610 no longer is. Each call got only "Block 31610 not found in area 'Main' on page 21175", so the model guessed at "a lock, permissions, or another concurrent modification".
- **Cause:** block IDs changed by edit mode (the hand edit's copy), together with the model reading the published version. It was not the edit-mode check (the same user passed it, as intended) and not the open-editor check (31610 wasn't sent, and nothing was refused by it).
- **Fix (`BlockNotFound`):** when a block write tool can't find the block in the newest version, it says why and what to use instead:
  - which version the block is in
  - that the newest version is unpublished, and who saved it
  - that a change in edit mode or by the assistant copied the block under a new ID
  - which block the newest version has at the same position (the ID to use)
  - that nothing was changed

  A block in another area is named with its area, and a block that isn't on the page says so. This applies to update, delete and move, and to `batch_update_page_content` before any draft. `StaleBlockIDTest` (red first) reproduces the two-tab case per tool and through the agent: the stale ID first, then the retry with the ID the error gave.
- **Not changed, proposed:** `get_page_content` reads the published version by default ("active"). The write tools edit the newest version, and editors see the newest version on the page. Reading the newest version by default would avoid the mismatch, but it changes a read tool's behaviour, and in this run the model asked for "active" explicitly anyway. For Glyn.
