# Katalysis Neuron AI Plan

Date updated: 27 July 2026
Status: Active implementation plan (post-PoC)

## Objective
Maintain and harden the Concrete CMS dashboard AI assistant now that core chat, persistence, and toolkit functionality is live.

## Current Delivered State

### Core assistant behavior
- Dashboard chat panel is injected via package startup hooks.
- Chat requests are handled through package routes and controller actions.
- Chat agent is active and connected to provider configuration.

### Persistence and conversation lifecycle
- Conversations are persisted in database table KatalysisNeuronAiChats.
- Chat history is loaded by chat ID and reused across requests.
- Previous chats can be listed and loaded in UI.
- New chat flow is available from panel header.

### Toolkit coverage
- Current model: toolkits are native PHP tools (direct Concrete CMS classes), not HTTP calls to /ccm/api/v1.

### Tool implementation policy (authoritative)
- Default rule: when a built-in Concrete API endpoint exists, implement the toolkit tool so behavior aligns with that endpoint's core execution path (permissions, versioning, validation, side effects, and response semantics), even when invoked natively.
- Access-control rule: chatbot capability is permission-scoped to the active Concrete user context (including guest context), and every tool action must enforce native Concrete permission checks; no toolkit implementation may bypass those checks.
- Preferred architecture: native invocation of the same underlying core logic used by API/controllers, rather than introducing HTTP overhead inside the package.
- Custom-only tools are allowed only when:
  - no suitable built-in API endpoint exists, or
  - the custom approach is demonstrably better (measurable performance, reliability, or UX gains) and documented.
- Every custom-only tool must include a short rationale in this plan and be periodically re-evaluated if new API coverage appears.

- Pages toolkit (created in package; API-aligned semantics):
  - list_pages (API-aligned page listing with permissions and visibility filtering)
  - get_page (API-aligned page read with active/recent version checks)
  - create_page (API-aligned create flow with type/template checks and attribute mapping)
  - update_page (API-aligned update flow with RECENT version editing and attribute mapping)
  - move_page (mimics sitemap move operations)
  - delete_page (API-aligned command-based delete/trash operation)
  - get_page_children (API-aligned child-page listing with parent/child permission checks)
  - add_block_to_page_area (API-aligned area-level permission check and add-block command flow)
  - update_block_in_page_area (API-aligned block update command flow)
  - delete_block_from_page_area (API-aligned block delete command flow)
  - list_page_types (custom-only helper; not a direct built-in API endpoint)

- Files toolkit (created in package; API-aligned semantics):
  - list_files (API-aligned file listing with file-manager permission checks)
  - get_file (API-aligned file read by ID or UUID with permissions)
  - delete_file (mimics delete file endpoint)
  - list_file_folders (custom helper for folder tree traversal)

- Users toolkit (created in package; API-aligned semantics):
  - list_users (API-aligned user listing with view permissions and date-added ordering)
  - get_user (API-aligned user read with repository lookup and view permission checks)
  - get_current_user (custom helper for current session identity)
  - search_users (custom convenience search wrapper)

### Reliability and cleanup already completed
- Temporary high-volume debug logging reduced.
- Sensitive error details removed from frontend API responses.
- Chat load rendering hardened for mixed message content shapes.
- Obsolete throwaway test scripts removed from httpdocs root.

### MCP parity and audit summary (27 July 2026)

Current state:
- Native toolkits are now the primary implementation path and cover core page lifecycle plus page-area block add/update/delete.
- Tool behavior is enforced by Concrete permissions in the active user context.
- Runtime response envelopes are normalized across representative success and failure paths (ok/error contract).

Still missing for broader API parity:
- File lifecycle breadth: upload_file, update_file, move_file.
- User admin breadth: create_user, update_user, delete_user, change_user_password.
- Group and page-version endpoint coverage remains out of scope for this cycle.

Validation status:
- Guest/runtime endpoint smoke checks are passing.
- Manual runtime checks verified high-priority page/block/user/file read flows.
- Authenticated admin mutation checks remain manual/best-effort in this environment.

Automation helper:
- Runtime smoke script: documents/runtime_smoke_test.sh
- Default guest/runtime run: BASE_URL=http://katalysis-epra-theme.test ./documents/runtime_smoke_test.sh
- Optional admin mutation run (best-effort): RUN_ADMIN_MUTATION_CHECKS=1 ADMIN_USER=<email> ADMIN_PASS=<password> BASE_URL=http://katalysis-epra-theme.test ./documents/runtime_smoke_test.sh
- Strict admin-required mode when needed: REQUIRE_ADMIN=1 RUN_ADMIN_MUTATION_CHECKS=1 ADMIN_USER=<email> ADMIN_PASS=<password> BASE_URL=http://katalysis-epra-theme.test ./documents/runtime_smoke_test.sh

### API-backed tool gaps (endpoint exists, toolkit missing)

| Missing toolkit tool | Existing core API endpoint | Why add | Priority |
|---|---|---|---|
| upload_file | POST /files (Files::add) | Close primary file management parity gap | High |
| update_file | PUT /files/{fID} (Files::update) | Needed for metadata edits and parity | High |
| move_file | POST /files/{fID}/move (Files::move) | Needed for folder organization workflows | Medium |
| create_user | POST /users (Users::add) | Admin parity for identity lifecycle | Medium |
| update_user | PUT /users/{uID} (Users::update) | Admin parity for user maintenance | Medium |
| delete_user | DELETE /users/{uID} (Users::delete) | Admin parity and lifecycle completion | Medium |
| change_user_password | POST /users/{uID}/change_password (Users::changePassword) | Important admin support flow | Medium |

## Gaps and Risks
- Follow-up context handling for short pronoun queries can still be improved.
- Route/controller response handling still uses manual header plus echo/exit patterns and could be standardized.
- Several legacy or unused imports/patterns likely remain in controller and agent classes.
- No formal automated regression test coverage for chat list/load/new conversation flows.
- No explicit release checklist for package updates and cache/ORM migration validation.

## Next Steps (Prioritized)

### Phase 1: Stability hardening
1. Standardize chat controller responses to framework response objects.
2. Remove remaining dead imports and stale compatibility code.
3. Add strict input validation and consistent error codes for all chat endpoints.
4. Add defensive parsing utilities shared by load, restore, and render paths.

Exit criteria:
- Chat send/list/load/new endpoints return consistent JSON contracts.
- No frontend runtime errors when loading old or malformed chat payloads.

### Phase 2: Conversation quality
1. Improve instruction handling for short follow-up prompts.
2. Add lightweight server-side conversation summary anchor per chat.
3. Add tool-call guardrails to prevent repetitive same-argument loops.

Exit criteria:
- Follow-up prompts such as and are there others resolve correctly from immediate prior context.

### Phase 3: Developer quality and release safety
1. Add minimal integration tests for:
   - send message
   - list chats
   - load chat
   - create new chat
2. Add a package-level maintenance checklist in documents.
3. Add a short migration and cache-clear checklist to setup docs.

Exit criteria:
- Repeatable validation steps exist before package update/deploy.

### Phase 4: Feature expansion (optional, after hardening)
1. Expand page editing/content tools based on most common user intents.
2. Reassess MCP integration only if native toolkit coverage becomes limiting.
3. Consider admin diagnostics view for chat health metrics and failure rates.

## Immediate Action Queue
1. Refactor chat controller response pattern.
2. Add endpoint contract and payload validation pass.
3. Run authenticated admin runtime validation for page and block mutation workflows.
4. Add regression tests for load-path rendering, chat lifecycle, and block-edit tools.

## Not In Scope Right Now
- Full MCP server migration.
- Realtime streaming transport.
- End-user skill marketplace or advanced skill UI.
- Broad production observability platform integration.
