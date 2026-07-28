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

## Troubleshooting

If changes are not visible:

```bash
cd httpdocs
php concrete/bin/concrete5 orm:clear-cache:metadata
php concrete/bin/concrete5 orm:clear-cache:query
php concrete/bin/concrete5 orm:clear-cache:result
```

Then clear application cache in dashboard and reload.
