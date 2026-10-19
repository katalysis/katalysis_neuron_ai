# Progress Log

Claude Code updates **Current position** and adds a **Log** entry at the end of every task (`/finish-task`). Review notes from Cowork are added under the entry they refer to. Only Glyn or Alex add to **Decisions**, usually after a design review in Cowork.

## Current position
- **Phase:** 0
- **Last completed:** Phase 0 (P0.1–P0.7). P0.3 was done by Glyn on GitHub. P0.7 was done in Cowork.
- **Next open:** P1.1 (behaviour spec).
- **Scenario baseline:** not recorded yet (P1.5)

## Decisions
Record decisions that change the plan or design, with the date and who made them.
- 2026-10-19: Neuron AI 4 upgrade made Phase 2, before the refactor (Glyn).
- 2026-10-19: Extension model adopted. Extensions use our `ToolDefinition` contract, never Neuron classes (Glyn).
- 2026-10-19: Workflow clarified after Claude Code's first review:
  - commit prefixes are a task ID or `PLAN:`
  - the merge rule moves from "no regressions" to "all pass" at the end of Phase 3
  - planned deletions are covered by plan approval
  - mechanical conversions are covered by a registry test (Glyn)
- 2026-10-19: Neuron isn't in use on EPRA for now. P5.4's pilot client is generalised to EPRA or a Katalysis-hosted site (Glyn).

## Entry template
```
### YYYY-MM-DD: P?.? Title
- Branch / PR:
- Done:
- Not done / left for later:
- Checks: PHPUnit … / Scenarios x of y (baseline z) / manual …
- Plan changes proposed:
- Questions for review:
```

## Log
(newest first)

### 2026-10-19: P0.7 EPRA working copy check (done in Cowork)
- Done: diffed the EPRA site's package copy (`main` plus its uncommitted changes) against `f218349` on `development`. They're identical: 766 files each, `diff -rq` is clean, and the `PagesToolkit.php` checksums match. Nothing exists only in the EPRA copy.
- Decision: Neuron isn't in use on EPRA for now (Glyn). The EPRA copy is left as it is and never edited. If Neuron returns there, it will be a fresh install of a tagged release.
- Not checked: the live EPRA server's copy. Diff it before any future deployment there.

### 2026-10-19: P0.6 Seed the dev site with test content
- Branch / PR: `task/P0.6-seed-dev-site` (PR #5, merged)
- Done:
  - Added `documentation/fixtures/seed_test_content.php`, run with `c5:exec` (options go after `--`: `-- --reset`). Without options it creates whatever is missing. With `--reset` it deletes only what it created, then recreates it.
  - It creates:
    - `/neuron-test` with child pages `headings` (3 Content blocks with h2/h3 and bold-paragraph headings), `columns` (a "Row - Two Column" container with nested Content blocks: bold-paragraph headings in column 1, h2/h3 in column 2) and `people` (a People block that selects the three test people)
    - three fake people ("Test Person Alpha/Beta/Gamma", `@neuron-test.invalid` emails)
    - an Express object "Test Room" (`neuron_test_room`: name, capacity, room type select with meeting/training/hall, description) with four entries
    - a non-admin user `neuron_editor` with view, edit contents/properties, add subpage and approve versions on `/neuron-test` and its children, and nothing else
  - It never reads or changes the 5 existing people or any other page, user or Express object. Before deleting, `--reset` checks a marker on each item (page description, user email, Express name, person name plus email domain) and aborts without deleting anything if one doesn't match.
  - Dev-only guard: it refuses to run unless the canonical URL host is `.test`, `.localhost` or `localhost`.
  - Documented in `documentation/SETUP.md`: the script, the throwaway `neuron_editor` password, and how to take and restore a full database snapshot in `~/Herd/backups/`.
  - Local snapshots, outside git: `~/Herd/backups/prodb_before_P0.6_20261019_130534.sql` (before any change) and `~/Herd/backups/prodb_seeded_20261019_134240.sql` (the seeded state). Mode 600; they contain password hashes.
- Not done / left for later:
  - The Express object has no published Dashboard pages or forms. Entries exist and read correctly through the Express API, but they aren't browsable in the Dashboard. Not needed yet; to revisit in Phase 6.
  - No Express entry list block was added to any page (Phase 6).
  - The People block on `/neuron-test/people` uses only the test people. `PeopleToolkit` and the external toolkit weren't touched.
  - Nothing was added for EPRA-specific blocks (`epra_resources_list` isn't installed on the dev site).
- Checks: PHPUnit and scenarios not run (not set up until P1.3 and P1.4). Manual checks on the dev site:
  - All four pages return HTTP 200 as a guest and show the expected text, with no error text.
  - Express: the object, its four attributes and four entries read back correctly through the Express API, including the select values.
  - `neuron_editor` (logged in over HTTP) gets the toolbar on all four `/neuron-test` pages, gets no toolbar on `/`, `/kitchen-sink`, `/terms-and-conditions`, `/privacy-policy` or `/search`, and gets HTTP 403 on `/dashboard`, `/dashboard/system` and `/dashboard/sitemap/full`. The permission tables show `delete_page`, `move_or_copy_page`, `edit_page_permissions` and the other page keys held by Administrators only. I did not check delete-denial over HTTP: the delete dialog returned HTTP 500 for both a seeded page and the home page, which isn't conclusive.
  - Repeatability: running without `--reset` on a seeded site changed nothing; an unknown option exits 2; `--reset` on a seeded site gave identical row counts in all 608 tables apart from `Logs`; with the marker on `/neuron-test` altered, `--reset` exited 1 and deleted nothing.
  - Clean deletion: deleting the fixture (with a temporary delete-only copy of the script, not committed) left every table's row count equal to the pre-seed baseline, except `Logs` and `Areas`. The extra 47 `Areas` rows come from my HTTP checks viewing existing pages, which makes Concrete create their areas; none belong to the test pages.
  - Two things found along the way and fixed in the script: deleting the page and user left 8 permission-access rows and an access entity behind (Concrete has no API to remove them, so the script removes the ones tied to the fixture with SQL, only if nothing else uses them), and recreating the Express object in the same process failed until the request attribute-key cache was flushed.
  - One-off: 8 permission rows and 1 entity left by my first test cycle (before the cleanup existed) were removed by hand with SQL, after confirming nothing referenced them.
- Plan changes proposed:
  - Reword P0.6's last bullet from "a SQL dump in `documentation/fixtures/` so the site can be reset" to "a seed script plus a local snapshot". The script in `documentation/fixtures/` is the committed fixture. Full-database snapshots stay out of git.
  - Tick P0.6 in `DELIVERY_PLAN.md`, and change the Phase 0 heading to say that only P0.7 is open.
  - P0.6 lists the non-admin editor user as "a non-admin editor user". It is a Registered Users member with per-page grants, not in any editor group.
- Questions for review:
  - `neuron_editor` isn't in a group, because the seed's reset only deletes what it created. Do the scenarios need an "Editors" group instead? It would be added to the seed.
    **Resolved (Glyn):** no group for now. Decide in P1.2.
  - The fixture IDs aren't stable between resets (page, person and Express entry IDs change). Scenarios should look things up by path, name or handle, not by ID.
    **Resolved (Glyn):** agreed. Added to P1.4 as a rule.

### 2026-10-19: P0.4 + P0.5 Package .gitignore and archive of analysis docs
- Branch / PR: `task/P0.4-gitignore-and-archive` (PR #4, merged)
- Done:
  - P0.4: added `.gitignore` with `.DS_Store` and `.claude/settings.local.json` (personal Claude Code settings).
  - P0.5: moved the six analysis docs from the package root to `documentation/archive/` with `git mv` (history kept, contents unchanged): `BLOCKS_TOOLKIT_IMPLEMENTATION`, `FEATURE_COMPARISON`, `OPENAPI_OPPORTUNITY_ANALYSIS`, `TOOL_IMPROVEMENTS`, `VERSIONS_TOOLKIT_IMPLEMENTATION`, `VERSION_MANAGEMENT_CASE_STUDY`.
- Not done / left for later:
  - P0.4's "remove the committed `.DS_Store` files" was skipped: there were no tracked `.DS_Store` files, and none on disk in the package.
  - The four root scripts (`test_tools.php`, `repair_chat_history.php`, `validate_all_chats.php`, `test_container_search.php`) are deliberately kept and not ignored. P3.3 deletes them.
  - Three links in the archived docs now point at old root paths and are stale: `TOOL_IMPROVEMENTS.md` line 370 (`FEATURE_COMPARISON.md`), and `VERSIONS_TOOLKIT_IMPLEMENTATION.md` lines 229-230 (`OPENAPI_OPPORTUNITY_ANALYSIS.md`, `VERSION_MANAGEMENT_CASE_STUDY.md`). Left as they are because the docs are historical. Links between the moved docs still work, and links to `src/` files are unaffected.
- Checks: PHPUnit not run (not set up until P1.3) / Scenarios not run (P1.4) / no PHP changed, so no manual test on the dev site. Git checks: `git check-ignore` confirms the two entries are ignored and `vendor/` is not; the diff is 6 renames with 0 content changes and no deletions; no other references to the old root paths remain outside the archive.
- Plan changes proposed: tick P0.3 (done by Glyn on GitHub), P0.4 and P0.5 in `DELIVERY_PLAN.md` Phase 0, and update the Phase 0 heading to reflect that only P0.6 and P0.7 remain. Optionally reword P0.4 to say that no `.DS_Store` files were tracked.
- Questions for review: none.

### 2026-10-19: Setup
- Commit `af261c2`: delivery plan, extension design, CLAUDE.md, the start/finish commands, settings, PR template. Reviewed by Claude Code. Its seven clarification points were resolved in a `PLAN:` follow-up commit.
