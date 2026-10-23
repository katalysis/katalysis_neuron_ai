# BF-3 plan: no empty draft is left when a write tool fails

Status: **for review** (Glyn). Base `development`, branch `task/BF-3-no-empty-draft-on-failure`. Significant (Glyn: write tools, drafts), although DELIVERY_PLAN calls it small.

## 1. Goal
A write tool that fails, is refused or changes nothing leaves the page exactly as it found it: no new empty or half-written version, no block copied under a new ID. It never touches a version the editor or an earlier call is working in, and a corrected retry works. `batch_update_page_content` also takes `changeMode` per block.

## 2. Where it can happen today
The model since BF-7: every write goes into the newest version if it is still new (`cvIsNew`), otherwise into a fresh clone (`PageDraft::of()`, Concrete's `getVersionToModify()`). Only the clone case can leave an *empty* draft. In the reuse case the risk is a *partial* write into a version someone else is using, which is out of reach for a clean-up (§4) and is covered by checking first (§3).

The original BF-2 cases (a global area in `batch_update_page_content` and `delete_block_from_page_area`) are already refused before the draft since BF-2 (`GlobalAreaWriteRefusalTest` checks the version count). What is still open, tool by tool (line numbers on `development` at `8d755f5`):

| # | Tool | After the draft is made, what can fail or do nothing | What is left |
|---|---|---|---|
| A1 | `batch_update_page_content` ([PagesToolkit.php:643](../../src/Tools/PagesToolkit.php#L643)) | Every block refused by the text check (wrong `changeMode`, a missing `replacedText`). The block is copied into the draft (`getBlockToEdit`, :689) **before** the text check (:708), so each refused block gets a new ID. **This is scenario 05 runs 3 and 4 in BF-5:** `changeMode` inside the block items was ignored, keep_text refused, and the retry used the old ID. | A new version with the same text, but block IDs changed |
| A2 | same | Snapshot or remap fails (:650, :657); area or block not found in the draft (:677–685) | Empty clone |
| A3 | same | `block->update()` throws for every block | Clone with copied blocks |
| A4 | same, `continueOnError: false` | First block fails after earlier blocks were applied | Partly written version (intended today) |
| B1 | `batch_update_content_headings` (:975) | No content block placements (:1000), no heading matched, or every match refused (open block, text check). Returns a normal result. | Empty clone |
| B2 | same | Copy made (:1114), then `update()` throws | Clone with a copied block |
| C1 | `update_page` (:1945) | Called with nothing to change (only `pageID`, or `attributes: {}`) | Empty clone, reported as a success |
| C2 | same | `AttributeValueMapFactory::createFromRequestData()` (:1965) throws on a bad value | Empty clone, or name/description already saved: partly written |
| C3 | same | `setAttribute()` throws after `update()` | Partly written |
| D1 | `add_block_to_page_area` (:2248) | `AddBlockToPageCommand` throws (a block controller's `save()`) | Empty clone |
| E1 | `update_block_in_page_area` (:2391) | Checks are already before the draft (BF-5). `getBlockToEdit` copies the block, then the update command throws | Clone with a copied block |
| F1 | `delete_block_from_page_area` (:2736) | The "not in the draft" refusal (:2752), or the command throws | Empty clone |
| G1 | `move_block_between_areas` (:2841) | `getBlockToEdit` returns null (:2846), or `move()` throws | Empty clone, or one with a copied block |
| H1 | `set_page_attribute_value` ([PageAttributesToolkit.php:856](../../src/Tools/PageAttributesToolkit.php#L856)) | `setAttribute()` throws (the attribute type's save) | Empty clone |
| I1 | `create_page` (:1732) | `createFromRequestData()` or `setAttribute()` throws after `$parent->add()` | A whole new unpublished page, partly set up |

Not in the list, and why:
- `create_page_draft`: making an empty draft is its job. A later write that fails reuses that draft, so the rule in §4 never discards it.
- `discard_draft_version`, `approve_page_version` (disabled), `sync_multi_links_to_epra_resources_list` (disabled; writes live through `getVersionToModify()` directly, moves out in P3.4).
- `AttributesToolkit::set_page_attribute_value`: the toolkit isn't registered (`ConcreteCmsAgent` uses `PageAttributesToolkit`).
- File, user, topic and `katalysis_pro` writes: no page versions.

## 3. Check before the draft (first line of defence)
The same pattern BF-5 used for `update_block_in_page_area` and BF-7 for stale block IDs: every check that can be made on the newest version is made before `PageDraft::of()`. The clone shares its block IDs and data with the version it was cloned from, so a check on the newest version is a check on what the draft will hold.

- **A1, A2 (batch):** for every item, before the draft: look up the block in the newest version, normalise the value, `keepOmittedFields`, and run `TextIntegrity::evaluate()` with that item's mode. The draft is made only if at least one item passes; items that fail are reported in `blocks[]` as today (`ok: false`, same error text), and are never copied.
  - **`continueOnError: false`** (A4): today the blocks before the first failure are applied. Proposed: if any item fails its check, nothing is applied and no draft is made ("Nothing was changed"). Stricter and simpler; `continueOnError: true` (the default) still applies the items that pass. **Decision needed (Q1).**
  - With every item refused, the result keeps today's shape (summary, `blocks[]` with each reason), adds "Nothing was changed; no draft was made", and carries no `versionState`. `BlockTextIntegrityTest::testBatchUpdateWithNoModeRefusesALostParagraph` keeps passing unchanged.
- **Per-block `changeMode`** (Glyn, after BF-5): each item may carry `changeMode`, `textFields` and `replacedText`. An item's `changeMode` overrides the top-level one for that item; an invalid value is refused for that item by name ("blocks[1].changeMode must be one of …"), before the draft. `replacedText` or `textFields` on an item whose mode is not `scoped_text` is refused for that item with the fix in the message ("set changeMode to scoped_text on this item or at the top level") rather than silently ignored. The `blocks` description says so. `ToolBaseline::DELIBERATE_CHANGES` already lists this tool (BF-5); only its reason text would change, so **I'll ask before touching it** (memory: ask before changing passing tests).
- **B1 (headings):** split the loop into a read pass on the newest version (find the placements, match, transform, text check, open-block check) and a write pass. No draft when the read pass finds nothing to change. The logic of matching is unchanged (P3.3 rewrites this tool; the one-heading-per-block bug stays).
- **C1, C2 (`update_page`):** refuse a call with nothing to change ("No change was given: pass name, description, template or attributes. Nothing was changed."), and build the attribute map before the draft.
- **I1 (`create_page`):** build the attribute map before `add()`. **Q2:** also refuse an unknown attribute handle before `add()`, as `update_page` has done since P1.13 (today it is dropped silently and the page reported as made)?
- D1, E1, F1, G1, H1 already check everything they can before the draft; what is left are failures inside Concrete's own save, covered by §4.

## 4. Clean-up when a call still fails after making the draft (second line)
**Rule (BF-7's version model):** a failed call may discard a version only if

1. **this call created it**: `PageDraft::of($page, $created)` reported `$created = true` (the newest version was published, or saved and closed in edit mode, so Concrete cloned it). A draft that existed before the call (an earlier call's, the editor's, `create_page_draft`'s) is never discarded, whatever happens;
2. **it is still the newest version**: `MAX(cvID)` for the page is still this version, so nothing has made a newer one;
3. **it is still new and unapproved**: `cvIsNew = 1`, `cvIsApproved = 0`. Edit mode's "Save" (check-in without publishing, `removeNewStatus()`) and any approval clear `cvIsNew`, so a version the editor has saved is never discarded;
4. **nobody has used it since it was made**, detected by a fingerprint taken right after the clone and again at the failure, which must match:
   - every per-version row Concrete's `Cloner::cloneCollectionVersion()` copies: `CollectionVersionBlocks` (bID, area, order, override flags), `CollectionVersionBlockStyles`, `CollectionVersionAreaStyles`, `CollectionVersionBlocksCacheSettings`, `CollectionVersionThemeCustomStyles`, `CollectionVersionAttributeValues`, `PageTypeComposerOutputBlocks` and per-version `BlockPermissionAssignments`;
   - the version row's own fields (name, handle, description, public date, template, theme, skin);
   - no `CollectionVersionRelatedEdits` row (global-area edits; refused anyway since BF-2);
   - the page's check-out row (`cIsCheckedOut`, `cCheckedOutUID`, `cCheckedOutDatetime`): if anyone, the same user included, has entered edit mode since the clone, edit mode has the version open, so it is kept.

   Any change by this call (a copied block, a saved name) also changes the fingerprint, so a partly written version is kept too: the call can't prove a difference is only its own.

If all four hold, the version is discarded with Concrete's own `Version::discard()` (what edit mode's "Discard" does, behind `canDiscard()`), the request's cached `RECENT` page (`PageDraft::cacheAsLatest()`) is cleared so the next call in the same request sees the version that is really newest, and one info line is logged. The error then ends "Nothing was changed." If any check fails, the version is kept and the error says so in one line: "Version N, which this call made, was kept because <it has changes / it is open in edit mode / a newer version exists>", with the block IDs it now holds when a block was copied (`BlockNotFound` wording, so a retry has the right ID).

The same check runs when a call completes but changed nothing (B1 if the read pass misses a case, C1 if it slips through), so a "success" with nothing done leaves nothing either.

**How it is built:** one small class, `Tools/Utilities/DraftCleanup.php` (fingerprint, the four checks, discard, cache clear), and one pattern in each tool of §2:

```php
$draft = PageDraft::of($page, $created);
$cleanup = DraftCleanup::track($draft, $created);   // fingerprint now; does nothing if !$created
try {
    // the writes
} catch (\Throwable $e) {
    throw $cleanup->afterFailure($e);                // discards only under the rule, adds the one-line outcome
}
```

`PageDraft::of()` keeps its signature. A registry-level test (§6) fails for any write tool that makes a draft without going through this.

**Residual race (accepted, stated):** between the last check and `discard()` (a few milliseconds) an edit-mode save in another request could still land. Concrete takes no lock we could share. Considered and rejected instead: running each call in a database transaction and rolling back. It would also undo partial writes in reused drafts, but Concrete's in-request caches, events and Doctrine entities don't roll back with it, and a long transaction would hold row locks against the editor.

## 5. The retry case
A refused first call must leave a state where the corrected retry works. Three ways it could not today, each with a fix and a test:
1. **Block IDs changed by the refused call** (A1, the scenario 05 case): fixed by §3, the refused block is never copied, so its ID stays valid.
2. **A stale cached page after a discard**: the retry in the same request would get the deleted version from `CacheLocal`. Fixed by the cache clear in §4; tested by running the refused call and the retry in one process, as the agent does in one turn.
3. **A kept, partly written version**: its new IDs are named in the error (§4), and BF-7's `BlockNotFound` and BF-10's newest-version reads already give the right ID to a retry that uses the old one.

## 6. Tests (red first; fast tier after each change, full before the PR)
New `tests/Integration/FailedWriteCleanupTest.php` (throwaway pages through `ThrowawayPages`, leak-checked like every integration class):
- **One case per row of §2** (A1–I1): the failing call, then the version count, newest `cvID` and the newest version's block IDs are as before. Failures inside Concrete (A2, A3, B2, D1, E1, G1, H1, C3) are made by a test subclass of the toolkit that throws at a named step after the draft (for example from `getBlockToEdit()` or the command); no change to production code for this.
- **The rule:**
  - a failing call on a page whose newest version is already a draft (made by an earlier call, by `create_page_draft`, and by a hand edit through `ScenarioSetup`'s `hand_edit` path): that version is untouched (fingerprint and row counts identical);
  - a version changed by "someone else" between the clone and the failure (the test subclass adds a block through Concrete's command at that point): kept, and the error says why;
  - a check-out started after the clone: kept;
  - a partly written version (C3): kept, the error names it.
- **Retry:** scenario 05 runs 3 and 4 replayed (`changeMode` and `replacedText` inside the item, refused), then the corrected call with the same block ID succeeds in the same process; the same for `update_page` with nothing to change, then with a name.
- **Per-block `changeMode`:** an item's mode overrides the top level; an invalid item mode is refused by index before any draft; `replacedText` without `scoped_text` is refused with the fix in the message; a mixed batch applies the passing items only.
- **Registry-level:** every registered write tool whose code calls `PageDraft::of()` / `getDraftOf()` also uses `DraftCleanup` (a source check, as `EditModeWriteRefusalTest` does for the edit-mode guard), so a new write tool can't skip it.
- **Headings:** no match → no draft (B1).
- Expected red before the change: every A1–I1 case except the ones BF-2 already covers, the retry tests, and the per-block mode tests.

Existing tests: none should need changing. If one does (most likely `DraftIsolationTest` or `BlockTextIntegrityTest` around `continueOnError: false`), I stop and ask, and it goes in its own commit with the reason.

## 7. Scenarios (cost)
After only, as BF-7 and BF-10 did; the change is proved by PHPUnit, the scenarios check nothing regressed. Each `--repeat=3`:
- **05, 23, 24**: batch updates, the headings tool and the retry path
- **06**: the headings tool (bold paragraphs)
- **07**: add, update and delete blocks
- **10**: `update_page` / `set_page_attribute_value`
- **14**: `nothing_changed`, which counts a leftover draft as a change

21 runs at about $0.07–0.10 each (BF-10's runs): **about $1.50–2.10, cap $2.50.** A full ×1 run of all 24 (about $1.70) only if you want it (Q4).

Manual: none needed; no panel or edit-mode change. Optional for Glyn: a refused batch update from the panel, then Page Versions shows no new version.

## 8. Files
- `src/Tools/Utilities/DraftCleanup.php` (new)
- `src/Tools/PagesToolkit.php`: the seven page tools of §2 and `create_page`
- `src/Tools/PageAttributesToolkit.php`: `set_page_attribute_value`
- `tests/Integration/FailedWriteCleanupTest.php` (new); new cases in `BlockTextIntegrityTest` only if a per-block mode case fits there better
- `documentation/BEHAVIOUR_SPEC.md`: proposed **B-WRT-7** "A failed or refused write leaves nothing behind; it never discards a version someone else is working in", status after the change
- `documentation/plans/BF-3.md`, then PROGRESS and the DELIVERY_PLAN tick in the last commit
- Possibly `tests/Support/ToolBaseline.php` (the reason text only), after asking

## 9. Out of scope
- A refused permission check that leaves an `Areas` row (`Area::getOrCreate()` before the draft in `add_block_to_page_area` and `move_block_between_areas`): page-level, not a version, harmless; noted for P3.3.
- Validating a new block's data with its controller's `validate()` before `add_block_to_page_area` (Concrete's add command doesn't): it would change what is accepted (Q3).
- Undoing a partial write in a draft that already existed: no rule allows the call to touch it (§4).
- `TurnChanges` only lists successful calls, so a kept, partly written version doesn't reload the panel. Rare after §3; noted for P3.6.
- Whether a batch where every item failed should be `ok: false` in the envelope (it is `ok: true` with `failureCount` today, so `no_write_tool_failed` doesn't see it): the BF-7 error-message audit in P3.3.
- The heading tool's one-heading-per-block bug (P3.3).

## 10. Risks and questions
- **Q1:** `continueOnError: false` applies nothing if any item fails its check (§3)? Recommended: yes.
- **Q2:** `create_page` refuses an unknown attribute handle before making the page, as `update_page` does? Recommended: yes. And if it still fails after `add()`: delete the page it just made (no children, never published, only this call knows it), or keep it and say so? Recommended: delete it through the same rule (made by this call, unchanged since, not checked out), with Concrete's `Page::delete()`; it leaves no permission rows for a page with no area grants.
- **Q3:** block `validate()` before `add_block_to_page_area`: recommended out of scope (§9).
- **Q4:** the full ×1 run, or the seven named scenarios only? Recommended: the seven.
- **Risk:** the fingerprint tables are those of Concrete 9.5.5's `Cloner`; a test compares the fingerprint of a fresh clone with its source, so a Concrete upgrade that copies another table fails loudly rather than discarding a used version.
- **Risk:** the batch split (§3) changes when blocks are copied; `DraftIsolationTest`, `StaleBlockIDTest` and `NewestVersionReadTest` cover the copy and the IDs returned.
