Start delivery plan task $ARGUMENTS.

1. Read `CLAUDE.md` (Workflow and Working agreement), the task $ARGUMENTS in `documentation/DELIVERY_PLAN.md`, any design doc it refers to (e.g. `documentation/EXTENSIONS.md`), and the latest entries in `documentation/PROGRESS.md`.
Run each command below as its own Bash call: no `&&` or `;` chains, and no `cd` prefix (the workspace root is the package folder). One command per call matches the allow rules in `.claude/settings.json` and keeps each permission prompt to one command.

2. Find the task's **base branch** in `DELIVERY_PLAN.md`: the integration branch its phase's **Branching** note names for it (e.g. `integration/neuron4` for P2.2–P2.6 and P2.8), otherwise `development`. Say which. Integration branches are protected like `development`: PRs only.
3. Check nobody else has the task and that you start from a clean, current base branch:
   1. `git switch <base>`
   2. `git fetch origin`
   3. `git status -sb`: on `<base>`, clean, not behind `origin/<base>` (if behind, `git merge --ff-only origin/<base>`)
   4. `git ls-remote --heads origin 'task/*'`: no branch `task/$ARGUMENTS-*`
   5. `gh pr list`: no open PR for it

   If it is taken, stop and say so.
4. Create the branch and mark the task as taken:
   1. `git switch -c task/$ARGUMENTS-<short-slug>`
   2. `git config branch.<branch>.description "started <date -u +%Y-%m-%dT%H:%MZ>; base <base>"` (`/finish-task` uses it for the Time field and the PR base)
   3. `git push -u origin <branch>`
5. Decide whether the task **stops for a plan** (CLAUDE.md, Working agreement, WP-4), and say which and why. It stops only if it touches the DB schema; the approval, permission or tier mechanisms (P3.5-x, P3.2-1, P3.6-x, P3.6a); dependencies; or deletes working code its `DELIVERY_PLAN.md` entry doesn't list.
   - **Plan stop:** write the plan in `documentation/plans/$ARGUMENTS.md`, on one page: the files, the decisions with your recommendation, and the risks. Commit it as `PLAN:`, push, and STOP for approval; say where the plan is, without repeating it. Edit no other files until it is approved.
   - **Otherwise (tool and prompt changes included):** implement it directly. When a question is inside the task's entry and you have a recommendation, go with it and list it under "Decisions taken" in the PR. Red first for safety work and bug fixes; for a change to what a read tool returns, one integration test per tool. Run the fast tier after every change. Scenarios: a free `--fake` run of the ones the task touches and ×1 of its own new or changed ones, as its entry names. Finish with `/finish-task`; the PR is the report.
