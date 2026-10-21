# Katalysis Neuron AI Setup Guide

**Note:** This package bundles all dependencies in its `vendor/` directory. You do NOT need to run `composer install` for normal installation.

**Requirements:** PHP 8.1 or later with the `curl` extension (`ext-curl`). Neuron AI 4 sends every request to the AI provider through curl; there is no other HTTP client bundled. Check it on every server the package runs on (`php -m | grep curl`, for the PHP version the site uses), including production before the Neuron 4 upgrade reaches it.

### Updating the bundled dependencies (developers only)
The versions are pinned in `composer.json` (`neuron-core/neuron-ai` exactly) and locked in `composer.lock`; `config.platform.php` is 8.1.0, so composer picks versions that work on the lowest PHP the package supports. To change a version (its own commit, CLAUDE.md rule 8):

1. Edit the pin in `composer.json`.
2. With Herd's composer, from the package folder: `composer update`, then `composer validate` and `composer audit`.
3. Commit `composer.json`, `composer.lock` and `vendor/` together and with nothing else: `ALLOW_VENDOR=1 git commit ...` (the pre-commit hook refuses `vendor/` otherwise).
4. Clear the Concrete cache, then run the PHPUnit suite.

## New developer setup (Claude Code)

For working on this package (Glyn and Alex: macOS, Herd, the Claude Code VS Code extension). Once per clone:

1. Open `httpdocs/packages/katalysis_neuron_ai` in VS Code as the workspace root, not `katalysis_pro_theme`. Then `CLAUDE.md`, `.claude/settings.json` and `.claude/commands/` apply.
2. In Claude Code, accept the workspace trust dialog once. Until you do, the `allow` rules in `.claude/settings.json` are ignored (Claude Code says so), so routine commands keep prompting. The `deny` and `ask` rules always apply.
   - **Edit mode:** `.claude/settings.json` sets `defaultMode: acceptEdits`, so edits inside the package don't prompt (`vendor/` stays denied; `composer.json` and `controller.php` still ask).
   - **VS Code:** the Claude Code docs say VS Code sessions don't read the starting mode from project settings. If edits still prompt in the extension, switch to the accept-edits mode in the extension's mode selector at the start of a session. Not yet checked in the extension.
   - **Source:** https://code.claude.com/docs/en/permission-modes
3. Enable the git hooks: `git config core.hooksPath .githooks`
4. Create `.claude/settings.local.json` (gitignored) with your own settings:
   ```json
   { "env": { "NEURON_BACKUP_DIR": "~/Herd/backups" } }
   ```
   `NEURON_BACKUP_DIR` must be an existing directory outside the repo and the web root. Add `"NEURON_NOTIFY": "off"` to turn off the desktop notifications.
5. Make sure `php` (8.2+), `git`, and for backups `mysqldump`, are on `PATH`. The hooks need only `php` and `git`.

What you get: a guard on risky Bash commands (`.claude/hooks/guard-bash.php`), a notification when Claude is waiting for you or has finished (`.claude/hooks/notify.php`), and git hooks that refuse `vendor/` commits and pushes to `main` or `development`. `CLAUDE.md`, "Working agreement", describes what each one does and doesn't cover.

## Install or upgrade package

1. Install in Concrete CMS

```bash
cd httpdocs
php concrete/bin/concrete5 c5:package:install katalysis_neuron_ai
```

If already installed:

```bash
cd httpdocs
php concrete/bin/concrete5 c5:package:update katalysis_neuron_ai
```

## Configure AI provider

Set at least the OpenAI key in package settings:

- Dashboard -> System -> AI -> Neuron AI Settings

Access is controlled by two Concrete task permissions, set at Dashboard -> System & Settings -> Permissions & Access -> Tasks:

- **Use Neuron AI assistant** (`use_neuron_ai`): who gets the chat panel. Administrators have it by default. Registered users only: guests never get the assistant, even if the key is granted to the Guest group.
- **Configure Neuron AI** (`configure_neuron_ai`): who can see and save the settings. Nobody but the super user has it by default. Users without it see only "You don't have permission to change Neuron AI settings." on the settings page, with no form.

The two are independent: holding one does not give the other. The super user always has both. Both keys are added on install and on upgrade to 3.1.0; an upgrade never changes a choice an admin has already made. Sites that had given other groups view on the settings page to let them chat must grant **Use Neuron AI assistant** to those groups instead.

## Verify routes

The package should register:

- `POST /ccm/system/katalysis_neuron_ai/chat/send_message`
- `GET /ccm/system/katalysis_neuron_ai/chat/list`
- `GET /ccm/system/katalysis_neuron_ai/chat/load?id={chatId}`
- `POST /ccm/system/katalysis_neuron_ai/chat/new`

## Quick validation checklist

- Open any dashboard page as an administrator and confirm the chat panel can be opened from the toolbar button. The panel is sent only on pages where Concrete shows the user the toolbar, so a user with the use key who has no toolbar on a page (for example the home page, for an editor who cannot edit it) has no panel there.
- Send a message and confirm response is returned
- Click Load and confirm previous chats are listed
- Open a previous chat and confirm history renders correctly
- Click New and confirm a fresh session starts

## Dev site test content (dev site only)

`documentation/fixtures/seed_test_content.php` gives the dev site (`katalysis_pro_theme`, `http://katalysis-pro.test/`) a known set of test content for the behaviour scenarios. It creates:

- Pages under `/neuron-test`: `headings` (Content blocks with h2/h3 headings and bold-paragraph headings), `columns` (a two-column container with nested Content blocks) and `people` (a People block). Added for the P1.2 scenarios: `injection` (text aimed at the assistant, with `.invalid` addresses only), `move-me` (with a child page, `move-me-child`) and `move-target`.
- Three fake people, "Test Person Alpha", "Test Person Beta" and "Test Person Gamma" (`@neuron-test.invalid` emails). Reset also removes any person whose name starts with "Test Person " and whose email ends in `@neuron-test.invalid`, so people that scenarios create must follow that pattern.
- A small text file in the file manager, "Neuron Test File", imported (copied) from `documentation/fixtures/neuron-test-file.txt`. Its description holds the fixture marker.
- An Express object, "Test Room" (`neuron_test_room`): name, capacity, room type (meeting / training / hall) and description, with four entries.
- A non-admin user, `neuron_editor`, who can view, edit, add subpages under and approve versions of `/neuron-test` and its children. The user cannot delete, move or change permissions on those pages, cannot edit any other page and cannot open the Dashboard. It cannot use the assistant (it is in no group that holds the use key), so it is the "logged-in user without access" fixture.
- A second non-admin user, `neuron_chat_editor` (P1.10), for browser checks. It has the same `/neuron-test` rights as `neuron_editor` (including the add-block grant on the Main area of `headings`), plus membership of the group **Neuron Test Editors**, which the script adds to the access list of the **Use Neuron AI assistant** key. It does not hold the configure key, so it cannot save the settings. The script no longer touches the settings page's permissions. A dev site that an earlier version of the script seeded (a view grant on the settings page, which made the page override the `/dashboard` permissions) is cleaned up on the next run: the page goes back to inheriting, and only rows the script created are removed. This clean-up can be dropped in a later release. If the settings page overrides its permissions in any other way, the script leaves it alone and prints a warning.

Run from `httpdocs/`. Options for the script go after `--`:

```bash
# create whatever is missing; leaves anything that exists alone
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php

# delete everything the script created, then create it again
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php -- --reset

# delete everything the script created and stop (nothing is created again)
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php -- --reset-only
```

`--reset-only` is for checking that a reset leaves nothing behind (take a snapshot of the permission tables before the seed and after `--reset-only`; they should match) and for testing code against a site without the fixtures. The site is then without `/neuron-test` and both users until the next seed.

The seed clears Concrete's cache at the end of every successful run (also `--reset` and `--reset-only`), the same as Dashboard > System & Settings > Optimization > Clear Cache. If permissions ever look wrong in the browser, clear the cache first.

`--reset` removes only what the script created: the `/neuron-test` tree, the three test people, the test file, the Test Room object, the `neuron_editor` and `neuron_chat_editor` users, the group Neuron Test Editors (it is taken out of the use key's access list first) and the permission rows their grants created. It knows what it owns by the IDs it stored: one row in the `Config` table (namespace `katalysis_neuron_ai`, group `fixture`, item `ids`, a JSON value) holds the page, user, group, Express object, file and people IDs it created. An item with a stored ID is the script's whatever its fields now say, so an edit by the assistant or a person cannot stop a reset. `--reset` first discards the draft versions under `/neuron-test` (the assistant only writes drafts), and when it deletes an item whose fields had been changed (for example the approved description of `/neuron-test`, a user's email, the Express name) it prints what it discards, such as `/neuron-test: approved description differs from the seed text; discarded`. A site seeded before the IDs were stored is adopted once: the script finds its content by the old marker (for the page, the description of the approved version, never a draft) and records the IDs; a plain run does this too. If something with the same path, handle, name or file title has neither a stored ID nor the marker, the script stops and deletes nothing. If the stored `/neuron-test` page has been moved or is in the trash, `--reset` and a plain run stop and say where it is, and delete or create nothing: move it back (or delete it yourself) and run again. `--reset-only` removes the stored row with the content. It never reads or changes the other pages, people, files, users or Express objects. It also removes the review folder "Assistant – to delete" (or "Assistant - to delete") that a scenario may have created, but only once nothing else is in it: it deletes the fixture file first, and if the folder then holds anything else it leaves the folder and prints a warning. The script itself never creates the folder.

`neuron_editor` has the dev-only password `NeuronEditorDev#1`, and `neuron_chat_editor` has the dev-only password `NeuronChatEditorDev#1`. Log in by username (this site has `email_registration` off). Never use these passwords, or this script, on a server. The script refuses to run unless the site's canonical URL is a `.test`, `.localhost` or `localhost` host.

The editor's rights are set per page through Concrete's API, and the site's simple permission model is enough: advanced permissions don't need to be switched on.

### What the model receives (`show_prompt.php`)

`tests/Support/show_prompt.php` (P2.4) prints what the model would be given for one user message: the system prompt, the user message with its page tag, and the tools offered, external toolkits included. It runs one real agent turn with a fake model, so there is no AI provider call, no API key is read and nothing is written to the database. It refuses to run unless the site's canonical URL is a `.test`, `.localhost` or `localhost` host, and everything it prints goes through `SecretRedactor`. Run it from the package folder; options go after `--`:

```bash
# /neuron-test/headings as neuron_chat_editor, message "Hello"
php ../../concrete/bin/concrete c5:exec tests/Support/show_prompt.php

# another page, user or message
php ../../concrete/bin/concrete c5:exec tests/Support/show_prompt.php -- --path=/neuron-test/columns --user=neuron_chat_editor --message="Summarise this page"
```

### Full database snapshot (local only, never in git)

A script reset is enough in most cases. To take a snapshot before a task, run `documentation/fixtures/backup_dev_db.sh <task-id>` from the package folder. It writes `$NEURON_BACKUP_DIR/<database>_before_<task-id>_<timestamp>.sql` (mode 600), reads the connection from `database.php` and accepts only a local host. The manual commands below do the same. For a full restore, keep snapshots outside the repo (Glyn uses `~/Herd/backups/`). The dev database is `prodb` on `127.0.0.1:3307`; the password is in `httpdocs/application/config/database.php`. A snapshot contains user password hashes and chat history, so don't copy it anywhere shared.

```bash
mkdir -p ~/Herd/backups
export MYSQL_PWD='<password from database.php>'

# take a snapshot
mysqldump -h127.0.0.1 -P3307 -uroot --single-transaction --routines --default-character-set=utf8mb4 prodb > ~/Herd/backups/prodb_$(date +%Y%m%d_%H%M%S).sql

# restore one (this replaces the whole database)
mysql -h127.0.0.1 -P3307 -uroot prodb < ~/Herd/backups/<snapshot>.sql
```

After a restore, clear the Concrete cache.

## Running the PHPUnit tests (dev site only)

PHPUnit is dev tooling. It is installed into `tools/phpunit/` (gitignored) from `composer.dev.json`, so it is never part of the package's bundled `vendor/`. It needs PHP 8.2 or later; use Herd's PHP and Composer.

Run from the package folder (`httpdocs/packages/katalysis_neuron_ai`):

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"

# one-off install
COMPOSER=composer.dev.json composer install

# run the tests
php tools/phpunit/vendor/bin/phpunit --exclude-group slow   # fast tier, after every change (about 9 s)
php tools/phpunit/vendor/bin/phpunit                        # everything, before every PR (about 35 s)
php tools/phpunit/vendor/bin/phpunit --testsuite unit
php tools/phpunit/vendor/bin/phpunit --testsuite integration
```

- The `unit` suite does not boot Concrete and doesn't need the dev site. The `integration` suite boots Concrete against the dev site database. Integration tests extend `tests/Support/ConcreteTestCase`, which boots through `tests/Support/ConcreteBoot.php`, the only place that boots Concrete.
- The dev-host guard is inside that boot function. It ends the run unless the canonical URL is a `.test`, `.localhost` or `localhost` host, so no test can use Concrete without passing it. Booting itself reads the dev database before the guard can see the site's URL, so don't point the package at a non-dev site.
- Tests must not call the AI provider or use an API key. Only the scenario runner does that.
- Tests are read-only unless they say otherwise. A test that writes must clean up after itself.
- Test content comes from the seed script above (`neuron_editor`, `/neuron-test`).
- Before Concrete boots, `ConcreteBoot` also refuses to run if the site's `database.php` points at a host that is not local (127.0.0.1, localhost, ::1 or a unix socket).

## Running the scenario runner (dev site only, costs money)

The runner plays the scenarios in `tests/Scenarios/` through the real agent and checks the end state. It is dev tooling under `tests/Eval/`, not part of the shipped package. **It calls the AI provider with the dev site's API key, so every run costs money, and it resets the `/neuron-test` content for each run.**

Run from `httpdocs/` as below, or from the package folder as `php ../../concrete/bin/concrete c5:exec tests/Eval/run_scenarios.php -- ...` (options for the script go after `--`):

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
cd httpdocs

php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --scenario=05-h3-to-h2
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --scenario=05,06 --repeat=3
```

- `--scenario=ID[,ID...]` runs only those scenarios (an id, or its number: `05`). `--repeat=N` (1 to 20, default 1) runs each one N times, each from a fresh fixture reset, and the table shows a pass rate such as "2/3 pass, 1/3 partial".
- It needs the seed script's content and the `neuron_editor` user (see above), and an OpenAI key in `katalysis.ai.open_ai_key` (Dashboard > System > AI > Neuron AI Settings). Without a key it stops before calling anything.
- Each run resets the fixture, acts as the scenario's user (no browser or login session), passes the page context the way the chat endpoint does (a native PHP session holding `katalysis_neuron_ai_page_context`), sends the turns, and takes a before/after snapshot of pages, blocks, files and people to check the end state. It never touches the shipped code path over HTTP.
- Outcomes: `pass`, `partial` (only a secondary reply check failed), `fail`, and `error` (the agent returned its generic error reply, or the runner failed). An `error` run proves nothing about behaviour; do not record it as a baseline. The real cause is read from the Concrete log and shown in the details.
- Tokens are shown per scenario and in total. If the provider reports none, the table says "not reported".
- Results go to `documentation/eval-results/<YYYYMMDD-HHMMSS>.md` and `.json` (same timestamp), and the markdown is printed. These are gitignored. A file named `baseline-*` in that folder is not ignored, so a recorded baseline can be committed.
- The runner's chat rows use the session id prefix `neuron-eval-`. They are deleted at the end of the run, and any left by an interrupted run are deleted at the start of the next.
- Guards: the script checks the database host and the dev-host URL first and refuses to run otherwise. Because `c5:exec` has already booted Concrete by then, these are the first thing the script does, not a check before Concrete reads the database.

## Troubleshooting

If changes are not visible:

```bash
cd httpdocs
php concrete/bin/concrete5 orm:clear-cache:metadata
php concrete/bin/concrete5 orm:clear-cache:query
php concrete/bin/concrete5 orm:clear-cache:result
```

Then clear application cache in dashboard and reload.
