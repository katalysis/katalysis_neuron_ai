# WP-1 findings (Copilot agent, 21 Oct 2026)

## Instruction loading (Copilot; now moot)
- VS Code read instructions only from the workspace root (then katalysis_pro_theme), so CLAUDE.md was never auto-loaded; rules held only because prompts said to read it.
- .claude/commands and .claude/settings.json had no effect under Copilot.

## Broad auto-approvals to remove
- Workspace (.vscode/settings.json in katalysis_pro_theme): mysql, m, git checkout, git add, git commit, fold, EOF, "\"/Users/studio4/Library/Application" (approves any Herd PHP, incl. php -r and any c5:exec script), plus about a dozen one-off regexes.
- User settings: sed (sed -i edits files), npx (runs arbitrary packages), "\"$P\"" (approves any PHP).
- User chat.agent.maxRequests = 50.

## Test timings (measured)
- Full PHPUnit: 437 tests, 2,636 assertions, 35 s wall; ~5 s unattributed setup (boot + leak snapshot). Unit suite < 1 s.
- Slowest classes: SeedResetTest 20.8 s (60%), TaskPermissionAccessTest 1.95 s, DraftIsolationTest 1.6 s, SettingsSavePermissionTest 0.9 s; all others < 0.6 s.
- Excluding SeedResetTest: about 13 s.
- Scenario 12 × 3: 27 s wall, 18 s in provider turns; about 3 s overhead per run (boot + reset).
- Per scenario run: 3.5–16.5 s (median 7.5 s); at 3 repeats, 15–40 s and about $0.15–0.50 per scenario.
- 5-scenario set × 3: about 2 min, $1–1.6.
- Full run (20 × 3): 467 s provider time, $4.95; about 11 min wall (estimate, not measured).
- The scenario 12 run showed a failed secondary reply check on turn 2 (known unstable; not investigated).

## Git hook design
- Tracked `.githooks/`, enabled per clone with `git config core.hooksPath .githooks` (document in SETUP.md).
- pre-commit: block staged changes under vendor/ (710 tracked files); override `ALLOW_VENDOR=1` for planned dependency upgrades only.
- pre-push: block pushes to main, deleting main or development, and non-fast-forward pushes.
- `--no-verify` bypasses hooks, so server-side branch protection is the real guard.

## Backup wrapper
- `documentation/fixtures/backup_dev_db.sh <label>`: reads the DB password from the Concrete config, dumps to ~/Herd/backups/prodb_<label>_<timestamp>.sql, chmod 600, prints the size.
- Auto-approve only this script; raw mysqldump can't be pattern-matched safely (e.g. `--result-file=` elsewhere).

## Other notes
- Prefer `| tail` / `| cut` to `> /tmp/file` redirects in canonical commands (redirects may trigger write checks).
