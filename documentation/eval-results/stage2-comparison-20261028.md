# P3.S2: Stage 2 comparison (28 October 2026)

- **Code:** `development` at b354b60 (every Stage 2 task merged: P3.3-3, P3.4, P3.3-4, P3.3-7, P3.3-5) plus this branch's `PLAN:` commit. Neuron 4.1.12; Concrete 9.5.5; PHP 8.4.27; gpt-4o, temperature not set.
- **Fixtures:** I backed up the database (`prodb_before_P3.S2_20261028_131751.sql`) and reset the seed first. The runner also resets before each run.
- **Runs:** 74, as the entry says:
  - 04, 06, 08, 09, 10, 13, 17, 20, 23, 24, 27–32, 36 and 37 ×3
  - the unstable 05, 07, 12 and 15 ×5
- **Raw results:**
  - `stage2-comparison-20261028-x3.json` (run file 20261028-131803)
  - `stage2-comparison-20261028-x5.json` (run file 20261028-132806)
- **Spend:** about **$7.03** ($5.20 + $1.83). That's the runner's upper bound. It is under Glyn's $8.00 go-ahead (priced at $6.26 beforehand) but over the entry's $6.40 cap.
- **Average: $0.095 a run**, against P3.S1's $0.070.
  - Even the ordinary ×5 runs came to $0.092.
  - Input tokens per run have grown: longer tool schemas and descriptions (P3.3-5, P3.3-7), and more tool calls per turn.
  - Price later stages at about $0.09–0.10 a run.

## Results against the reference

The reference for each scenario is its most recent recorded multi-run label. That is P3.S1 where it ran, otherwise BF-6 (12, 17, 04) or the Phase 2 exit (09, 20). For 36 and 37 it is P3.3-7's ×1 run.

| Scenario | P3.S2 | Reference | Change |
|---|---|---|---|
| 04 import people | fail 3/3 | fail (BF-6) | — (known; `add_person` waits for P5.0) |
| 05 h3 → h2 | partial 5/5 | partial 5/5 (P3.S1) | — |
| **06 bold → h3** | **fail 3/3** | partial 3/3 (P3.S1) | **partial → fail** |
| 07 content → HTML | pass 5/5 | pass 3/5 (P3.S1) | — (better) |
| 08 child page | pass 3/3 | pass 3/3 | — |
| 09 move page | fail 3/3 | fail (P2.9) | — (known; `move_page` disabled) |
| 10 page attribute | pass 3/3 | pass 2/3 | — |
| 12 follow-up | pass 5/5 | pass 3/5 (BF-6) | — (better) |
| 13 no such capability | pass 3/3 | pass 3/3 | — |
| 15 failed write, chat continues | pass 3/5 (2 fail) | pass 3/5 (P3.S1) | — (see causes) |
| 17 multi-page confirmed | fail 3/3 | fail (BF-6) | — (known; B-CON-3, P3.6-2) |
| 20 navigate then edit | pass 3/3 | pass (P2.9) | — |
| 23 h3 → h2, edit mode | pass 2/3 (1 partial) | pass 3/3 | — |
| 24 edit after hand edit | 1 pass, 1 partial, 1 fail | pass 2/3 (1 partial) | no majority (see causes) |
| 27 Content block | pass 3/3 | pass 2/3 | — (better) |
| 28 Image block | pass 3/3 | pass 3/3 | — |
| 29 Page List block | pass 2/3 (1 partial) | pass 3/3 | — |
| 30 Feature block | pass 2/3 (1 partial) | fail 2/3 (P3.S1) | fail → pass |
| 31 Accordion block | pass 3/3 | pass 3/3 | — |
| 32 Express form block | pass 3/3 | pass 3/3 | — |
| 36 Image Slider | pass 3/3 | partial ×1 (P3.3-7) | new |
| 37 Testimonial | pass 3/3 | pass ×1 (P3.3-7) | new |

**Merge rule:**
- **pass → fail:** none.
- **partial → fail:** **06**. This blocks Stage 3 until its fix task is merged. It reproduces with P3.S1's own code, so Stage 2's code didn't cause it (see below).
- **pass → partial at the majority:** none.
- **24 has no majority label** (1 pass, 1 partial, 1 fail). It isn't counted as a regression. Its one fail is explained below.

## Every pass → fail, partial → fail, and failed run in a passing scenario, with its cause

- **06, partial 3/3 → fail 3/3 (regression; data, not Stage 2's code).** `batch_update_content_headings` refused the Column 1 block in every run: "Permission denied: you can't edit block … It needs Concrete's 'Edit Block' permission". In P3.S1 the same call, with the same inputs, converted both headings. What I found:
  - **Reproduced without the model:** the same tool call, as `neuron_content_editor` on today's data, is refused.
  - **Not Stage 2's code:** the same call on **P3.S1's code (8df5f90)**, against today's data, is refused the same way.
  - **Where it fails:** Concrete's Edit Block (and View Block) check is false for every fixture user on blocks in areas that **inherit** the page's permissions. On the columns page and the edit-mode page:
    - the page's Edit Page Contents and the area's Edit Area Contents are true
    - the block checks are false
  - **Where it passes:** blocks in areas that **override** their permissions, as the seed's Main on headings and block-types do. Scenarios 05, 23–32, 36 and 37 write there.
  - **Not the cache:** clearing Concrete's cache changes nothing. A result computed as the super user doesn't carry over to the next user (checked; no elevation).
  - **Not my cleanup:** the P3.3-5 red-run cleanup is not the cause. `Page::delete()` on a page that no longer exists returns at once.
  - **Not found yet:** what changed in the data since P3.S1 (10:17Z today).
  - **Proposed fix task, BF-19: find why block checks fail in inherited areas.** Compare the permission tables in P3.S1's backup (`prodb_before_P3.S1_20261028_101743.sql`) with today's, and look for any site setting changed since. Then fix the cause: the data, or the seed (grant on Main as the headings page does), or report it upstream if Concrete misbehaves. Re-run 06 ×3.
  - **Not explained:** 23 wrote to the edit-mode page's inherited Main in this run, though a check outside the runner refuses it. The fix task should explain that too.
- **15, runs 2 and 4 (fail; 3 of 5 pass, as in P3.S1): a wrong-place write.** The model called `list_pages` with `parentPath: "/"` and `limit: 1`. That returned the home page's first child, `/neuron-test`, which the model took for the home page; it created "Neuron Denied" there and said it was "under the home page".
  - Before BF-18, `/` was an error.
  - The page is unpublished (P1.13), but it is in the wrong place, and the reply is wrong.
  - **Proposed: `list_pages` names its parent** (`parent: {id, path, name}`, as `get_page_children` does), so the model sees which page `/` is. A small fix task.
- **24, run 3 (fail).** Asked to rename the heading "Opening Times", the model used `batch_update_content_headings` with `targetTag: "h3"`. That tool only changes levels, and the heading is already h3, so it reported "not found". The model told the user the heading couldn't be found.
  - Earlier runs edited the text with `batch_update_page_content`.
  - **Proposed for P3.7-2 or a small task:** the headings tool says when a heading is already at the target level, and that it changes levels, not text.
- **29, run 1 (partial).** The first `add_block_to_page_area` failed with a raw SQL exception (`INSERT INTO btPageList …`), because the model sent `true`/`false` for the flag columns. The Level 0 adapter (P3.3-7) passes them as given. The model retried with 1/0, which worked.
  - **Proposed fix task, BF-20:** Level 0 converts a boolean to 1/0 for flag and number columns, refuses a non-numeric value naming the field, and never returns the database's message.
- **17, 3/3 fail (known), behaviour changed.** Since P3.3-5 accepts `parentID` on `get_page_children`, the model no longer gets refused there. It reads the children and calls `update_page` on all ten without asking first. B-CON-3 (confirm a multi-page change) is P3.6-2's, through approval.
- **Partial runs:** 05 ×5, 23, 29 (above) and 30 missed only secondary reply checks:
  - the page path, or "undo", in 05
  - "visible to all users once someone clicks Publish" (23)
  - "will only be live to the public once you publish" (30)

  None of these are regressions. 30's wording shows the publish pattern still misses some paraphrases.

## Improvements over the reference
- 07 went from 3/5 to 5/5 pass, and 12 from 3/5 to 5/5.
- 27 went from 2/3 to 3/3.
- 30 went fail → pass. BF-17 and P3.3-7 made field names refused, not dropped.
- 36 and 37, block types outside P3.3-6's six, pass 3/3 through the Level 0 adapter.

## BEHAVIOUR_SPEC
The "Today" column is brought up to date for Stage 2's rules:
- B-ACC-5
- B-TRU-3 (lists stop counting and say "at least")
- B-WRT-2 and B-WRT-4 (stale line links into the split `PagesToolkit.php`)
- B-T1-2 (child rows)
- B-T1-4 (the EPRA tool is gone; scenario 07)
- B-T1-6 (Level 0)
- B-T1-7 and B-T1-8 (stale links)
- B-T1-10 (bare script URLs)

## Phase 3 margin (after this run)

The budget is $60. These are actual spends for the tasks done, and caps for the rest:

| | Spent or capped |
|---|---|
| Stage 1, spent: P3.3-1 $2.17, P3.3-2 $0, P3.3-6 $0.98, P3.7-1 $0, P3.11-1 $0.11, P3.5-1 $1.14, P3.5-2 $0.07, P3.2-1 $2.65, P3.S1 $6.74 | $13.86 |
| Fix tasks between stages, spent: BF-14, BF-15, BF-16 and BF-18 $0; BF-17 $0.48 | $0.48 |
| Stage 2, spent: P3.3-3, P3.4, P3.3-4 and P3.3-5 $0; P3.3-7 $1.26; P3.S2 $7.03 | $8.29 |
| **Spent so far** | **$22.63** |
| Stage 3 caps $8.20, Stage 4 caps $10.60, alongside $0.25, P3.12 $10.10 | $29.15 |
| **Total** | **about $51.78, leaving about $8.22** |
| If P3.S4's run is the exit run (P3.12 costs nothing more) | about $41.68, leaving about **$18.32** |

**Repricing risk.** The remaining caps were priced at about $0.07 a run. This run cost $0.095 a run, so every cap grows by about 1.36×:
- Stage 3's $8.20 becomes about $11.13 (+$2.93).
- Stage 4's $10.60, including P3.S4, becomes about $14.39 (+$3.79).
- P3.12's $10.10 becomes about $13.71 (+$3.61).
- In all, that's about **$10.33 more**. The phase would then go about **$2.10 over** its $60 budget.
- If P3.S4's run is the exit run (+$6.72 instead), about **$11.60** would be left.

So, at today's run price, **P3.S4 needs to be the exit run** to stay within budget; otherwise the budget needs Glyn's decision. Cutting the input tokens per run would help everywhere. P3.2-2's tool groups and P3.7-2's shorter prompt both aim at that.

The proposed fix tasks (BF-19's re-run of 06 ×3, BF-20's ×1 of 29) add about $0.40.
