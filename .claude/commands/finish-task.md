Finish the current delivery plan task.

1. Run the full PHPUnit suite (`php tools/phpunit/vendor/bin/phpunit`). Run scenarios only if the task touches prompts, tools, the safety layers, the model or provider, or the Neuron version (CLAUDE.md, Test tiers), and only the ones the plan names. They are an `ask` because they cost money. Report the results honestly, failures included, against the last recorded baseline.
2. Show `git diff --stat development...HEAD`. Confirm that nothing outside the approved plan (or, for a small task, outside its scope) changed, and that nothing was deleted without being in the plan.
3. If the work had to differ from `DELIVERY_PLAN.md` or a design doc, say so clearly. Don't change the plan or design docs yourself; list the proposed change under "Plan changes proposed".
4. Push the branch and open the PR: `gh pr create --base development`, filled in from `.github/pull_request_template.md`. Both prompt for approval.
5. Last commit, now that the PR number is known (prefix: the task ID):
   - the `documentation/PROGRESS.md` entry from the template there, newest at the top of the Log section, with the cost fields:
     - Who / tool / model
     - Time: elapsed since the start time in `git config branch.<branch>.description`, plus the person's own time
     - Cost: `/usage`, and scenario spend

     Ask the person for their own time and their `/usage` figures. If they don't give them, write "not recorded".
   - "Current position" in PROGRESS: the last completed task and the next open one
   - the task's tick in `DELIVERY_PLAN.md`: `[x]` and "Done (PR #N)"

   Push it to the same branch.
6. Give the PR link and STOP. Never merge: a person merges on GitHub.
