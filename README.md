# Katalysis Neuron AI Package

Concrete CMS dashboard assistant powered by Neuron AI.

## Current capabilities

- Chat panel, opened from the Concrete toolbar, for users with the "Use Neuron AI assistant" task permission
- Persistent chat history saved in the database: one row per chat in `KatalysisNeuronAiChats`, one row per message in `KatalysisNeuronAiChatMessages`
- List and load previous chats for the current user
- Start a new chat session from the panel header
- Toolkits for pages, files, and users
- Concrete CMS page-type aware responses via dedicated tooling

## Installation

See [documentation/SETUP.md](documentation/SETUP.md) for installation instructions.

**Note:** This package bundles all its dependencies. No `composer install` needed for normal use.

## Routes

- `POST /ccm/system/katalysis_neuron_ai/chat/send_message`
- `GET /ccm/system/katalysis_neuron_ai/chat/list`
- `GET /ccm/system/katalysis_neuron_ai/chat/load?id={chatId}`
- `GET /ccm/system/katalysis_neuron_ai/chat/current`
- `POST /ccm/system/katalysis_neuron_ai/chat/new`
- `POST /ccm/system/katalysis_neuron_ai/chat/delete`
- `POST /ccm/system/katalysis_neuron_ai/chat/delete_current`

## Technical Details

- Built on Neuron AI 4.1.12 (`neuron-core/neuron-ai`, pinned exactly in `composer.json`)
- Dependencies are **bundled** in the `vendor/` directory (committed to git): Neuron AI and `psr/event-dispatcher` only
- The package uses its own autoloader via `setupAutoloader()` in controller.php
- Parent projects won't install duplicate dependencies
- Other packages add tools through `ExternalToolkitRegistry` (see [documentation/EXTENSIONS.md](documentation/EXTENSIONS.md)). `katalysis_pro` needs its `ToolDefinition` version to match; see SETUP.md

## Notes
- Chat history is saved by the agent's message store as each message is committed; there is no separate save step.
- Frontend message rendering handles both string and structured content payloads.

## Requirements

- Concrete CMS 9.3+
- PHP 8.1+ with the `curl` extension (`ext-curl`); Neuron AI 4 sends provider requests through curl

