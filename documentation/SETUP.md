# Katalysis Neuron AI Setup Guide

**Note:** This package bundles all dependencies in its `vendor/` directory. You do NOT need to run `composer install` for normal installation.

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

## Verify routes

The package should register:

- `POST /ccm/system/katalysis_neuron_ai/chat/send_message`
- `GET /ccm/system/katalysis_neuron_ai/chat/list`
- `GET /ccm/system/katalysis_neuron_ai/chat/load?id={chatId}`
- `POST /ccm/system/katalysis_neuron_ai/chat/new`

## Quick validation checklist

- Open any dashboard page and confirm chat panel is visible
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
- A non-admin user, `neuron_editor`, who can view, edit, add subpages under and approve versions of `/neuron-test` and its children. The user cannot delete, move or change permissions on those pages, cannot edit any other page and cannot open the Dashboard.

Run from `httpdocs/`. Options for the script go after `--`:

```bash
# create whatever is missing; leaves anything that exists alone
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php

# delete everything the script created, then create it again
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php -- --reset
```

`--reset` removes only what the script created: the `/neuron-test` tree, the three test people, the test file, the Test Room object, the `neuron_editor` user and the permission rows that user's grant created. It checks a marker on each first. If something with the same path, handle, name or file title exists without the marker, it stops and deletes nothing. It never reads or changes the other pages, people, files, users or Express objects. It also removes the review folder "Assistant – to delete" (or "Assistant - to delete") that a scenario may have created, but only once nothing else is in it: it deletes the fixture file first, and if the folder then holds anything else it leaves the folder and prints a warning. The script itself never creates the folder.

`neuron_editor` has the dev-only password `NeuronEditorDev#1`. Never use this password, or this script, on a server. The script refuses to run unless the site's canonical URL is a `.test`, `.localhost` or `localhost` host.

The editor's rights are set per page through Concrete's API, and the site's simple permission model is enough: advanced permissions don't need to be switched on.

### Full database snapshot (local only, never in git)

A script reset is enough in most cases. For a full restore, keep snapshots outside the repo in `~/Herd/backups/`. The dev database is `prodb` on `127.0.0.1:3307`; the password is in `httpdocs/application/config/database.php`. A snapshot contains user password hashes and chat history, so don't copy it anywhere shared.

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
php tools/phpunit/vendor/bin/phpunit                        # everything
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

Run from `httpdocs/` (options for the script go after `--`):

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
cd httpdocs

php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --scenario=05-h3-to-h2
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --scenario=05,06 --repeat=3
```

- `--scenario=ID[,ID...]` runs only those scenarios (an id, or its number: `05`). `--repeat=N` (1 to 20, default 1) runs each one N times, each from a fresh fixture reset, and the table shows a pass rate such as "2/3 pass, 1/3 partial".
- It needs the seed script's content and the `neuron_editor` user (see above), and an OpenAI key in `katalysis.ai.open_ai_key` (Dashboard > System > AI > Neuron AI Settings). Without a key it stops before calling anything.
- Each run resets the fixture, acts as the scenario's user (no browser, no session), sends the turns, and takes a before/after snapshot of pages, blocks, files and people to check the end state. It never touches the shipped code path over HTTP.
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
