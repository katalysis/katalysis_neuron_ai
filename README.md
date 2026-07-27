# Katalysis Neuron AI Package

Concrete CMS dashboard assistant powered by Neuron AI.

## Current capabilities

- Chat panel injected on dashboard pages
- Persistent chat history saved in database table `KatalysisNeuronAiChats`
- List and load previous chats for the current user
- Start a new chat session from the panel header
- Toolkits for pages, files, and users
- Concrete CMS page-type aware responses via dedicated tooling

## Routes

- `POST /ccm/system/katalysis_neuron_ai/chat/send_message`
- `GET /ccm/system/katalysis_neuron_ai/chat/list`
- `GET /ccm/system/katalysis_neuron_ai/chat/load?id={chatId}`
- `POST /ccm/system/katalysis_neuron_ai/chat/new`

## Notes

- Chat history persistence is handled by `DatabaseChatHistory` and is automatic on each message.
- Frontend message rendering handles both string and structured content payloads.

## Requirements

- Concrete CMS 9.3+
- PHP 8.1+

