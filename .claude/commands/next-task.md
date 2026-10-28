Show which delivery plan tasks are open and not taken. Read-only: change nothing, create no branch.

1. Work out where the project is (WP-5: no task PR edits a "Current position" any more):
   1. `git fetch origin`
   2. **Last done:** the newest progress entry, the file most recently added to `documentation/progress/` on `origin/development`: `git log origin/development --diff-filter=A --name-only --format= -- documentation/progress/`, first `.md` that isn't `README.md`. Read it (its "Not done / left for later" and "Plan changes proposed" matter). If the folder has none yet, the newest entry is the top of `documentation/PROGRESS.md`'s Log.
   3. **Open tasks:** the `- [ ]` items in `documentation/DELIVERY_PLAN.md` on `origin/development`, in the order the plan lists them: the current phase and stage first (the first stage with an open task), then the `BF` and `WP` tasks. A task whose "Depends on" names a task that isn't ticked `[x]` is not ready. An open `BF` or `WP` task that the latest entries or Glyn's instructions put before the next phase task comes first.
   4. Read `documentation/PROGRESS.md`'s "Current position" for its standing notes (steps each dev site needs, open follow-ups) and the Decisions.
2. Find what's taken: `git ls-remote --heads origin 'task/*'` and `gh pr list --state open`. A task with a pushed `task/<ID>-*` branch or an open PR is taken; say who has it if the PR shows it.
3. List the next one to three tasks that are open and not taken. For each, give:
   - its ID and title
   - what it depends on (e.g. "after P1.13") and whether that is merged
   - whether it is significant or small (CLAUDE.md, Working agreement), with the reason
   - whether the scenario rule will apply
4. Say which task was done last (from step 1.2) and any standing dev-site step not yet done on this site, if the notes say so.
5. Suggest `/start-task <ID>` for the first one, and stop.
