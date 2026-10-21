Show which delivery plan tasks are open and not taken. Read-only: change nothing, create no branch.

1. Read "Current position" in `documentation/PROGRESS.md` and the open (`- [ ]`) items in `documentation/DELIVERY_PLAN.md`: the current phase first, then the `WP` working-practice tasks.
2. Find what's taken: `git fetch origin`, `git ls-remote --heads origin 'task/*'` and `gh pr list --state open`. A task with a pushed `task/<ID>-*` branch or an open PR is taken; say who has it if the PR shows it.
3. List the next one to three tasks that are open and not taken. For each, give:
   - its ID and title
   - what it depends on (e.g. "after P1.13") and whether that is merged
   - whether it is significant or small (CLAUDE.md, Working agreement), with the reason
   - whether the scenario rule will apply
4. Suggest `/start-task <ID>` for the first one, and stop.
