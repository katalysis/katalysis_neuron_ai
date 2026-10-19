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

- Pages under `/neuron-test`: `headings` (Content blocks with h2/h3 headings and bold-paragraph headings), `columns` (a two-column container with nested Content blocks) and `people` (a People block).
- Three fake people, "Test Person Alpha", "Test Person Beta" and "Test Person Gamma" (`@neuron-test.invalid` emails).
- An Express object, "Test Room" (`neuron_test_room`): name, capacity, room type (meeting / training / hall) and description, with four entries.
- A non-admin user, `neuron_editor`, who can view, edit, add subpages under and approve versions of `/neuron-test` and its children. The user cannot delete, move or change permissions on those pages, cannot edit any other page and cannot open the Dashboard.

Run from `httpdocs/`. Options for the script go after `--`:

```bash
# create whatever is missing; leaves anything that exists alone
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php

# delete everything the script created, then create it again
php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php -- --reset
```

`--reset` removes only what the script created: the `/neuron-test` tree, the three test people, the Test Room object, the `neuron_editor` user and the permission rows that user's grant created. It checks a marker on each first. If something with the same path, handle or name exists without the marker, it stops and deletes nothing. It never reads or changes the other pages, people, users or Express objects.

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

## Troubleshooting

If changes are not visible:

```bash
cd httpdocs
php concrete/bin/concrete5 orm:clear-cache:metadata
php concrete/bin/concrete5 orm:clear-cache:query
php concrete/bin/concrete5 orm:clear-cache:result
```

Then clear application cache in dashboard and reload.
