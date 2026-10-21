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
5. Decide whether the task is **significant** or **small** (CLAUDE.md, Working agreement), and say which and why.
   - **Significant:** write the plan in `documentation/plans/$ARGUMENTS.md` and STOP for approval. Edit no other files until the plan is approved. The plan must cover:
     - **Goal:** one sentence
     - **Files:** each one you will change or create
     - **Out of scope:** what you will deliberately not touch
     - **Tests:** how you will check it works (the PHPUnit tiers, which scenarios and `--repeat` if the scenario rule applies, manual steps)
     - **Risks and questions:** anything unclear in the plan
   - **Small:** implement it without a plan stop. Run the fast tier after every change. Finish with `/finish-task`. The PR and its PROGRESS entry are the report.
