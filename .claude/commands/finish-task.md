Finish the current delivery plan task.

Run each command as its own Bash call: no `&&` or `;` chains, and no `cd` prefix. If a commit message mentions a word the Bash guard blocks or asks about (for example `database.php`, `.env`, `core.hooksPath`, a SQL keyword or `run_scenarios`), write the message to a file in the scratchpad and commit with `git commit -F <file>`.

The **base branch** is the one `/start-task` recorded: `git config branch.<branch>.description` ends `; base <base>`. If it is missing, take it from `DELIVERY_PLAN.md` as `/start-task` step 2 does (an integration branch named in the phase's Branching note, otherwise `development`).

1. Run the full PHPUnit suite (`php tools/phpunit/vendor/bin/phpunit`). Run scenarios only if the task touches prompts, tools, the safety layers, the model or provider, or the Neuron version (CLAUDE.md, Test tiers), and only the ones the plan names. They are an `ask` because they cost money. Report the results honestly, failures included, against the last recorded baseline.
2. Show `git diff --stat <base>...HEAD`. Confirm that nothing outside the approved plan (or, for a small task, outside its scope) changed, and that nothing was deleted without being in the plan.
3. If the work had to differ from `DELIVERY_PLAN.md` or a design doc, say so clearly. Don't change the plan or design docs yourself; list the proposed change under "Plan changes proposed".
4. Push the branch and open the PR, as two calls:
   1. `git push origin <branch>` (allowed for `task/*` and `spike/*`)
   2. `gh pr create --base <base>`, filled in from `.github/pull_request_template.md` (prompts for approval)
5. Last commit, now that the PR number is known (prefix: the task ID):
   - the `documentation/PROGRESS.md` entry from the template there, newest at the top of the Log section, with the cost fields:
     - Who / tool / model
     - Time: elapsed since the start time in the branch description, plus the person's own time
     - Cost: `/usage`, and scenario spend

     Ask the person for their own time and their `/usage` figures. If they don't give them, write "not recorded".
   - "Current position" in PROGRESS: the last completed task and the next open one
   - the task's tick in `DELIVERY_PLAN.md`: `[x]` and "Done (PR #N)"

   Stage, commit and push it as separate calls (`git add <files>`, `git commit ...`, `git push origin <branch>`).
6. Give the PR link and STOP. Never merge: a person merges on GitHub.
