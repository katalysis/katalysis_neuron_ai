# CLAUDE.md: Katalysis Neuron AI

A Concrete CMS 9.3+ package (PHP 8.1+) that adds an AI assistant to the CMS. It's built on Neuron AI (`neuron-core/neuron-ai`, bundled in `vendor/`). It's on 3.15.26 now, and an upgrade to 4.x is planned in Phase 2 of the delivery plan.

Dev site: `katalysis_pro_theme` (Concrete 9.5.5, run locally with Herd). Integration branch: `development`, except where `DELIVERY_PLAN.md` names an `integration/*` branch for a task: those task branches branch from it and PR into it. In Phase 2, P2.2–P2.8 used `integration/neuron4`, which P2.9 merged into `development` (PR #47) and which was then deleted. `main`, `development` and any `integration/*` branch are protected: changes go in only through PRs, with no force pushes and no deletion.

**Tooling:** Glyn and Alex use the Claude Code VS Code extension with this package folder (`httpdocs/packages/katalysis_neuron_ai`) as the workspace root, so this file, `.claude/settings.json` (permission rules and hooks) and `.claude/commands/` apply. Copilot is no longer used. First-time setup: `documentation/SETUP.md`, "New developer setup".

**Read first:** `documentation/DELIVERY_PLAN.md` covers the product tiers, guard rails, architecture and the current phase. `documentation/KATALYSIS_NEURON_AI_PLAN.md` holds the tool implementation policy.

## Workflow (required)
- `/next-task` shows what's open and not yet taken. Start every task with `/start-task <ID>` and end it with `/finish-task`. Work only on tasks listed in `documentation/DELIVERY_PLAN.md` (`P` tasks and `WP` working-practice tasks).
- **Never** change `DELIVERY_PLAN.md`, `EXTENSIONS.md` or the decisions in `PROGRESS.md`. Propose changes in your progress entry instead. Glyn or Alex decide, with design review in Cowork.
  - Exception: you may fix purely factual or formatting slips in `DELIVERY_PLAN.md` and `PROGRESS.md` (PR numbers, status lines, markdown rendering, typos) and must list each one in your reply. Anything that changes scope, rules or decisions still goes to Glyn.
  - Exception: the task's own tick in `DELIVERY_PLAN.md` ("Done (PR #N)") and its progress entry go inside the task PR, as its last commit (see Working agreement).
- Stop and ask whenever any of these apply:
  - the task needs files outside the approved plan
  - a test or scenario that used to pass now fails
  - you're about to delete more than about 20 lines of working code **that the approved plan didn't list for deletion** (planned deletions, such as `src/Toolkits/` in P3.3-3, are covered by plan approval)
  - a design doc and the code disagree
- **Commit messages** start with the task ID (`P1.4: ...`, `WP-1: ...`). Changes to the plan, design or workflow docs made after a review start with `PLAN: ...`.
- **Don't rewrite pushed or shared history.** Never amend or reword commits someone else made, even unpushed ones, unless you're asked to.
- Report results honestly. "Not tested" is an acceptable answer; claiming something works when it wasn't checked is not.
- Before reporting test results or opening a PR, check git status is clean: the results must be from committed code.

## Working agreement
- **A task stops for plan approval** after `/start-task` only if it touches (WP-4, Glyn, 27 October 2026):
  - the DB schema
  - the approval, permission or tier mechanisms (P3.5-x, P3.2-1, P3.6-x, P3.6a)
  - dependencies
  - deleting working code that its `DELIVERY_PLAN.md` entry doesn't list

  **Every other task, including tool and prompt changes, is implemented directly, and the PR is the report.** A plan, when needed, fits on one page: files, decisions with your recommendation, risks. At a plan stop, say where the plan is; Glyn's reviewer reads plans and PRs from the repo, so don't repeat the plan in the reply.
- **Decisions inside the task:** when a question is inside the task's `DELIVERY_PLAN.md` entry and you have a recommendation, go with it and list it under "Decisions taken" in the PR description. Glyn overrules at review. This doesn't change the "always stop" list below.
- **Always stop and ask**, whatever the size: anything in the Workflow "Stop and ask" list, any change to a scenario's expected values or the baseline, running paid scenarios the plan doesn't name, and any command that prompts: the `ask` rules (`gh pr create`, `mysql`, the scenario runner, `composer require|update`, deleting a branch, discarding changes) and anything not allowed, such as a push to a branch other than `task/*` or `spike/*`. Commits and pushes to `task/*` and `spike/*` are allowed (WP-1a). Run commands one per call, not in `&&` chains, so the allow rules match.
- **PR contents, in commit order:**
  1. `PLAN:` the plan, or the `DELIVERY_PLAN.md` entry for a new task
  2. tests, red first when behaviour changes
  3. code
  4. docs
  5. last, after `gh pr create` so the number is known: the progress entry and the `DELIVERY_PLAN.md` tick ("Done (PR #N)"), with the task ID prefix

  Nothing is pushed straight to `development` or `main`.
- **Stacked branches (Glyn, after BF-18):** if a task starts while another of your PRs that edits `DELIVERY_PLAN.md` is still open, branch it from that PR's branch, not `development`, and say so in the PR (stacked, as P3.5-1 did). GitHub retargets it when the first merges.
- **Progress entries (WP-5):** one new file per task, `documentation/progress/<task-id>.md`, from the template in `PROGRESS.md`. A task PR doesn't edit `PROGRESS.md`: not its Log (the entries before WP-5 stay there) and not "Current position", which is now standing notes kept by Glyn or Alex. `/next-task` works out the last task done and the next open one from `DELIVERY_PLAN.md`'s ticks and the newest progress file. A new standing note goes in the entry and the PR.
- **Progress entries** are at most 10 lines (WP-4). `BEHAVIOUR_SPEC.md`'s "Today" column is updated in each stage's comparison task (P3.S1–P3.S4), not in each task.
- **Progress fields**, in every entry. Write "not recorded" rather than guess.
  - `Who / tool / model`: e.g. `Glyn; Claude Code (VS Code); claude-opus-5-5`
  - `Time`: elapsed from `/start-task` to the PR. Own time is "not recorded" unless the person volunteers it; don't ask for it.
  - `Scenario spend`: in $ (the runner's estimate), or "none"

  Claude Code usage (`/usage`) is not recorded per task: Glyn and Alex record their weekly `/usage` limit percentages separately.
- **Guards and their limits:**
  - `.claude/hooks/guard-bash.php` (PreToolUse, Bash) blocks:
    - force pushes, and pushes to or deleting `main`, `development` or `integration/*`, however they are written
    - `--no-verify`, `git commit -n` and changes to `core.hooksPath` (reading it, `git config core.hooksPath`, is allowed)
    - `DROP`, `TRUNCATE` and `mysqladmin`
    - `gh pr merge`, `gh repo edit|delete` and non-GET `gh api`
    - commands that name `database.php`, `generated_overrides` or `.env`

    It **asks** (a prompt even when an allow rule matches, WP-1b) for any command containing `run_scenarios`, however it is written, for `curl` unless it only reads from `katalysis-pro.test`, and for a `git push` run in another repository (`git -C <path>`, `--git-dir`, `--work-tree`; P2.8), whose branch it can't check.

    **The guard does not fail safe.** If PHP is missing or the hook errors, Claude Code treats that as a non-blocking hook error and the command goes on to the permission rules. The deny rules in `.claude/settings.json` are the backstop. Don't work around a block: report it.
  - `.githooks/` (pre-commit: `vendor/`; pre-push: `main`, `development`, `integration/*`, non-fast-forward) is enabled once per clone with `git config core.hooksPath .githooks`. `--no-verify` skips it, so GitHub branch protection is the real guard.
  - The `allow` rules in `.claude/settings.json` apply only after you accept the workspace trust dialog once. The `deny` and `ask` rules always apply.
  - `defaultMode` is `acceptEdits` (WP-1a):
    - **Accepted without a prompt:** file edits inside the package, and the filesystem commands that mode covers (`mkdir`, `touch`, `mv`, `cp`, `sed`; `rm` and `rmdir` too, but the `rm *` ask rule still prompts for them).
    - **Rules still win:** deny, then ask, then allow, whatever the mode. So `Edit(/vendor/**)` is still denied, and `composer.json` and `controller.php` still prompt.
    - **VS Code:** the extension may not take its starting mode from project settings. See SETUP.md.
- **Personal settings** go in `.claude/settings.local.json` (gitignored), for example `NEURON_BACKUP_DIR` and `NEURON_NOTIFY=off` under `env`. Committed files contain no absolute or machine-specific paths.

## Layout
- `controller.php`: package controller (routes, chat panel injection, install/upgrade)
- `controllers/chat.php`: chat endpoints
- `src/ConcreteCmsAgent.php`: the agent (provider, instructions, tools, history)
- `src/Tools/*Toolkit.php`: native toolkits (extend `NeuronAI\Tools\Toolkits\AbstractToolkit`)
- `src/Tools/ExternalToolkitRegistry.php`: lets other packages register toolkits
- `src/Entity/`, `src/Chat/ChatMessageStore.php`: chat persistence (Neuron 4 message store, one row per message; P2.5)
- `js/chat-panel.js`, `css/chat-panel.css`, `elements/chat_panel.php`: UI

## Rules
1. **Never delete or rewrite a file or a large block of code to "rebuild" it.** Make small, incremental edits. If a rewrite seems necessary, stop and propose a plan first.
2. **Work on a branch** and keep each change to a single task. Never commit to `main` directly.
3. **Read before editing** anything in `src/ConcreteCmsAgent.php`, `controllers/chat.php` or any toolkit's write tools: know what the change touches. A written plan is needed only when the Working agreement's plan-stop list applies (WP-4).
4. **Every tool:**
   - has a clear name, description and typed properties
   - returns the `{ok: true, data}` / `{ok: false, error}` envelope through `guarded()`
   - checks Concrete permissions for the active user
   - has a PHPUnit test

   How rule 4 applies by situation:
   - **New tools, or tools whose behaviour changes:** need their own PHPUnit test in the same PR.
   - **Mechanical conversions** (e.g. P2.3, converting the 87 core tools to `CallableTool`): covered by one registry test that checks every tool registers with a valid name, description and schema, plus the scenarios. Individual tests aren't needed.
   - **Permission-denied tests for existing tools:** come in P3.5.
5. **Put behavioural rules in code, not in `instructions()`.** If the model keeps getting something wrong, fix the tool or add a check and a scenario. Don't add another prompt rule. Exception: the trust-boundary rule in `instructions()`, see `plans/P1.11.md`.
6. **No client-specific code in this package** (e.g. EPRA block handles). It belongs in a separate package that registers through `ExternalToolkitRegistry`.
7. **Writes must be undoable:** edit a new page version and never publish silently. Keep the text-integrity checks (checksums, `formatting_only` / `scoped_text`) when editing content tools.
8. **Don't edit `vendor/`.** Dependencies are bundled. Changes to them go through composer and a deliberate dependency update.
   - A dependency upgrade gets its own PR.
   - It must not be combined with refactoring.
   - It must keep scenario results the same.
   - On an integration branch, the dependency change is its own commit, and the integration merge (as P2.9 did) carries the "scenario results unchanged" check.
9. **Don't touch secrets:** API keys live in Concrete config (`katalysis.ai.*`) and must never be logged or returned to the frontend.

## Testing
- PHPUnit tests: `php tools/phpunit/vendor/bin/phpunit`, run from the package folder with PHP 8.2+ on `PATH` (install and options in `documentation/SETUP.md`). Suites: `unit` (no Concrete, no dev site) and `integration` (boots Concrete against the dev site database; the dev-host guard is inside the boot function, so it can't be skipped).
- JS safety tests (BF-11): `node --test tests/js/*.test.mjs`, with Node 20+ on `PATH` (no npm packages). Both developers need Node: `/finish-task` stops without it. Manual check: `tests/js/manual/reply-rendering.html` (SETUP).
- **Test tiers:**
  - **Fast**, after every change: `php tools/phpunit/vendor/bin/phpunit --exclude-group slow` (about 18 s) and `node --test tests/js/*.test.mjs` (under a second). The `slow` group is `SeedResetTest`. A new test class that takes more than about 2 s goes in it too (`#[Group('slow')]`), with a note in the task's progress entry.
  - **Full**, before every PR (in `/finish-task`): `php tools/phpunit/vendor/bin/phpunit` (about 43 s) and the JS safety tests.
  - **Scenarios** cost money and call the AI. Run them only when a task touches prompts, tools, the safety layers, the model or provider, or the Neuron version, and at every phase exit. They don't run on every merge. **In Phase 3 (WP-4):** a task runs a free `--fake` run of the scenarios it touches, and only its own new or changed scenarios ×1. The ×3 comparison of the stage's named scenarios runs once at the end of each stage, in its own task (P3.S1–P3.S4), and the merge rule is applied there. P3.5-1, P3.2-1, P3.6-1, P3.6-2 and P3.6a keep their own ×3 runs before merge. Each task's `DELIVERY_PLAN.md` entry names its scenarios and cap.
- **Red first** stays for safety work and bug fixes. For a change to what a read tool returns, one integration test per tool is enough (WP-4).
- **No test may call the AI provider or use an API key.** Only the scenario runner does that.
- Tests that change shared fixtures restore the state they found. They never assume or force a fixed starting state.
- Tests that run the seed's reset change fixture IDs and the Config record; compare by structure and row counts, as SeedResetTest does.
- **Fixture lock and drift (WP-2):** the seed, the integration tests and the scenario runner share one lock (`tests/Support/FixtureLock.php`); whichever starts second refuses. Don't work around a refusal. "fixture drift: run the seed reset" means a `/neuron-test` page changed outside the run: ask before resetting if the change might matter (SETUP.md, "One at a time").
- **Leak check (P1.12):** every integration test class that extends `ConcreteTestCase` is checked after it runs. `DatabaseSnapshot` counts every table (and checksums `Config` and the permission tables), and the class fails if any table changed, naming the tables. Only `Logs` is allowed (reason in the constant). `Page::delete()` and `UserInfo::delete()` leave permission rows behind, so use `AreaGrantFixture` and `TaskKeyFixture` for those grants and users, and call `parent::tearDownAfterClass()` if you override it. To clean up rows from older runs, `documentation/fixtures/cleanup_test_leftovers.php` (dry run by default).
- Behaviour scenarios: `php ../../concrete/bin/concrete c5:exec tests/Eval/run_scenarios.php -- [--scenario=] [--repeat=N]`, run from the package folder against the dev site. It costs money; see `documentation/SETUP.md`.
- Before a task that writes to the dev site, back up the database: `documentation/fixtures/backup_dev_db.sh <task-id>` (needs `NEURON_BACKUP_DIR`; see SETUP.md).
- **The merge rule tightens as the project goes:**
  - **Before the P1.5 baseline exists:** no regressions in the manual checks listed in the task plan.
  - **After P1.5:** no regressions against the recorded baseline. A scenario that passed must still pass, and scenarios that are known to fail may keep failing.
    - **pass → partial** is not a regression for merge purposes, but it must be listed in the PR. (`partial` means only a secondary reply check failed; see `tests/Scenarios/README.md`.)
    - **pass → fail** and **partial → fail** are regressions and block the merge.
    - **Don't loosen the baseline quietly.** Any change to a scenario's expected values or regexes needs its own commit, with the reason.
  - **From the end of Phase 3:** all scenarios must pass.
- After changing an entity: `concrete/bin/concrete c5:package:update katalysis_neuron_ai` and clear the cache.

## Concrete conventions
- Use core services and commands (e.g. the page/block command bus, `Page::getByID`, the permission `Checker`) and follow the same paths as the `/ccm/api/v1` endpoints, per the tool policy.
- Namespace: `Katalysis\NeuronAi\...` (autoloaded from `src/`). The package controller is in `Concrete\Package\KatalysisNeuronAi`.
