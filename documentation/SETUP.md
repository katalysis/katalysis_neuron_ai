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
5. Make sure `php` (8.2+), `git`, **`node` (20+)**, and for backups `mysqldump`, are on `PATH`. The hooks need only `php` and `git`. Both developers need Node: the JS safety tests run in the fast tier and `/finish-task` stops without them (BF-11; "Running the JS tests" below).

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

- Pages under `/neuron-test`: `headings` (Content blocks with h2/h3 headings and bold-paragraph headings), `columns` (a two-column container with nested Content blocks) and `people` (a People block). Added for the P1.2 scenarios: `injection` (text aimed at the assistant, with `.invalid` addresses only), `move-me` (with a child page, `move-me-child`) and `move-target`. Added for BF-10: `edit-mode` and `edit-mode-draft`, one Content block each holding `documentation/fixtures/edit-mode-content.html`, the bytes a browser save in edit mode stored (CR LF between elements, `&nbsp;`), saved by the content block's own `save()` as one published version. Scenario 24's unpublished hand edit on `edit-mode-draft` is its `hand_edit` setup step, not part of the seed. Added for P3.3-6: `block-types`, one block each of Content, Image (the seed photo, with alt text), Page List, Feature, Accordion and Express form (the "Room Enquiry" form of the Test Room object), with an add-block grant on its Main area for the fixture users; scenarios 27 to 32 use it.
- Three fake people, "Test Person Alpha", "Test Person Beta" and "Test Person Gamma" (`@neuron-test.invalid` emails). Reset also removes any person whose name starts with "Test Person " and whose email ends in `@neuron-test.invalid`, so people that scenarios create must follow that pattern.
- A small text file in the file manager, "Neuron Test File", imported (copied) from `documentation/fixtures/neuron-test-file.txt`. Its description holds the fixture marker.
- A photo in the file manager, "Neuron Test Person Photo" (Test Person Alpha's image and the Image block on `block-types`), imported from `documentation/fixtures/neuron-test-person.png`: a 200×200 picture labelled "Neuron test photo" (BF-13; before, a single pixel that showed nothing). **File links in old chats stop working after any seed reset or scenario run** (the runner resets after every run): the photo and the test file are imported again each time, with a new path.
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

`--reset` removes only what the script created: the `/neuron-test` tree, the three test people, the test file and the test photo (BF-12), the Test Room object, the `neuron_editor` and `neuron_chat_editor` users, the group Neuron Test Editors (it is taken out of the use key's access list first) and the permission rows their grants created. It knows what it owns by the IDs it stored: one row in the `Config` table (namespace `katalysis_neuron_ai`, group `fixture`, item `ids`, a JSON value) holds the page, user, group, Express object, file and people IDs it created. An item with a stored ID is the script's whatever its fields now say, so an edit by the assistant or a person cannot stop a reset. `--reset` first discards the draft versions under `/neuron-test` (the assistant only writes drafts), and when it deletes an item whose fields had been changed (for example the approved description of `/neuron-test`, a user's email, the Express name) it prints what it discards, such as `/neuron-test: approved description differs from the seed text; discarded`. A site seeded before the IDs were stored is adopted once: the script finds its content by the old marker (for the page, the description of the approved version, never a draft) and records the IDs; a plain run does this too. If something with the same path, handle, name or file title has neither a stored ID nor the marker, the script stops and deletes nothing. If the stored `/neuron-test` page has been moved or is in the trash, `--reset` and a plain run stop and say where it is, and delete or create nothing: move it back (or delete it yourself) and run again. `--reset-only` removes the stored row with the content. It never reads or changes the other pages, people, files, users or Express objects. It also removes the review folder "Assistant – to delete" (or "Assistant - to delete") that a scenario may have created, but only once nothing else is in it: it deletes the fixture file first, and if the folder then holds anything else it leaves the folder and prints a warning. The script itself never creates the folder.

`neuron_editor` has the dev-only password `NeuronEditorDev#1`, and `neuron_chat_editor` has the dev-only password `NeuronChatEditorDev#1`. Log in by username (this site has `email_registration` off). Never use these passwords, or this script, on a server. The script refuses to run unless the site's canonical URL is a `.test`, `.localhost` or `localhost` host.

The editor's rights are set per page through Concrete's API, and the site's simple permission model is enough: advanced permissions don't need to be switched on.

### One at a time: the fixture lock and the drift check (WP-2)

The seed, the PHPUnit integration tests and the scenario runner share one lock on the fixture (`tests/Support/FixtureLock.php`), so one of them can't reset `/neuron-test` while another is using it. Whichever starts second refuses and changes nothing, with a message such as:

```
Refusing to start the seed (seed_test_content.php --reset): the dev site's test fixture is in use by the scenario runner (run_scenarios.php --scenario=05 --repeat=3) (process 41234, since 2026-10-23 16:40:12 UTC). ...
```

Wait for it to finish and start again. The integration tests take the lock when the first test class boots Concrete and keep it until PHPUnit ends; a unit-only run doesn't take it. A seed that the tests or the runner start themselves shares their lock (it finds their token in `NEURON_FIXTURE_LOCK`).

**Stale locks:** the lock is an `flock()` on `.fixture-lock` in the package folder (gitignored). The operating system releases it when the holding process ends, however it ends (a crash, a fatal error, Ctrl-C, `kill -9`), so a crashed run never blocks the next one; the file's leftover details are ignored and overwritten. Only a run that is still alive keeps the lock. If it is stuck, stop the process the message names (`kill <pid>`). Don't delete the file to get past a refusal: it doesn't release a lock that is held.

**Drift:** after every successful run the seed records a fingerprint of each page at `/neuron-test` and below (every row of its versions, their blocks, content-block text and attribute values) in one `Config` row (`katalysis_neuron_ai` / `fixture` / `pages`; `--reset-only` removes it). Before every integration test class, `ConcreteTestCase` compares, and if a page changed (a browser check, a page-properties save, a test that didn't clean up) the class fails with:

```
fixture drift: run the seed reset. /neuron-test is not as the seed left it:
  /neuron-test/headings (page ID 31541) has 2 version(s), newest 2 (not approved); the seed left 1, newest 1
```

Run the seed with `--reset` (back up first if the change matters) and run the tests again.

**Once per dev site, after WP-2 is merged:** run the seed with `--reset` (after a backup) to record the first fingerprint. A site seeded before WP-2 has none, and until it does, every integration test class fails (the unit suite still passes) with:

```
fixture drift: run the seed reset. /neuron-test is not as the seed left it:
  the seed has recorded no fingerprint of the fixture pages (it records one since WP-2)
```

That is expected and not a broken test: one `--reset` records the fingerprint, and from then on every seed run keeps it up to date.

**Once per dev site, after BF-12 is merged:** run the seed with `--reset` (after a backup). BF-12 gives Test Person Alpha a photo (a small image, "Neuron Test Person Photo", from `fixtures/neuron-test-person.png`), and a plain run leaves existing test people alone, so only a reset adds it. Until then scenario 25 has no image to link, and its secondary file-name check fails.

### Clearing the dev chats (`clear_dev_chats.php`)

Dev chats are disposable (P2.5): chat history has no compatibility across the Neuron 3 → 4 upgrade or its rollback. `documentation/fixtures/clear_dev_chats.php` deletes every stored chat; the messages in `KatalysisNeuronAiChatMessages` go with them (the foreign key cascades). It refuses to run unless the site's canonical URL is a `.test`, `.localhost` or `localhost` host. Run it from the package folder:

```bash
# dry run (the default): how many chats and message rows there are
php ../../concrete/bin/concrete c5:exec documentation/fixtures/clear_dev_chats.php

# delete them, after a backup (documentation/fixtures/backup_dev_db.sh <task-id>)
php ../../concrete/bin/concrete c5:exec documentation/fixtures/clear_dev_chats.php -- --apply
```

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
php tools/phpunit/vendor/bin/phpunit --exclude-group slow   # fast tier, after every change (about 18 s, 1,210 tests)
php tools/phpunit/vendor/bin/phpunit                        # everything, before every PR (about 43 s, 1,223 tests)
php tools/phpunit/vendor/bin/phpunit --testsuite unit
php tools/phpunit/vendor/bin/phpunit --testsuite integration
```

- The `unit` suite does not boot Concrete and doesn't need the dev site. The `integration` suite boots Concrete against the dev site database. Integration tests extend `tests/Support/ConcreteTestCase`, which boots through `tests/Support/ConcreteBoot.php`, the only place that boots Concrete.
- The dev-host guard is inside that boot function. It ends the run unless the canonical URL is a `.test`, `.localhost` or `localhost` host, so no test can use Concrete without passing it. Booting itself reads the dev database before the guard can see the site's URL, so don't point the package at a non-dev site.
- Tests must not call the AI provider or use an API key. Only the scenario runner does that.
- Tests are read-only unless they say otherwise. A test that writes must clean up after itself.
- Test content comes from the seed script above (`neuron_editor`, `/neuron-test`). An integration run holds the fixture lock and fails a class on fixture drift ("One at a time: the fixture lock and the drift check" above).
- Before Concrete boots, `ConcreteBoot` also refuses to run if the site's `database.php` points at a host that is not local (127.0.0.1, localhost, ::1 or a unix socket).
- **`katalysis_pro` must match this package** (P2.8). `katalysis_pro` is a separate git clone in `httpdocs/packages/katalysis_pro`. On Neuron 4 (`development` since P2.9, PR #47), it needs its `ToolDefinition` version, which is on `themeintegrationtemplates` since katalysis/katalysis_pro#42. On Neuron 3 (`main` of this package, until a release takes `development` there), it needs `katalysis_pro`'s older toolkit, from before #42. A mismatched pair fails safe on the site: there's no error page, the `katalysis_pro` tools just aren't offered, and on Neuron 4 the settings page shows the skip. In the tests, it fails: `KatalysisProToolsTest`, `PromptBaselineTest` and the `katalysis_pro` cases of `ToolRegistryTest` and `ToolResultScanningTest` name the reason.

## Running the JS tests

The chat panel's reply formatter (`js/chat-format.js`) has tests that keep model output from becoming live HTML in the panel (BF-11). They use Node's built-in test runner: Node 20 or later, no `npm install`, no `package.json`, and no dev site. Run them after every change, with the fast tier, and before every PR. `/finish-task` stops if Node is missing ("Node is required for the JS safety tests").

```bash
node -v                              # 20 or later
node --test tests/js/*.test.mjs      # under a second
```

**Manual check:** `tests/js/manual/reply-rendering.html` loads the real `chat-format.js` and `chat-panel.js` with a fake server and harmless payloads (an `<img onerror>`, `javascript:` and `data:` links, a quote break-out, a form, an iframe, a script tag, image markdown, a chat-list title and ID, a list error). Nothing talks to the site. Open it in a browser, or run it headless and read the report at the end, where every line should say `ok` and "ran: nothing":

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
  --user-data-dir="$(mktemp -d)" --virtual-time-budget=5000 \
  --dump-dom "file://$PWD/tests/js/manual/reply-rendering.html" 2>/dev/null | grep -A 20 'id="report"'
```

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
- `--fake` is a free dry run (P2.6): the model is Neuron's `FakeAIProvider` (`tests/Eval/DryRunAgent.php`), which asks for `get_current_page_context` once per turn and then replies "Dry run reply.", with made-up token counts. No provider is called and no key is needed. The checks mostly fail, because the replies are fake. A clean dry run has no `error` outcome, a recorded tool call with its result in every turn, and all its chat rows deleted. Run it before a paid run after a change to the agent, the runner or Neuron. Its results are not committed.
- It needs the seed script's content and the `neuron_editor` user (see above), and an OpenAI key in `katalysis.ai.open_ai_key` (Dashboard > System > AI > Neuron AI Settings). Without a key it stops before calling anything.
- Each run resets the fixture, acts as the scenario's user (no browser or login session), passes the page context the way the chat endpoint does (a native PHP session holding `katalysis_neuron_ai_page_context`), sends the turns, and takes a before/after snapshot of pages, blocks, files and people to check the end state. It never touches the shipped code path over HTTP.
- Outcomes: `pass`, `partial` (only a secondary reply check failed), `fail`, and `error` (the agent returned its generic error reply, or the runner failed). An `error` run proves nothing about behaviour; do not record it as a baseline. The real cause is read from the Concrete log and shown in the details.
- Tokens are shown per scenario and in total. If the provider reports none, the table says "not reported".
- Results go to `documentation/eval-results/<YYYYMMDD-HHMMSS>.md` and `.json` (same timestamp), and the markdown is printed. These are gitignored. A file named `baseline-*` in that folder is not ignored, so a recorded baseline can be committed.
- The runner's chat rows use the session id prefix `neuron-eval-`. They are deleted at the end of the run, and any left by an interrupted run are deleted at the start of the next.
- It holds the fixture lock for the whole run, so it refuses to start while the seed or the integration tests are running, and they refuse while it runs (WP-2, above).
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
