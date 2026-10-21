# WP-1 plan: agent tooling and working practice

Approved by Glyn on 21 October 2026, with the decisions below. Where a decision and the body differ, the decision wins; the body has been updated to match.

## Decisions (Glyn, 21 October 2026)
1. **pre-push:** block direct pushes to both `main` and `development`.
2. **PHPUnit:** every PHPUnit run is allowed without a prompt, the full suite included. Tests are dev-guarded and never call the provider. The scenario runner stays on ask, because it costs money and calls the AI.
3. **Backups:** `NEURON_BACKUP_DIR` only, with no default. If it's unset, the wrapper stops with a clear message. It also refuses any path inside the repo or the web root.
4. **Alex:** macOS and Herd. The hooks need nothing beyond `php` and `git` on `PATH`, so there's no Linux branch and no `jq`.
5. **Significant task:** no time threshold, because an agent can't judge time reliably. It's defined by what the task touches: prompts, tools, the safety layers, permissions, DB schema or fixtures, dependencies, or anything in `BEHAVIOUR_SPEC.md`. Everything else is a small task: implement, then report.
6. **Findings file:** stays as given. The rule belongs in the working agreement.
7. **DELIVERY_PLAN §6** is updated in this PR. Scenarios run when a task touches prompts, tools, the safety layers, the model or provider, or the Neuron version, and at every phase exit. They don't run on every merge.
8. **Guard hook:** unit-test the bypass forms as well as the plain ones: `git -c ... push`, `git push origin +main`, refspecs such as `HEAD:main`, `DROP` in `mysql -e` and in heredocs, and `--no-verify` anywhere. CLAUDE.md records that the guard doesn't fail safe if PHP is missing or the hook errors; the deny rules are the backstop.
9. **P1.14 tick:** already on `development` (`a3133cf`: PR #27, "PR pending" changed to #27, Phase 1 closed), so nothing is added here.
10. **"Verify" items:** each one is settled during implementation and the outcome goes in the PR description. If one fails, use the simplest fallback and say what was chosen.

Findings from the previous Copilot agent: [WP-1-findings.md](WP-1-findings.md). Permission and hook behaviour below was checked against the current Claude Code docs (code.claude.com/docs/en/permissions, /hooks, /settings-reference, /vs-code) on 21 October 2026. Points the docs don't settle are marked **verify**, and each is checked during implementation.

**Goal:** Glyn and Alex run Claude Code with the same enforced rules. Risky commands are blocked or need a prompt; routine checks don't. Tests come in three tiers, and each PROGRESS entry records who did the work, with what, how long it took and what it cost.

## 1. Basics

- **Workspace root:** open `httpdocs/packages/katalysis_neuron_ai` in VS Code, not `katalysis_pro_theme`. `CLAUDE.md`, `.claude/settings.json`, `.claude/commands/` and the hooks then load. Project `allow` rules and `env` only apply after each person accepts the workspace trust dialog once; `deny` and `ask` rules apply straight away.
- **Paths:** no committed file contains an absolute or Herd-specific path. Hooks use `${CLAUDE_PROJECT_DIR}`. Scripts find the Concrete root from their own location. Rules use project-relative (`/vendor/**`) or any-depth (`//**/name`) patterns. Commands assume `php`, `mysql` and `mysqldump` are on `PATH`; how each person gets them there goes in SETUP.md (Herd for Glyn).
- **`.claude/settings.local.json`** (already gitignored, so `.gitignore` needs no change): personal settings only. Glyn's would be:
  ```json
  { "env": { "NEURON_BACKUP_DIR": "~/Herd/backups" } }
  ```
  plus `"NEURON_NOTIFY": "off"` for anyone who doesn't want notifications. **Verify** that `env` reaches Bash tool commands and hooks, and whether `~` is expanded there (if not, the script expands it).
- **CLAUDE.md updates:**
  - The header says Claude Code in VS Code is the tool and the package folder is the workspace root. `development` and `main` are protected and PR-only.
  - "Herd's PHP" becomes "PHP 8.2+ on `PATH`".
  - The rule against changing `DELIVERY_PLAN.md` gets one more exception: the task's own tick and PR number, and the "Current position" update in PROGRESS, go inside the task PR.
  - The commit prefix rule covers `WP-n` IDs as well as `P` IDs.
  - The Testing section gets the tiers (section 6).
  - A new "Working agreement" section (section 8).
- **SETUP.md:** a "New developer" section: open the package folder as the workspace root, run `git config core.hooksPath .githooks`, create `settings.local.json` (above), and accept the trust dialog.
- **DELIVERY_PLAN §6:** reworded in this PR (decision 7).

## 2. Permissions (`.claude/settings.json`)

Rules are checked in the order deny, ask, allow; the first match wins, and a deny in any file beats an allow in any file. Rules match each part of a chained command (`&&`, `;`, `|`) on its own, so an allowed `git status && rm x` still prompts for the `rm`. Claude Code already auto-allows read-only commands (`ls`, `cat`, `grep`, `find` without `-exec`/`-delete`, `head`, `tail`, `wc`, `diff`, read-only `git`), so the allow list is short.

```json
{
  "$schema": "https://json.schemastore.org/claude-code-settings.json",
  "permissions": {
    "allow": [
      "Bash(php tools/phpunit/vendor/bin/phpunit *)",
      "Bash(php tools/phpunit/vendor/bin/phpunit)",
      "Bash(php -l *)",
      "Bash(git status *)", "Bash(git diff *)", "Bash(git log *)", "Bash(git show *)",
      "Bash(git branch --show-current)", "Bash(git fetch *)",
      "Bash(gh pr view *)", "Bash(gh pr list *)", "Bash(gh pr diff *)", "Bash(gh pr checks *)",
      "Bash(gh auth status)",
      "Bash(documentation/fixtures/backup_dev_db.sh *)"
    ],
    "ask": [
      "Bash(git commit *)", "Bash(git push *)", "Bash(gh pr create *)", "Bash(gh pr edit *)",
      "Bash(git merge *)", "Bash(git rebase *)", "Bash(git restore *)", "Bash(git checkout -- *)",
      "Bash(git stash *)", "Bash(gh api *)",
      "Bash(mysql *)", "Bash(mysqldump *)",
      "Bash(php ../../concrete/bin/concrete *)",
      "Bash(composer *)", "Bash(rm *)",
      "Edit(/composer.json)", "Edit(/controller.php)"
    ],
    "deny": [
      "Read(//**/.env)", "Read(//**/.env.*)", "Edit(//**/.env)", "Edit(//**/.env.*)",
      "Read(//**/*.pem)", "Read(//**/*.key)", "Read(~/.ssh/**)",
      "Read(//**/application/config/database.php)",
      "Read(//**/application/config/generated_overrides/katalysis.php)",
      "Edit(/vendor/**)", "Write(/vendor/**)",
      "Bash(git push --force *)", "Bash(git push * --force*)", "Bash(git push -f *)", "Bash(git push * -f)",
      "Bash(git push * +*)", "Bash(git push --no-verify *)", "Bash(git push * --no-verify*)",
      "Bash(git push * --delete *)", "Bash(git push * :*)",
      "Bash(git push * main)", "Bash(git push * HEAD:main)", "Bash(git push * development)",
      "Bash(git commit * --no-verify*)", "Bash(git commit -n *)",
      "Bash(git reset --hard *)", "Bash(git filter-branch *)", "Bash(git config core.hooksPath *)",
      "Bash(gh repo edit *)", "Bash(gh repo delete *)", "Bash(gh pr merge *)",
      "Bash(mysqladmin *)", "Bash(rm -rf *)"
    ]
  }
}
```

Notes:
- **PHPUnit:** the brief allows the fast tier. I propose allowing every PHPUnit run, the full suite included. Every integration test sits behind the dev-host guard and the leak check, no test calls the provider, and a prompt before a 35-second run adds nothing. *Question 2.*
- **Scenarios** go through `concrete/bin/concrete` and cost money, so they stay in **ask**. **Verify** that `c5:exec` works from the package root with `../../concrete/bin/concrete` and a package-relative script path. If it doesn't, add a small wrapper, `tests/Eval/run.sh`, that changes into `httpdocs/` itself.
- **Pattern matching has gaps:**
  - `Bash` rules are case-sensitive and can't see every spelling (for example `git -c ... push`, or a refspec built in a variable). They can't catch `DROP` inside a `mysql -e` either.
  - So a **PreToolUse guard hook** also checks each Bash command: `.claude/hooks/guard-bash.php`, run with `php "${CLAUDE_PROJECT_DIR}/.claude/hooks/guard-bash.php"`.
  - It reads `tool_input.command` and exits 2 with a reason when it finds any of these:
    - a force push (`--force`, `-f`, `--force-with-lease`, `+refspec`)
    - `--no-verify`, or `-n` on a commit
    - a push to `main` or `development`, or deleting either
    - any change to `core.hooksPath`
    - `DROP DATABASE|SCHEMA|TABLE` or `TRUNCATE` in any case, and `mysqladmin`
    - `gh repo edit|delete`, `gh pr merge`, or `gh api` with a non-GET method
    - a command naming `database.php`, `generated_overrides` or `.env`
  - It's written in PHP because both developers have PHP and it needs no `jq`. It gets a unit test.
  - A hook can block but can't approve, so it can't weaken the rules.
- **Concrete config:** `database.php` and `generated_overrides/katalysis.php` (the API keys) are outside the workspace. Reading files outside the workspace already prompts, and the `//**/` deny rules plus the guard make it a hard no.
- **Unchanged from the current file:** the vendor and `rm` rules, and `Edit(composer.json)` / `Edit(controller.php)` as asks. The vendor rules are re-anchored to `/vendor/**`: unanchored, `vendor/**` in a deny rule matches a `vendor` folder at any depth.
- **Removed:** the `git checkout main` / `git switch main` denies. Reading `main` is harmless, and pushing to it is what the pre-push hook and branch protection stop.
- **Verify:** that the existing `:*` rules and the new space-`*` rules behave the same, and that the whole list loads. I'll check each rule in `/permissions`.

## 3. Notifications

- `.claude/hooks/notify.php`, wired to two events:
  - `Notification` (`permission_prompt`, `idle_prompt`): Claude is waiting for you.
  - `Stop`: the turn has finished.
- Uses `osascript` (part of macOS); if that isn't there, it rings the terminal bell. It needs only `php`. The message is the hook's `message` (Notification) or "Claude Code finished" plus the folder name (Stop).
- **Opting out:** a committed hook can't be switched off on its own from a local file, so the script exits quietly when `NEURON_NOTIFY=off`.
- **Verify** in the VS Code extension. The docs say hooks fire the same in IDE extensions, and the extension has no desktop notifications of its own.

## 4. Git hooks

- `.githooks/pre-commit`: refuses staged changes under `vendor/` unless `ALLOW_VENDOR=1` is set. Only for a planned dependency upgrade PR, as CLAUDE.md rule 8 says.
- `.githooks/pre-push`: reads the refs git passes in and refuses:
  - a push to `main`
  - a push to `development` (decision 1)
  - deleting `main` or `development`
  - any non-fast-forward update (the remote commit is not an ancestor of the local one)
- **Setup, once per clone:** `git config core.hooksPath .githooks`. The files are committed as executable.
- `--no-verify` skips both hooks. Claude can't use it (deny rule and guard), but a person can, so branch protection on GitHub stays the real guard.

## 5. Backup wrapper

- `documentation/fixtures/backup_dev_db.sh <task-id>`, for example `backup_dev_db.sh WP-1`.
- Writes `${NEURON_BACKUP_DIR}/<db>_before_<task-id>_<YYYYMMDD_HHMMSS>.sql`, the same naming as the existing dumps, then `chmod 600` and prints the path and size.
- Reads host, port, database, user and password from `application/config/database.php`, found relative to the script, through PHP. The password goes in `MYSQL_PWD`, never on the command line, and is never printed.
- Refuses when:
  - `NEURON_BACKUP_DIR` is unset (no default; decision 3)
  - the directory is inside the repo, the web root, or any git work tree
  - the host isn't `127.0.0.1` or `localhost`
  - the label isn't a task ID shape (`[A-Za-z0-9.-]+`)
- Uses the same `mysqldump` flags as SETUP.md.
- Restores stay manual (`mysql`, an **ask**), because a restore replaces the whole database.

## 6. Test tiers

| Tier | Command | When | Time (measured today) |
|---|---|---|---|
| Fast | `php tools/phpunit/vendor/bin/phpunit --exclude-group slow` | after every change | 425 tests, 8.5 s |
| Full | `php tools/phpunit/vendor/bin/phpunit` | before every PR (in `/finish-task`) | 437 tests, 32–35 s |
| Scenarios | `run_scenarios.php` via `c5:exec` (ask) | when the task touches prompts, tools, the safety layers, the model or provider, or the Neuron version, and at every phase exit (decision 7). The plan says which scenarios and `--repeat`. | median 7.5 s per run; full set × 3 about 11 min, about $5 |

- `SeedResetTest` gets `#[Group('slow')]`. That is its only change.
- No other class goes in `slow`. The next slowest, `TaskPermissionAccessTest` (2 s) and `DraftIsolationTest` (1.6 s), are safety tests, and they cost little.
- A new test over about 2 s gets `slow` and a note in PROGRESS.
- The merge rule for scenarios (no regressions against the baseline) doesn't change. It applies to the scenarios a task runs.

## 7. Commands

- `/start-task` and `/finish-task` are updated for the new workflow (section 8): only significant tasks stop for plan approval; the start time is recorded; ticks go inside the PR; the full tier runs before the PR, and scenarios only when the tier rule says so; the PR is created as an ask.
- **`/next-task`: worth having, mainly because two people now share the plan.** It changes nothing. It:
  - reads PROGRESS "Current position" and the open items in DELIVERY_PLAN
  - runs `gh pr list` and `git ls-remote --heads origin 'task/*'`, so a task with a pushed branch or open PR shows as taken
  - lists the next one to three open tasks, with their dependencies and whether each is significant, then suggests `/start-task <ID>`
- To make "taken" visible, `/start-task` pushes the new branch straight away (an ask).
- The alternative, `/start-task` with no ID doing the same, saves one command but mixes reading and starting. I recommend the separate command.

## 8. Working agreement (new CLAUDE.md section, draft)

> ### Working agreement
> **Significant tasks** stop for plan approval after `/start-task`. A task is significant if it touches prompts (`instructions()`, guidelines), tools, the safety layers (draft isolation, injection detection, disabled tools, approval), permissions, the DB schema or fixtures, dependencies, or anything in `BEHAVIOUR_SPEC.md`. **Everything else** is a small task: implement it, then report with the PR and its PROGRESS entry. If unsure, treat it as significant.
>
> **Always stop and ask** (whatever the size) for any item in the Workflow "Stop and ask" list, for any baseline or expected-value change, before running paid scenarios not named in the plan, and before any action an `ask` rule prompts for.
>
> **PR contents, in commit order:** (1) `PLAN:` plan or DELIVERY_PLAN entry, (2) tests, red first where there is a behaviour change, (3) code, (4) docs, (5) last, after `gh pr create` so the number is known: the PROGRESS entry, the "Current position" update and the DELIVERY_PLAN tick with "Done (PR #N)", all prefixed with the task ID. Nothing is pushed straight to `development` or `main`.
>
> **PROGRESS cost fields**, in every entry:
> - `Who / tool / model:` e.g. `Glyn; Claude Code (VS Code); claude-opus-5-5`
> - `Time:` elapsed from `/start-task` to the PR (from the recorded start time), and the person's own time, estimated by them
> - `Cost:` Claude Code usage from `/usage` ($ and tokens for API-key users; "subscription, n% of session limit" for plan users), plus scenario provider spend in $ (from the runner's results), or "none"

The PROGRESS entry template and `.github/pull_request_template.md` get the same three fields, and the PR template gets the test tiers as checkboxes.

## 9. Old Copilot auto-approvals to remove (Glyn, by hand; I don't edit these files)

**User settings** (`~/Library/Application Support/Code/User/settings.json`), in `chat.tools.terminal.autoApprove`:
- `"npx": true`: runs any npm package.
- `"sed": true`: `sed -i` edits files.
- `"\"$P\"": true`: approves any command run through the `$P` PHP variable.
- `"chat.agent.maxRequests": 50` (top level). It no longer matters once Copilot isn't used, so remove it with the others.
- Optional: `"git remote": true` (it can add or change remotes). `gulp`, `/^npx gulp sass$/`, `diff` and `git check-ignore` are harmless and can stay if Copilot is still used for theme work.

**Workspace settings** (`katalysis_pro_theme/.vscode/settings.json`, not tracked by git, so this is a local file): delete the whole `chat.tools.terminal.autoApprove` block. It contains `mysql`, `m`, `git checkout`, `git add`, `git commit`, `fold`, `EOF`, `"\"/Users/studio4/Library/Application"` (any Herd PHP, including `php -r` and any `c5:exec` script), the `export MYSQL_PWD=...` regex, and one-off regexes with absolute paths. None of it applies to Claude Code, which no longer opens that folder as the root.

## Files

Change: `CLAUDE.md`, `.claude/settings.json`, `.claude/commands/start-task.md`, `.claude/commands/finish-task.md`, `documentation/SETUP.md`, `documentation/PROGRESS.md` (template, entry), `documentation/DELIVERY_PLAN.md` (§6 wording, decision 7; WP-1 tick), `.github/pull_request_template.md`, `tests/Integration/SeedResetTest.php` (the group attribute only).

Create: `.claude/hooks/guard-bash.php`, `.claude/hooks/notify.php`, `.claude/commands/next-task.md`, `.githooks/pre-commit`, `.githooks/pre-push`, `documentation/fixtures/backup_dev_db.sh`, `tests/Unit/BashGuardTest.php`.

## Out of scope

- `vendor/` and all `src/` code.
- User-level files and the `katalysis_pro_theme` workspace file (Glyn removes those by hand, from section 9).
- GitHub branch protection and the fine-grained token (Glyn).

## Tests

- **`BashGuardTest` (unit):** every blocked form in section 2 and the bypass forms in decision 8, the allowed look-alikes (`git push origin task/x`, `git log --force-something`, `SELECT ... dropdown`), and the exit codes.
- **Git hooks, in a throwaway repo under the scratchpad:**
  - pre-commit refuses a staged `vendor/` file, and allows it with `ALLOW_VENDOR=1`
  - pre-push refuses `main`, `development`, a deletion and a non-fast-forward push
  - pre-push allows a normal task branch push
- **Backup script:** one real dump labelled `WP-1`; mode 600, size printed, no password in the output; refusals checked with the variable unset and with a non-local host (faked through an env override that exists only for that test).
- **Notifications:** one permission prompt and one finished turn in the VS Code extension.
- **Rules:** `/permissions` shows them all; the fast tier runs without a prompt; `git commit` and `gh pr create` prompt; `git push --force` and `cat ../../application/config/database.php` are refused.
- **PHPUnit:** fast tier and full tier pass (425 and 437).
- **Scenarios:** none. WP-1 touches no prompts, tools or safety layers.

## Questions for review

Answered: see Decisions at the top.
