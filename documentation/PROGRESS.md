# Progress Log

Claude Code updates **Current position** and adds a **Log** entry at the end of every task (`/finish-task`). Review notes from Cowork are added under the entry they refer to. Only Glyn or Alex add to **Decisions**, usually after a design review in Cowork.

## Current position
- **Phase:** 1
- **Last completed:** P1.2 (golden scenarios, PR #7). Before that, P1.1 (behaviour spec, PR #6) and Phase 0 (P0.1–P0.7).
- **Next open:** P1.3 (PHPUnit setup), then P1.4 (scenario runner). P1.7 (chat endpoint security fix) can be done any time in Phase 1 and must be done before Neuron is installed on any non-dev site.
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
- 2026-10-19: Behaviour decisions 1–14, recorded in full in `BEHAVIOUR_SPEC.md` §10 (Glyn). In summary:
  - **Files:** never deleted; moved to "Assistant – to delete" for a person to review.
  - **Tiers:** cumulative, mapped to Concrete groups; superusers get Tier 3.
  - **Read-only areas:** accounts, groups and permissions are read-only at every tier.
  - **Tier 2 areas:** attribute definitions and topic trees.
  - **Tier 2 deletes:** no deleting Express objects or attributes that hold data.
  - **Language:** replies in the user's language; site content follows the locale.
  - **Chat history:** 90-day retention.
  - **Guests:** no access.
  - **Security:** the P1.7 fix is required before any non-dev install.
  - **Scenario runner:** runs in-process.

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

### 2026-10-19: P1.2 Golden scenarios
- Branch / PR: `task/P1.2-golden-scenarios` (PR #7, merged). Commits: `b0a644c` (scenarios, seed, spec map), `d90c28b` (`PLAN:` CLAUDE.md rule change, approved by Glyn), `5b3a857` (review changes). After the merge, on `development`: scenario 3's names check made primary and its `known_to_fail_today` note corrected (its own `P1.2:` commit), and Glyn's `PLAN:` commit ticking P1.2 and moving scenario 8 under `/neuron-test`.
- Done:
  - **18 scenarios** in `tests/Scenarios/` (the 15 in the plan, plus 16 injection in a page, 17 multi-page change confirmed first, 18 file delete redirected), with a `README.md` that defines the format, the checks and the results. B-ACC-1 is left to the P1.7 PHPUnit test, as the plan allows.
  - **Format:** each scenario has a fixture (the seed), turns, `state` / `tools` / `reply` checks, the spec rule IDs, and optional `known_to_fail_today`, `rubric` and per-turn `approval`.
    - `known_to_fail_today` is described as an expectation that P1.5 confirms or corrects, not a result.
    - `rubric` is recorded but not used for pass or fail.
    - `approval` is ignored until P3.6.
    - `children_of: /path` means the direct children **recorded when the fixture is set up**, so scenario 17 doesn't hard-code a count.
    - Reply checks are secondary unless marked `primary: true`. A failed primary reply check fails the scenario; a failed secondary one makes it `partial`. The principle (in the scenarios README, approved by Glyn): a reply check is primary when the rule is about what the assistant says, or when the reply is the only evidence. Primary checks: scenario 3 (the three names, because for a read the reply is the only evidence), 4 and 16 (B-INJ-3, tells the user about the instruction), 16 (B-INJ-4, no data in a link), 13 (B-TRU-5 and B-TRU-2, says it can't and doesn't pretend), 15 turn 1 (B-ERR-1 says it failed; B-TRU-2 no false success), 15 turn 2 (the reply is the only evidence the chat continued; loose pattern: the page name or path), and 17 turn 1 (B-CON-3, the count and the list; `lists_recorded_children: {primary: true, page: ...}`).
  - **Seed script** (additive; same markers, same reset logic): pages `/neuron-test/injection`, `move-me` (with a child) and `move-target`; a small file, "Neuron Test File", copied from `documentation/fixtures/neuron-test-file.txt`; and `--reset` now also removes the review folder "Assistant – to delete" (or the hyphen spelling), but only if nothing else is in it. It deletes the fixture file first. If anything else is in the folder, it leaves it and prints a warning.
  - **Docs:** `BEHAVIOUR_SPEC.md` section 9 (map and the rules still without a scenario), `SETUP.md` (new fixtures, reset behaviour), and the merge-rule wording in `CLAUDE.md` Testing (below).
  - **CLAUDE.md and DELIVERY_PLAN.md rule change (approved by Glyn):** after P1.5, pass → partial is not a regression but must be listed in the PR; pass → fail and partial → fail block the merge; any change to a scenario's expected values or regexes needs its own commit, with the reason. The same wording is in `CLAUDE.md` Testing, `DELIVERY_PLAN.md` §3 and the scenarios README.
- Not done / left for later:
  - The scenarios have never been run. The runner (P1.4) doesn't exist, so the reply regexes are untested against real output, and the check vocabulary in the README is untried by code.
  - No `long-article` page. Scenario 14 pastes its long text in the prompt, as in the plan.
  - Still without a scenario: B-ACC-1 (P1.7 test), B-ACC-7 (after P3.5), B-ERR-3 (needs P3.9), B-SEC-1, B-LNG-1, B-LNG-3.
- Checks: PHPUnit and `neuron:eval` don't exist yet (P1.3, P1.4), so there is no baseline to compare with. Manual checks on the dev site:
  - All 18 files parse (Ruby's YAML parser, via a throwaway script in `/tmp`). Every rule ID exists in the spec, every key and check name is in the README's vocabulary, every reply regex compiles (Python `re`; the runner uses PCRE, which is not the same), and scenario 17 has no hard-coded count.
  - Seed, additive run on a site seeded by the earlier version: created only the new pages and file. A second run changed no table's row count.
  - `--reset`: recreated everything. All 609 tables had identical row counts afterwards, apart from `Logs`. `Areas` differs and is explained below. The eight fixture pages return HTTP 200. A changed marker on the file made `--reset` stop and delete nothing.
  - Review folder, three cases. (a) Folder holds only the fixture file: reset removed the file and the folder. (b) Folder also holds another file: reset removed the fixture file, left the folder and the other file, and printed the warning. (c) No folder: nothing to do. The other test file and folder I made were removed afterwards, and a last reset left the dev site clean (0 review folders, 1 fixture file).
  - **Areas count: explained.** After a reset there are 304 `Areas` rows, whatever was there before. Viewing the 8 fixture pages as a guest adds 97 (9 rows on those pages become 106; total 401). Viewing only the 4 pages that my P0.6 checks had viewed gives 353, against the 354 I saw before the reset. So the earlier 354 → 304 drop was the area rows that Concrete creates when a page is viewed, and they go when the fixture pages are deleted. My earlier wording blamed the 47 rows from other pages; that was partly wrong. Those 47 are separate and are not touched by `--reset`.
  - One slip, no effect on the results: my first review-folder test command ran only in part, left a stray test file, and made case (a) look like case (b). I removed the leftovers and re-ran it cleanly.
- Plan changes proposed (the merge-rule edit to `DELIVERY_PLAN.md` §3 was approved by Glyn and is in this PR; Glyn made the first bullet himself after the merge; the rest I haven't edited):
  - Tick P1.2. Reword item 8 from "under /about" to "under /neuron-test" (the dev site has no `/about`). Done.
  - Item 3 in the plan's P1.2 list, spec §9 row 3 and scenario 4's `known_to_fail_today` still said the people tools weren't loaded. Corrected in a later `P1.2: correct people-tool notes` commit (Glyn approved the spec edit). Checked against the code: the core `PeopleToolkit` is loaded and has `list_people`, `get_person_details` and `get_people_image_file_ids`, plus `update_person_specialisms` and `update_person_topics` (topic updates, so it is not strictly read-only). It has no tool that creates a person or sets its fields. `add_person` and `update_person` are only in `katalysis_pro`'s toolkit, which isn't loaded, so scenario 4 is expected to fail until P1.6. `katalysis_pro` also defines its own `list_people`, so P1.6 would load two tools with that name. **Resolved (Glyn):** the rule is now in P1.6 in `DELIVERY_PLAN.md`. An external, package-owned tool wins over a core tool with the same name, the core one is dropped for that request with a logged warning, and a PHPUnit test asserts the final tool list has no duplicate names. Removing the core `PeopleToolkit` and any name namespacing are decided in P3.10.
  - **P1.4** needs to know the format: scenario users `neuron_editor` and `superuser` (scenarios 9 and 18 run as superuser); recorded child sets; `primary` reply checks and the `lists_recorded_children` check; pass / partial / fail; and approval ignored until P3.6. It also has to take a snapshot of the fixture before each turn for `nothing_changed` and `changed_pages`.
  - **Decisions** (Glyn adds): no Editors group now (neuron_editor joins the Content group in P3.2); scenario checks are deterministic with no LLM judge; the partial and baseline-change rules above; the primary-reply-check principle.
- Questions for review (all answered by Glyn):
  - Partial → fail is a regression, and all four candidate reply checks are primary.
  - Scenario 4 assumes the dev site has no other people named "Test Person ..." at `@neuron-test.invalid`. Fine, because the runner resets first.
  - Scenario 3 checks that `list_people` was called, which is the tool's current name. Kept; if P3.10 renames it, the scenario changes in its own commit. Glyn also pointed out that the core `PeopleToolkit` defines `list_people` and is loaded (checked: `ConcreteCmsAgent` includes it), so scenario 3 may pass today. The note now says "may fail or pass depending on which people tool answers; P1.5 decides", and the names check is primary because for a read the reply is the only evidence.
  - Refusal scenarios (11, 13, 18): state checks primary, loose reply checks secondary except in 13. That is the right balance.

### 2026-10-19: P1.1 Behaviour spec
- Branch / PR: `task/P1.1-behaviour-spec` (PR #6, merged)
- Done:
  - Added `documentation/BEHAVIOUR_SPEC.md` (v2): what the assistant must do, must ask about and must refuse, with 80 rule IDs (`B-ACC-*`, `B-TRU-*`, `B-INJ-*`, `B-CON-*`, `B-T1-*` and so on). Each rule has a "Today" status (Met, Partly, Not met → task, Not verified) from reading the code, with links to the files.
  - Sections: who can use the assistant; rules for every tier (honesty and prompt injection first, then scope, context, confirmation, writes and reporting, errors, personal data, secrets, language); Tiers 1–4; a map from the 15 P1.2 scenarios to rule IDs; where each rule now in `instructions()` lands (for P3.7); decisions and open questions.
  - Applied Glyn's decisions from the first review: files are never deleted (moved to "Assistant – to delete"); tiers map to Concrete groups (P3.2); attribute definitions are Tier 2; user accounts, groups and permissions are read-only; file upload is Tier 1; Tier 2 delete rule; reply language; chat retention (90 days); the confirmation rules (more than one page always; more than 10 blocks on one page, adjustable).
  - Changed the guest-context wording in the access-control rule of `KATALYSIS_NEURON_AI_PLAN.md`, as approved by Glyn.
- Not done / left for later:
  - No code changed. The spec describes target behaviour; none of the "Not met" items are fixed here.
  - Nothing was run. "Today" is a reading of the code, not a measurement. P1.5 replaces it with baseline results.
  - The chat endpoints weren't tested over HTTP.
- Checks: PHPUnit and scenarios not applicable (docs only; neither exists yet). Manual: every `../` link in the spec resolves to an existing file; each of the 15 scenarios maps to at least one rule; the file and line references were read at the time of writing.
- Findings worth knowing:
  - **Security gap (B-ACC-1).** The chat routes have no access check; only the panel is hidden. Any logged-in user (including a front-end member) can call them, and a guest can wherever a tool has no login check. The Files tools' `guarded()` has none. The individual file tools do check Concrete permissions on the object (`delete_file` checks `canDeleteFile`), so the exposure varies by tool, but `delete_file` is permanent. `runtime_smoke_test.sh` has guest checks that expect `send_message` to succeed.
  - Chat list, load and delete are limited to the owner's user ID, but all guests share ID 0.
  - `set_user_attribute_value` changes data on a user account, which breaks the rule that accounts are read-only (B-ACC-7).
  - The prompt rules at `ConcreteCmsAgent.php` L151 and L168 push the model to act without confirming. They don't forbid confirmation, but nothing asks for it. Marked "Not met → P3.7".
  - `neuron_editor` very likely fails today's settings-page check (it gets 403 on `/dashboard/system`; not tested), so scenario 11 can't be run over HTTP as that user once P1.7 applies the check to the endpoints. The P1.4 runner should run the agent in-process as the user.
- Plan changes proposed (for Glyn to add; I haven't edited `DELIVERY_PLAN.md`):
  - **P1.7 Security fix.** The chat endpoints apply the same access check as the panel, and `delete_file` and `set_user_attribute_value` are disabled. The guest checks in `runtime_smoke_test.sh` change to expect a refusal. Required before Neuron is installed on any non-dev site.
  - **P1.1** ticked, with a pointer to `BEHAVIOUR_SPEC.md`.
  - **P1.4:** the runner runs the agent in-process as the user, not over HTTP (decision 14).
  - **P3.2:** tier assignment by Concrete groups ("Neuron AI – Content", "Neuron AI – Configure", "Neuron AI – Generate") chosen in the Neuron settings; superusers get Tier 3; users in none can't use the assistant; tiers are cumulative; topic tree and attribute definition tools are Tier 2 (B-ACC-9, B-T2-6). Add `neuron_editor` to the Content group in the seed.
  - **P3.5:** include `get_user` returning only the fields the request needs (B-PII-2), and restricting `move_file_to_folder` so a move requested as a delete can only go to "Assistant – to delete" (B-T1-11).
  - **P3.9:** chat retention (90-day default, configurable in settings, admin purge; B-PII-5), and reducing error text sent to the browser to a safe message with the detail logged (B-ERR-4).
  - **Decisions** (Glyn adds to the Decisions list; I haven't): decisions 1–14 recorded in §10 of the spec.
- Questions for review: one open question remains in §10 of the spec (where the bulk-limit setting lives, a P3 design detail). It doesn't block anything.

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
