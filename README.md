# Katalysis Neuron AI Package

Concrete CMS dashboard assistant powered by Neuron AI.

## Current capabilities

- Chat panel injected on dashboard pages
- Persistent chat history saved in database table `KatalysisNeuronAiChats`
- List and load previous chats for the current user
- Start a new chat session from the panel header
- Toolkits for pages, files, and users
- Concrete CMS page-type aware responses via dedicated tooling

## Installation

See [SETUP.md](SETUP.md) for installation instructions.

**Note:** This package bundles all its dependencies. No `composer install` needed for normal use.

## Development

If you need to update bundled dependencies, see [BUILD.md](BUILD.md).

## Routes

- `POST /ccm/system/katalysis_neuron_ai/chat/send_message`
- `GET /ccm/system/katalysis_neuron_ai/chat/list`
- `GET /ccm/system/katalysis_neuron_ai/chat/load?id={chatId}`
- `POST /ccm/system/katalysis_neuron_ai/chat/new`

## Technical Details

- Dependencies are **bundled** in the `vendor/` directory (committed to git)
- The package uses its own autoloader via `setupAutoloader()` in controller.php
- Parent projects won't install duplicate dependencies
- See [BUILD.md](BUILD.md) for the build architecture

## Notes

- Chat history persistence is handled by `DatabaseChatHistory` and is automatic on each message.
- Frontend message rendering handles both string and structured content payloads.

## Requirements

- Concrete CMS 9.3+
- PHP 8.1+

