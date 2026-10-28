Finish the current delivery plan task.

Run each command as its own Bash call: no `&&` or `;` chains, and no `cd` prefix. If a commit message mentions a word the Bash guard blocks or asks about (for example `database.php`, `.env`, `core.hooksPath`, a SQL keyword or `run_scenarios`), write the message to a file in the scratchpad and commit with `git commit -F <file>`.

The **base branch** is the one `/start-task` recorded: `git config branch.<branch>.description` ends `; base <base>`. If it is missing, take it from `DELIVERY_PLAN.md` as `/start-task` step 2 does (an integration branch named in the phase's Branching note, otherwise `development`).

1. Run the full PHPUnit suite (`php tools/phpunit/vendor/bin/phpunit`) and the JS safety tests (`node --test tests/js/*.test.mjs`, BF-11). If `node -v` fails, STOP: "Node is required for the JS safety tests" (SETUP, "Running the JS tests"). Don't open the PR without them. Run scenarios only if the task touches prompts, tools, the safety layers, the model or provider, or the Neuron version (CLAUDE.md, Test tiers), and only as the task's `DELIVERY_PLAN.md` entry names them: in Phase 3 (WP-4), a free `--fake` run of the ones it touches and ×1 of its own new or changed ones; the ×3 comparison is the stage's own task (P3.S1–P3.S4), except for the tasks whose entries keep their own ×3. They are an `ask` because they cost money. Report the results honestly, failures included, against the last recorded results.
2. Show `git diff --stat <base>...HEAD`. Confirm that nothing outside the approved plan (or, for a task without a plan stop, outside its entry's scope) changed, and that nothing was deleted without being in the plan or the entry.
3. If the work had to differ from `DELIVERY_PLAN.md` or a design doc, say so clearly. Don't change the plan or design docs yourself; list the proposed change under "Plan changes proposed". List each question you decided inside the entry under "Decisions taken" in the PR description (WP-4).
4. Push the branch and open the PR, as two calls:
   1. `git push origin <branch>` (allowed for `task/*` and `spike/*`)
   2. `gh pr create --base <base>`, filled in from `.github/pull_request_template.md` (prompts for approval)
5. Last commit, now that the PR number is known (prefix: the task ID). It touches only the task's own lines, so two open PRs don't conflict (WP-5):
   - the progress entry, a new file `documentation/progress/<task-id>.md` (for example `progress/BF-17.md`), from the template in `documentation/PROGRESS.md`, at most 10 lines (WP-4), with the fields:
     - Who / tool / model
     - Time: elapsed since the start time in the branch description; own time "not recorded" unless the person volunteered it
     - Scenario spend: in $, or "none"

     Don't ask the person for their own time or for `/usage` (`/usage` is recorded weekly, not per task). Don't edit `PROGRESS.md` itself: not its Log and not "Current position" (`/next-task` works out the position). A new standing note (a step every dev site needs, an open follow-up) goes in the entry and the PR, for Glyn or Alex to add.
   - the task's tick in `DELIVERY_PLAN.md`: `[x]` and "Done (PR #N)", on the task's own line only

   Stage, commit and push it as separate calls (`git add <files>`, `git commit ...`, `git push origin <branch>`).
6. Give the PR link and STOP. Never merge: a person merges on GitHub.
