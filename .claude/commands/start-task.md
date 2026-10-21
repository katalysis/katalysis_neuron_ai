Start delivery plan task $ARGUMENTS.

1. Read `CLAUDE.md` (Workflow and Working agreement), the task $ARGUMENTS in `documentation/DELIVERY_PLAN.md`, any design doc it refers to (e.g. `documentation/EXTENSIONS.md`), and the latest entries in `documentation/PROGRESS.md`.
2. Check you are on `development` with a clean working tree, up to date with `origin/development` (`git fetch origin`). Check nobody else has the task: no branch `task/$ARGUMENTS-*` on `origin` (`git ls-remote --heads origin 'task/*'`) and no open PR for it (`gh pr list`). If it is taken, stop and say so.
3. Create the branch `task/$ARGUMENTS-<short-slug>`. Record the start time on it: `git config branch.<branch>.description "started <date -u +%Y-%m-%dT%H:%MZ>"` (`/finish-task` uses it for the Time field). Push it with `git push -u origin <branch>`, so the task shows as taken.
4. Decide whether the task is **significant** or **small** (CLAUDE.md, Working agreement), and say which and why.
   - **Significant:** write the plan in `documentation/plans/$ARGUMENTS.md` and STOP for approval. Edit no other files until the plan is approved. The plan must cover:
     - **Goal:** one sentence
     - **Files:** each one you will change or create
     - **Out of scope:** what you will deliberately not touch
     - **Tests:** how you will check it works (the PHPUnit tiers, which scenarios and `--repeat` if the scenario rule applies, manual steps)
     - **Risks and questions:** anything unclear in the plan
   - **Small:** implement it without a plan stop. Run the fast tier after every change. Finish with `/finish-task`. The PR and its PROGRESS entry are the report.
