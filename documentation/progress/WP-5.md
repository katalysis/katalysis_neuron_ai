### 2026-10-28: WP-5 No more PROGRESS conflicts
- Branch / PR: `task/WP-5-progress-files`, PR #79 into `development`. No plan stop (docs and commands only).
- Done: one progress file per task in `documentation/progress/`; task PRs no longer edit `PROGRESS.md` (its Log and "Current position", now standing notes); `/next-task` works out the last done and next open task from the newest progress file and DELIVERY_PLAN's ticks; `/start-task`, `/finish-task`, `CLAUDE.md` and the PR template match.
- Not done / left for later: the old Log entries stay in `PROGRESS.md` (as asked); "Current position"'s last-completed and next-open bullets are Glyn's or Alex's to trim.
- Checks: PHPUnit full OK (1514); JS 55/55; no scenarios (no code).
- Who / tool / model: Glyn; Claude Code (VS Code); claude-opus-5-5
- Time: about 3 minutes by the branch clock (10:48Z–10:51Z); own time not recorded
- Scenario spend: none
- Plan changes proposed: none. Questions for review: none.
