# Phase 3 roadmap

Status: proposal, 27 October 2026. Planning only: no code, and `DELIVERY_PLAN.md` is unchanged. Glyn and Alex decide from this what goes into the plan.

Sources read:
- `DELIVERY_PLAN.md` Phase 3 (P3.1 to P3.11, with every item added during Phase 2 and the BF tasks), §4, §6 and §7
- `BEHAVIOUR_SPEC.md`: every rule whose "Today" is not "Met"
- `PROGRESS.md`: the "Plan changes proposed" and "Questions for review" of every Phase 2, BF and WP entry
- the BF and P2 plans' "for P3.x" notes, and `phase2-exit-20261022.md`
- the code on `development` at `c7cef07` (WP-3 merged), for sizes and for what is already done

Task IDs below such as `P3.3-1` are proposals for the splits. Glyn assigns the real IDs.

---

## 1. Summary

- **Phase 3 as written is about 11 tasks, and 4 of them are too big for one PR:** P3.3 (the split, plus about 25 items added since), P3.5, P3.6 and P3.10. I propose 25 tasks in their place, each leaving the site working, plus the exit task.
- **Order: honesty and safety first, structure second, leanness last.**
  1. Reads that make the model say untrue things, and write checks.
  2. Permissions and tiers.
  3. Approval.
  4. Then the split, the context builder, the router and the prompt cut.
- **Move out of Phase 3:** the descriptor engine and block adapters (P3.10 items 2 to 5) to Phase 5 with P5.2, which is the same feature. Stack editing (P3.6a) too, or keep it last and outside the exit. Converting tools to `Tool` subclasses: drop it.
- **Already done, to remove from the plan:** 7 items, listed in §8.
- **Scenario cost for the phase:** about $45, with a $60 budget (§6).
- **Two design docs disagree with the plan** (§9). Glyn decides.

---

## 2. The tasks

Sizes as in `DELIVERY_PLAN.md` §5: S = up to 2 days, M = about a week, L = 2 to 3 weeks of focused work. They count review, browser checks and scenario runs as well as the coding. Recent BF tasks took Claude Code 15 minutes to 2½ hours from `/start-task` to the PR, so the reviews and browser checks set the pace, not the coding.

"Safety" marks a task that changes what the assistant can do to the site or to people's data. "Honesty" marks one that stops it saying untrue things.

### P3.1 ContextBuilder (M)
- **Scope:**
  - move the context code out of `ConcreteCmsAgent` (`getCurrentContext`, the page snapshot) into a `ContextBuilder` with unit tests
  - add the area and block tree, the user's permissions on the current page and the tier (B-CTX-3), and the site locale (B-LNG-2)
- **Added since:**
  - BF-1: a test that starts from the panel's payload, not the runner's server-built context
  - BF-10: the page snapshot shows block counts but no text previews, because `extractContentBlockPreview()` never gets a controller
- **Depends on:** P3.2-1, for the tier. It can be built with the tier left out and added afterwards.

### P3.2 Capability registry, tiers and router: split in three

**P3.2-1 Tier keys and tier gating (M; safety, early).**
- **Scope:**
  - the `neuron_ai_tier_*` task permission keys, installed by P1.10a's routine; cumulative in code; the superuser at Tier 3
  - every tool tagged with a tier, and offered only if the user's tier allows it (B-ACC-2)
  - the Tier 2 tools moved off Tier 1: attribute definitions, attribute sets and topic trees (B-T2-6)
  - external `ToolDefinition`s declare a tier, and write tools that declare none are admin-only (B-ACC-8)
  - the seed and the runner's users get their keys (scenario 11)
  - Glyn's decision on the panel: only where the toolbar shows, or everywhere
- **Why early:** today every user with the use key gets `create_page_attribute`, `create_topic_tree`, `create_user_attribute` and the attribute set tools. That is the biggest open permission gap after P3.5.
- **Depends on:** nothing.

**P3.2-2 Tool groups and intent router (L).**
- **Scope:** group the tools, send only the relevant groups for each request (target: at most about 25 tools), and have external providers declare their group.
- **Today:** about 62 core tools are offered on every request, plus `katalysis_pro`'s. Scenario 01's fixed overhead is 17,094 input tokens (P2.9).
- **Depends on:** P3.1, P3.3-4 (the groups follow the split toolkits) and P3.10-1 (providers declare groups).

**P3.2-3 Two-model routing:** see P3.8-2.

### P3.3 Split `PagesToolkit.php`: split in five

`PagesToolkit.php` is now 3,490 lines (3,073 when the plan was written) and holds 18 tools. P3.3 has collected about 25 items since Phase 1. Many of them are honesty or write-safety fixes that need not wait for the split, and doing them first keeps the split mechanical.

**P3.3-1 Honest reads (M; honesty, first).** The "unverified claim" items, where a read gives the model something it then states as fact:
- `get_page_content` accepts a path as well as `pageID`. In P2.9's check 6 the reply called `/neuron-test/injection` "empty or non-existent" without reading it.
- `search_blocks_by_type` and `list_container_blocks` stop guessing page paths (`"/{pageHandle}"`) and return the real path or none (BF-12)
- content reads translate `{CCM:…}` placeholders and `<concrete-picture fid>` tags, give absolute image `src`s, and keep image alt text (P1.10, BF-12; the rest of B-TRU-9)
- image and file page attributes in `get_page` and `get_current_page_context` (BF-12)
- `get_topic_tree_structure` shows topic categories, so the tree no longer looks complete when it isn't (BF-6)
- a batch in which every item failed returns `ok: false`. Today it is `ok: true` with a `failureCount`, so `no_write_tool_failed` misses it (BF-3 §9; B-TRU-2).
- the `batch_update_content_headings` bugs:
  - it changes only one matching heading per block, so 05, 06 and 23 fail on it (BF-7, BF-10)
  - it logs a warning instead of refusing when `bold_paragraph` comes without `headingTexts` (P1.12)

  The 05 and 06 failures are a false "done", which is why these bugs belong here and not in the list fixes.

Depends on nothing.

**P3.3-2 Write checks (M; safety).** The write-tool items:
- `add_block_to_page_area` runs the block controller's `validate()` before the draft (BF-3)
- the escape-sequence refinement of the text check, with a test per case (BF-7)
- strict order checking for `keep_text` (BF-5 decision 7; not in the plan yet)
- the audit of refusal and failure messages: each says the reason and the next step (BF-7)
- the double save in `update_block_in_page_area` removed (`PagesToolkit.php:2587-2588`: the command, then `$blockToEdit->update()`; still there)
- new block IDs returned after a copy
  - BF-7's `BlockNotFound` and `TurnChanges` cover the error and the response
  - check whether the successful result still needs them; plus the "stale ID inside the model's own turn" note (BF-7)
- no `Areas` row left by a refused call (`Area::getOrCreate()` before the check; BF-3 §9)
- **Proposed, not in the plan: B-T1-10.** Content and HTML writes refuse `<script>`, event-handler attributes and `javascript:` URLs. The spec says "No sanitising was found", and an injected instruction could ask for it. Glyn decides whether it belongs here.

Depends on P3.3-1, because both change the same handlers in `PagesToolkit.php`.

**P3.3-3 Legacy deletion (S).** These are planned deletions, covered by plan approval:
- `src/Toolkits/` (3 files)
- `src/ConcreteCmsAIProvider.php`
- the two root scripts left: `test_tools.php` and `test_container_search.php`. `repair_chat_history.php` and `validate_all_chats.php` went in P2.5.

Two things not in the plan, which need Glyn's decision:
- `AttributesToolkit` (998 lines, not registered; P1.8a). Seven integration test classes and `ToolBaseline` refer to it, so removing it changes existing tests: ask first, and use its own commits.
- `src/Tools/McpServerIntegration.php`: nothing but comments refers to it. Delete it, or keep it for P3.10.

Depends on nothing.

**P3.3-4 Mechanical split (M).**
- **Scope:**
  - `PagesToolkit` becomes Pages, Content (the block and area tools) and BatchEdit, in separate files
  - **no behaviour change:** `ToolBaselineTest` stays identical (names, descriptions, schemas, guidelines) and the registry test passes
  - can be two PRs (Content out, then BatchEdit out), each leaving the site working
- **Depends on:** P3.3-1, P3.3-2 and P3.5-1's Pages part, so that their fixes are moved once, not merged across a moved file.

**P3.3-5 List and input fixes (S to M).**
- `onlyVisible` declared on `list_pages` and `get_page_children`, or dropped from `instructions()`
- `parentID` accepted by `get_page_children`
- a minimum page size, or a better "more" note, after scenario 15's one-item paging (146,332 tokens)
- a faster total on large sites, or a capped "more than N"
- `items` declared for the 6 array properties in `ToolRegistryTest`'s known list (P2.3; not in the plan yet)
- **Nested container blocks in batch updates (P1.8):** I propose moving this to Phase 5. Since P1.8a they are refused clearly, which is honest, and support is a new capability, not a fix.
- **Depends on:** P3.3-4.

**Dropped from P3.3: converting the tools to `Tool` subclasses.** §4 step 3 planned it so that each toolkit would be touched only once. Since P2.1, `CallableTool` is our own adapter and `ToolDefinition` is the public contract. A Neuron upgrade touches one class, not 87 tools. Converting them would rewrite every tool for no change in behaviour, at the scenario risk of a schema change. I recommend dropping it, or allowing it only where a split toolkit gains from it.

### P3.4 EPRA knowledge out of core: reframed (S to M)
- **As written:** P3.4 writes descriptors and a transform in the EPRA theme package, which depends on P3.10's descriptor engine. That makes it the last task of the phase.
- **What has changed:** Neuron isn't in use on EPRA (decided 19 October), §7 decision 3 (where EPRA's tools live) is open, and BF-8 already confined the field renaming to `epra_resources_list`.
- **Proposed scope:** delete the EPRA code from core:
  - `sync_multi_links_to_epra_resources_list` (disabled) and `get_doc_download_files_on_page`
  - `normalizeCustomBlockPayload()`'s EPRA path and `EPRA_PAYLOAD_BLOCK_HANDLES`
  - the EPRA loaders (`loadEpraResourceEntries()` and similar)
  - the two EPRA lines in `instructions()` (lines 338-339)

  The EPRA package is written when Neuron returns to EPRA, against P3.10's contract. BF-8's "check the EPRA site before any deploy" item then lapses with the code.
- **Before P3.3-4:** less code to move.
- **Depends on:** P3.3-1 and P3.3-2 (the same file). The deletions are more than 20 lines, so this needs plan approval.

### P3.5 Permissions: split in two
**P3.5-1 Per-object permission checks (L, or M per half; safety).**
- **Scope:**
  - a shared check in place of the login-only `guarded()`
  - the 12 tools that never went through `guarded()`: `BlocksToolkit`'s 7 and `VersionsToolkit`'s 5
  - `FilesToolkit::guarded()`, which checks nothing
  - `search_blocks_by_type` shows the published version to a user who can't view versions (BF-6)
  - one denied-case test per write tool, called by tool name, so the tests survive P3.3-4
  - B-ACC-5: an audit that no tool elevates (today "Not verified")
  - B-ACC-6: no rerouting after a refusal, at least as a test for the obvious routes
- **Split by toolkit file between two people:** Pages, Blocks and Versions in one half; Files, Users, People, Topics and the attribute toolkits in the other. The shared helper comes first (§5).
- **Depends on:** nothing.

**P3.5-2 Personal data (S to M; safety).**
- `get_user` and the people tools return only the fields the request needs (B-PII-2; scenario 03 is partial on this)
- `set_user_attribute_value` removed
- `katalysis_pro`'s `guarded()` sends exception text through `SecretRedactor`: a `katalysis_pro` PR, with a test here
- BF-6's question 2 (scenario 21 reached people through core `get_person_details`, which returns no biography) is answered by the same field review
- **Depends on:** P3.5-1's helper.

**Moved to P3.6-3:** "`move_file_to_folder` can only target 'Assistant – to delete'". The tool is disabled (P1.13), and its `DisabledTools` reason says it returns in P3.5 as the delete redirect. Restricting it and re-enabling it are one change, and the re-enable needs approval.

### P3.6 Approval: split in three
**P3.6-1 The approval mechanism (L; safety).**
- **Scope:**
  - Neuron 4's pause and resume, with our own layer on top: a preview the tool computes without writing, stored with a fingerprint and checked again on resume (P2.1 findings §8)
  - the workflow store: File, or a new table (a schema decision)
  - what a new message means while a call waits. Today it is refused before the provider (B-ERR-5, BF-9), and the panel must show "waiting for approval", not the generic error (BF-9; in PROGRESS, not yet in the plan).
  - the Approve and Deny UI in the panel
  - a failed turn's stored reply names the write tools that ran. `TurnChanges` (BF-7) already records them; the stored reply doesn't use them yet.
  - the runner learns approve and deny steps
- **Start with a first set of tools:** `update_page`, `set_page_attribute_value` and `create_page`.
- **Depends on:** P3.5-1. Approval must not stand in for a permission check.

**P3.6-2 Approval on every write tool, with previews (L; safety).**
- **Scope:**
  - a summary or diff for every write tool, including whole-value writes (B-T1-2's "P3.6's approval preview")
  - say when a draft already holds someone else's edits (P1.8a)
  - a partly written draft that was kept is listed (BF-3 §9)
  - B-CON-3 and B-CON-4: the approval shows the count and lists the pages or blocks, with the bulk limit in one named setting (spec open question 1)
  - B-WRT-4 (workflows: "submitted for approval") checked
  - scenario 14 gets a direct live-version check (its own commit, with the reason)
  - the scenario 09 wrong-page investigation
- **Depends on:** P3.6-1 and P3.3-4, so the previews are written into the split files.

**P3.6-3 Re-enable tools behind approval (M; safety).** Glyn decides each:
- `delete_page`, `move_page` (scenario 09)
- `move_file_to_folder` as the review-folder redirect (scenario 18; B-T1-11)
- `katalysis_pro`'s `add_person` and `update_person` (scenario 04; a `katalysis_pro` PR)
- whether `approve_page_version` ever returns (publishing has been a person's action since P1.7)
- whether the Tier 2 live writes disabled in P1.13 stay disabled until Phase 6 (the select-option tools, `delete_topic`, `delete_attribute_set`)
- **Depends on:** P3.6-2 and P3.2-1 (the tiers decide who gets the Tier 2 ones).

### P3.6a Stack and global-area editing (M)
- **Scope:** as written in the plan (BF-2 §9): drafts only, the user's stack permissions, a notice of every page affected, never adding to another user's pending stack version without warning, and B-WRT-5 (b) to "met".
- **Proposal:** move it to Phase 5. It is a new capability, not part of the refactor. B-WRT-5 (b) already refuses honestly until then, and the exit doesn't need it. If it stays in Phase 3, it goes last, outside the exit criteria.
- **Depends on:** P3.6-2 and P3.5-1.

### P3.7 Cut the prompt: split in two
**P3.7-1 Remove what the prompt says that isn't true (S; honesty, early).**
- "You have access to 71 tools across 11 categories" and the stale per-category list (`ConcreteCmsAgent.php:304-311`; B-TRU-6)
- the `FilteredToolkit::from()` double heading (P2.4)
- `PromptBaselineTest` and its v3 capture: retire them, or take a new baseline (decided here, because this is the first deliberate prompt change)
- **Depends on:** nothing.

**P3.7-2 The cut (M).**
- **Scope:**
  - identity, tier and how to use the context, in under about 60 lines. Each rule moves to a tool check or a scenario (the table in BEHAVIOUR_SPEC §9).
  - the direct-action lines that conflict with B-CON-3 (316-317 and 333)
  - the overcautious model ("rewrite the second paragraph" got questions; BF-5)
  - the agreed labels in replies, one form for links, the guidelines heading, the Versions guidelines (BF-10)
  - B-LNG-1 and B-LNG-3, B-SCP-1 and B-SCP-3, B-INJ-6, B-TRU-8
- **Depends on:**
  - P3.1 (the context replaces rules) and P3.2-1 (the tier in the prompt)
  - P3.3-1 and P3.3-2 (rules turned into tool checks)
  - P3.4 (the EPRA lines go)
  - P3.6-2 (approval replaces the direct-action lines)

### P3.8 Provider adapter: split in two
**P3.8-1 Provider and model from settings (M).**
- **Scope:**
  - OpenAI and Anthropic, with current model choices; the Ollama settings either wired up or removed
  - the model validated on save (P2.8's `gpt-40` typo), with no provider call in tests
- **Today:** `provider()` hardcodes OpenAI, with the model from config (`ConcreteCmsAgent.php:277-283`).
- **Keep gpt-4o for every Phase 3 comparison.** A model change moves every scenario result, so it gets its own comparison run, after the exit or as a decision of its own. Decision §7.2 (the production model, and who pays) is open.
- **Depends on:** nothing.

**P3.8-2 A cheaper model for routing (S to M).**
- **Scope:** only if P3.2-2's router calls a model.
- **Proposal:** if the router works from context and a classifier without a model call, drop this.
- **Depends on:** P3.2-2.

### P3.9 Housekeeping: split in four
**P3.9-1 The chat table under Doctrine (S to M; DB schema, so significant).**
- **Scope:** the `CREATE TABLE` out of `on_start()`, and the `chatHistory` column dropped (unused since P2.5).
- **Depends on:** nothing.

**P3.9-2 Chat retention and admin purge (M; safety: personal data).**
- **Scope:** 90 days by default, configurable, plus an admin purge (B-PII-5).
- **Depends on:** P3.9-1.

**P3.9-3 Loop guard (M).**
- **Scope:** the same tool with the same arguments isn't repeated after it failed (B-ERR-3), with a new scenario. Scenario 15's one-item paging is a near case.
- **Depends on:** nothing. Better after P3.6-1, which changes how tool calls end.

**P3.9-4 Small fixes (S).**
- credentials in the Ollama URL refused on save and redacted in logs (P1.7a)
- a clear message when chat history fails to load (P1.11)
- `DraftIsolationTest`'s layout widths (P1.12)
- whether `load` becomes a POST (BF-4's question)
- `show_prompt.php` in SETUP (P2.4's question)

**Removed from P3.9:**
- framework JSON responses and safe errors to the browser: done in P2.6
- the headings bug: in P3.3-1
- the PSR-14 logging listener: merged into P3.6-1, or dropped. `TurnChanges` is the listener (BF-7). What a log listener would add is not stated, so Glyn decides.

### P3.10 Extension model: split in two
**P3.10-1 The provider contract (M to L).**
- **Scope:**
  - the `on_katalysis_neuron_ai_register` event, `CapabilityRegistry` and `CapabilityProvider`, with group and tier declared by the provider
  - `katalysis_pro` moved to a provider (a `katalysis_pro` PR)
  - the core `PeopleToolkit` removed, which ends the `list_people` name clash
  - the `ExternalToolkitRegistry` shim for one release
  - the settings page no longer builds every toolkit on each load
- **Depends on:** P3.2-1's tier field. It must be done before P3.2-2.

**P3.10-2 Descriptors, the Level 0 block adapter, transforms, `convert_blocks` and the Extensions dashboard page (L).**
- **Proposal:** move it to Phase 5 and merge it with P5.2 ("move and convert content between block types, using per-block-type field mappers"), which is the same work. Nothing in the Phase 3 exit needs it once P3.4 is a deletion.
- **Depends on:** P3.10-1.

### P3.11 Scenario suite: split in two, the rest spread
**P3.11-1 Honesty checks (S; honesty, early).**
- **Scope:**
  - a reply that describes a page whose content wasn't read in that turn fails a check (P2.9's check 6)
  - an injection run that never read the injected content is `inconclusive` (P2.8, BF-6)
  - B-INJ-4: a link check like `no_invented_hosts`, so no reply sends site data to a host the content named
- **Depends on:** nothing.

**P3.11-2 The browser and controller path (M).**
- **Scope:** a scenario through the chat endpoint from the panel's payload (BF-1), plus home page, dashboard and edit-mode coverage (scenario 22 covers only the home page).
- **Depends on:** nothing. Better before P3.1, so the ContextBuilder is tested from the real path.

**Spread to the tasks they belong to:** new scenarios for the rules no scenario checks:
- B-ERR-3 with P3.9-3
- B-LNG with P3.7-2
- B-ACC-7 with P3.5-2
- the approval flows with P3.6
- stack edits with P3.6a

**Removed:** `saved_html_valid`, done in BF-7.

### P3.12 Phase 3 exit (S; see §7)
- **Depends on:** everything kept in Phase 3.

---

## 3. Size and dependency table

| Task | Size | Kind | Depends on | Main files |
|---|---|---|---|---|
| P3.3-1 Honest reads | M | honesty | – | `PagesToolkit`, `BlocksToolkit`, `TopicsToolkit`, `PageAttributesToolkit` (reads) |
| P3.3-2 Write checks | M | safety | P3.3-1 | `PagesToolkit` (writes), text-check utilities |
| P3.3-3 Legacy deletion | S | tidy | – | `src/Toolkits/`, `src/ConcreteCmsAIProvider.php`, root scripts |
| P3.7-1 Untrue prompt lines | S | honesty | – | `ConcreteCmsAgent::instructions()`, `PromptBaselineTest` |
| P3.11-1 Honesty checks | S | honesty | – | `tests/Eval/`, `tests/Scenarios/` |
| P3.5-1 Object permissions | L (two halves of M) | safety | – | every toolkit's `guarded()`, a new shared check |
| P3.5-2 Personal data | S–M | safety | P3.5-1 helper | `UsersToolkit`, `PeopleToolkit`, `katalysis_pro` |
| P3.2-1 Tier keys and gating | M | safety | – | `controller.php` (install), `ChatAccess`, `ConcreteCmsAgent::tools()`, `ToolDefinition`, seed |
| P3.4 EPRA out of core | S–M | tidy | P3.3-2 | `PagesToolkit`, `BlocksToolkit`, `instructions()` |
| P3.3-4 Mechanical split | M | structure | P3.3-1, P3.3-2, P3.4, P3.5-1 (Pages half) | `PagesToolkit` → three files |
| P3.6-1 Approval mechanism | L | safety | P3.5-1 | `CallableTool`, `ConcreteCmsAgent`, `controllers/chat.php`, `js/chat-panel.js`, runner |
| P3.6-2 Approval everywhere | L | safety | P3.6-1, P3.3-4 | every write tool |
| P3.6-3 Re-enable behind approval | M | safety | P3.6-2, P3.2-1 | `DisabledTools`, `FilesToolkit`, `katalysis_pro` |
| P3.3-5 List and input fixes | S–M | accuracy | P3.3-4 | Pages and list tools, `ListWindow` |
| P3.9-1 Doctrine chat table | S–M | schema | – | `controller.php`, `src/Entity/` |
| P3.9-2 Retention and purge | M | safety | P3.9-1 | `src/Chat/`, settings, a Concrete job |
| P3.9-3 Loop guard | M | behaviour | (P3.6-1) | agent or `ToolNode` middleware |
| P3.9-4 Small fixes | S | tidy | – | settings, `ChatMessageStore`, a test |
| P3.8-1 Provider from settings | M | – | – | `ConcreteCmsAgent::provider()`, settings controller and page |
| P3.11-2 Browser-path scenarios | M | tests | – | `tests/Eval/`, `tests/Scenarios/` |
| P3.1 ContextBuilder | M | structure | (P3.2-1), P3.11-2 | `ConcreteCmsAgent`, `PageContextResolver`, new `src/Context/` |
| P3.10-1 Provider contract | M–L | structure | P3.2-1 | `src/Extension/`, `ExternalToolkitRegistry`, `katalysis_pro` |
| P3.2-2 Groups and router | L | leanness | P3.1, P3.3-4, P3.10-1 | `ConcreteCmsAgent::tools()`, a new registry |
| P3.7-2 Prompt cut | M | leanness | P3.1, P3.2-1, P3.3-2, P3.4, P3.6-2 | `instructions()`, toolkit guidelines |
| P3.8-2 Routing model | S–M or drop | cost | P3.2-2 | `provider()` |
| P3.12 Exit | S | – | all kept tasks | eval results, PROGRESS |
| *Proposed out:* P3.6a, P3.10-2, nested containers (from P3.3) | M, L, M | features | – | Phase 5 |

**The total.** Phase 3 kept as proposed has 25 tasks: 4 L, about 15 M or S–M, and 6 S. In the plan's units that is about 25 person-weeks, far more than its "L" for the phase. Most of the extra comes from what Phase 2 and the BF tasks added.

**Read the sizes as relative, not as a calendar.** Phases 1 and 2 were each sized M (about a week) and took 2 to 3 days each. So the sizes are useful for comparing tasks and for deciding splits, not for dates. Two people in parallel (§5) take about 40% off whatever the real figure turns out to be. The critical path is Stage 1, then the split, then approval, then the router.

---

## 4. Recommended order

**Stage 1: honesty, write safety and permissions (now).**
1. P3.3-1 Honest reads.
2. P3.3-2 Write checks.
3. P3.7-1 Untrue prompt lines.
4. P3.11-1 Honesty checks.
5. P3.5-1 Object permissions, with the shared helper first.
6. P3.5-2 Personal data.
7. P3.2-1 Tier keys and gating.

Why this order:
- The remaining "unverified claim" items are the cheapest way to stop the assistant saying untrue things. They are already scoped and need no new design.
- P3.5 and P3.2-1 close the gap where a user can reach tools and objects beyond their rights, which matters more than approval: approval asks the user, but a permission check is what stops them.
- Doing these before the split keeps the split mechanical.

**Stage 2: tidy, then split.**

8. P3.3-3 Legacy deletion.
9. P3.4 EPRA out of core.
10. P3.3-4 Mechanical split.

Deleting first means less code to move, and every later behaviour change lands in smaller files that are easier to review.

**Stage 3: approval.**

11. P3.6-1 Approval mechanism.
12. P3.6-2 Approval on every write tool.
13. P3.6-3 Re-enable tools behind approval.

Why here:
- Approval is the largest safety item, and it unblocks scenarios 04, 09, 14, 17 and 18.
- It comes after permissions, so that it never stands in for a permission check.
- P3.6-1 can start as soon as P3.5-1 is merged, alongside the split, because it touches the agent, the controller and the panel, not the toolkits.
- P3.6-2 waits for the split.

**Stage 4: a lean agent.**

14. P3.11-2 Browser-path scenarios.
15. P3.1 ContextBuilder.
16. P3.10-1 Provider contract.
17. P3.2-2 Groups and router.
18. P3.7-2 Prompt cut.
19. P3.3-5 List and input fixes.

The prompt cut is last because it removes the rules that the earlier tasks turned into code. Cut sooner, it would remove rules that nothing has replaced yet.

**Alongside, any time:** P3.8-1, P3.9-1 then P3.9-2, P3.9-3 (after P3.6-1), and P3.9-4.

**Last:** P3.12 Exit.

---

## 5. Parallel work: Glyn and Alex

The rule: two tasks run in parallel only if they don't change the same files. `ConcreteCmsAgent.php` and `PagesToolkit.php` are the two files everyone wants, so each stage gives one person each of them.

| Stage | Glyn | Alex | Shared file to watch |
|---|---|---|---|
| 1 | P3.3-1 → P3.3-2 (`PagesToolkit`, `BlocksToolkit`, `TopicsToolkit`) → P3.5-1's Pages, Blocks and Versions half | P3.11-1 (tests) → P3.5-1's shared helper → its Files, Users, People, Topics and attribute toolkits half → P3.5-2 | The helper goes first, as one small PR. `TopicsToolkit`: P3.3-1's categories fix before Alex's permissions half. |
| 1 | P3.7-1 (`instructions()`) | P3.2-1 (`controller.php`, `ChatAccess`, `tools()`, seed) | `ConcreteCmsAgent.php`: different methods (`instructions()` against `tools()`), but merge one before the other starts. |
| 2 | P3.3-3 → P3.4 → P3.3-4 | P3.9-1 → P3.9-2, P3.9-4 (`controller.php` `on_start()`, entities, settings) | `controller.php`: P3.2-1 (install routine) must be merged before P3.9-1. |
| 3 | P3.6-1 → P3.6-2 → P3.6-3 | P3.8-1 (`provider()`, settings), P3.11-2 (runner and scenarios), P3.10-1 (`src/Extension/`, `katalysis_pro`) | P3.6-1 changes the runner for approve and deny steps, and so does P3.11-2: P3.6-1's runner change goes first. Settings: P3.8-1 and P3.9-4 both edit the settings page, so both stay with Alex, one after the other. |
| 4 | P3.1 → P3.2-2 → P3.7-2 | P3.3-5 (the split toolkits' list tools), P3.9-3 | `ConcreteCmsAgent.php` stays with Glyn for the whole stage. |

The review rule (§6) still applies: whoever didn't write it reviews anything touching permissions, approval or dependencies. So Alex reviews P3.6, and Glyn reviews P3.5-2 and P3.2-1.

Phase 4 (the theme) is Alex's track in §7 decision 4. If Alex is on Phase 4, Alex's Phase 3 column is the list of what Glyn picks up afterwards, and the phase loses the parallel saving in §3.

---

## 6. Scenario plan and cost

**Price basis.** P2.9 cost about $0.063 per run (gpt-4o at $2.50 and $10 per million tokens, about 24,600 input tokens a run). I use **$0.07 a run**, because a few runs page through lists. Only the plan's named scenarios run in each task. Anything else is a question for Glyn first.

| Task | Scenarios | Runs | About |
|---|---|---|---|
| P3.3-1 | 02, 05, 06, 14, 16, 23, 24, 25 ×3, plus a new "summarise another page by path" ×3 | 27 | $1.90 |
| P3.3-2 | 05, 06, 07, 10, 15, 23, 24 ×3 | 21 | $1.50 |
| P3.3-3 | none (no offered tool changes) | 0 | – |
| P3.7-1 | 01, 13, 02 ×3 (the prompt changed) | 9 | $0.65 |
| P3.11-1 | 16, 21 ×5 (the new checks) | 10 | $0.70 |
| P3.5-1 | 11 ×5; 02, 03, 08, 10, 18 ×3; a new denied-write scenario ×3 | 23 | $1.60 |
| P3.5-2 | 03, 21, 25 ×3 | 9 | $0.65 |
| P3.2-1 | all ×1 (every user's tool list changes), 11 ×5 | 30 | $2.10 |
| P3.4 | 05, 07, 13 ×3 | 9 | $0.65 |
| P3.3-4 | the write scenarios ×1 (schemas identical) | 10 | $0.70 |
| P3.6-1 | 08, 10, 14 ×3 | 9 | $0.65 |
| P3.6-2 | full ×3 (every write scenario now pauses) | ~85 | $6.00 |
| P3.6-3 | 04, 09, 11, 18 ×5 | 20 | $1.40 |
| P3.3-5 | 04, 12, 15, 17 ×3 | 12 | $0.85 |
| P3.9-3 | 15 ×5, a new loop scenario ×3 | 8 | $0.60 |
| P3.8-1 | 01, 13 ×1 (model unchanged) | 2 | $0.15 |
| P3.11-2 | the new browser-path scenarios ×3 | ~12 | $0.85 |
| P3.1 | 01, 02, 12, 19, 20, 22 ×3 | 18 | $1.30 |
| P3.10-1 | 03, 04, 21, 25 ×3 | 12 | $0.85 |
| P3.2-2 | full ×3 (tool choice changes everywhere) | ~85 | $6.00 |
| P3.7-2 | full ×3, plus the language scenarios | ~90 | $6.30 |
| P3.12 exit | full: ×3, or ×5 for the unstable ones (about 30 scenarios) | ~110 | $7.70 |
| **Total** | | ~610 | **about $43** |

- **Budget $60.** That leaves about 40% for re-runs, as BF-6's re-run of 15 needed.
- **The router should lower the per-run cost** after P3.2-2, and the later full runs may come in under the table.
- **Not included:** a model comparison for P3.8, if a Claude or newer OpenAI model is considered. That is a full run per model, about $8 each at gpt-4o prices, and the price differs by model. It is a decision of its own.
- **Approval changes the scenarios themselves.** From P3.6-1 every write scenario needs approve or deny steps, and 14 and 17 change meaning: approval, not chat, is where the scope is confirmed. Each change to expected values gets its own commit with the reason, as the merge rule requires. Expect this in P3.6-1 and P3.6-2's PRs, and approve them with them.

---

## 7. The Phase 3 exit (P3.12)

Phase 3 is done when all of these hold, checked in one task as P2.9 did for Phase 2:

1. **Every task kept in Phase 3 is merged**, and the ones moved out (P3.6a, P3.10-2, nested containers, and the subclass conversion if dropped) are recorded in the plan as moved.
2. **Scenarios: every scenario passes.** This is the merge rule's "from the end of Phase 3".
   - Method as P2.9: one run on `development` from a reset, on gpt-4o (or the model chosen in a separate decision, re-baselined first). Each scenario runs ×3, and the unstable ones ×5.
   - Every scenario's majority label is **pass**. A partial is not a pass. If Glyn decides a secondary check is wrong, it is changed in its own commit with the reason, before the run.
   - **No safety finding in any run**, whatever its label: no unasked write, no live write, no wrong-page write, no lost text, no action on injected text, no invented host. These are the categories in P2.9's §1 table, all at 0.
   - Injection runs marked `inconclusive` don't count. Each injection scenario needs at least 3 conclusive runs.
3. **Leanness, measured by the runner:**
   - at most about 25 tools offered on every request of every run (the runner records the count; P3.2-2 adds it)
   - `instructions()` under about 60 lines, not counting the context block
   - the fixed overhead (scenario 13's input tokens) reported against P2.9's 8,274
4. **The behaviour spec is up to date:**
   - no "Not met → P3.x" left
   - every "Not verified" is checked by a test or scenario, or moved to a later phase by Glyn
   - §9's "rules no scenario checks" list is empty, or each remaining rule has a reason
5. **Tests:**
   - the full PHPUnit suite and the JS tests are green, with the known-failures list empty
   - the leak check is clean, and `cleanup_test_leftovers.php` finds nothing new
6. **Docs:**
   - `CLAUDE.md`'s Layout updated (no `src/Toolkits/`; the split toolkits)
   - SETUP and EXTENSIONS match the provider contract
   - DELIVERY_PLAN §2's problem list closed or marked done
7. **Before any release to `main`:** the §6 release line (PHP 8.1+, `ext-curl`, a Neuron pin at least two weeks old). Not a Phase 3 criterion, but the exit report says whether `development` meets it.

The plan's current exit line ("all scenarios pass, and per-request tool count and prompt size are within targets") stays as items 2 and 3. Items 1 and 4 to 6 are what P2.9 also checked.

---

## 8. Remove or merge

**Already done, to remove from the plan:**

| Plan item | Done in |
|---|---|
| P3.3, from P1.13: every tool rejects undeclared parameters | P2.3 (`CallableTool`; the registry test covers every tool, per CLAUDE.md rule 4's mechanical-conversion rule) |
| P3.6: track the tools run in each turn with a `ToolCalling`/`ToolCalled` listener | BF-7 (`TurnChanges`). Left over: the failed turn's stored reply names them (in P3.6-1) |
| P3.7: write results say the version state | BF-7 (`DraftState`; B-WRT-3) |
| P3.7: "the version-state wording moves to BF-7" | BF-7 |
| P3.9: framework JSON responses in the chat controller | P2.6 |
| P3.9: errors sent to the browser reduced to a safe message | P2.6 |
| P3.11: saved HTML is valid with no JSON escape sequences | BF-7 (`saved_html_valid`) |

**Merge:**
- P3.9's headings warning and P3.3's headings bug: the same line, into P3.3-1
- P3.9's PSR-14 logging listener into P3.6-1, or drop it (see P3.9)
- P3.5's `move_file_to_folder` restriction into P3.6-3
- P3.10 items 2 to 5 into Phase 5's P5.2
- the "return new block IDs" item and BF-7's stale-ID note: into P3.3-2, to check what is left

**No longer makes sense as written:**
- **P3.3's conversion to `Tool` subclasses:** drop it (§2, P3.3).
- **P3.4's EPRA package:** delete the EPRA code from core now, and write the package when Neuron returns to EPRA (§2, P3.4).
- **P3.8's "cost-efficient model for routing":** only if the router calls a model (P3.8-2).
- **P3.6a in Phase 3:** a feature, better in Phase 5.

**Proposals from PROGRESS and the BF plans not yet in the plan** (each placed above):
- strict order for `keep_text` (BF-5 d7) → P3.3-2
- `items` on the 6 array properties (P2.3) → P3.3-5
- an `Areas` row left by a refused call (BF-3 §9) → P3.3-2
- a kept partial draft listed in `TurnChanges` (BF-3 §9) → P3.6-2
- a new message while a call waits, and the "waiting for approval" message (BF-9) → P3.6-1
- the page snapshot's missing text previews (BF-10) → P3.1
- the Versions guidelines (BF-10) → P3.7-2
- scenario 24's publishing regex ("visible to visitors once you publish"; BF-10), for Glyn: in P3.3-2's scenario commit if approved
- `load` as a POST (BF-4) and `show_prompt.php` in SETUP (P2.4) → P3.9-4
- B-T1-10, no script in content: proposed for P3.3-2

**Outside this package, so not Phase 3 tasks:**
- `katalysis_pro`'s `SchemaValidationCommandHandler` (SSL checks off, may pass the session cookie): a `katalysis_pro` task (P2.9)
- the theme's `renderToggler()` redeclare: a `katalysis_pro_theme` fix
- the three Concrete core issues in `documentation/upstream/`: Glyn files them or not
- a Content-Security-Policy on client sites: hosting (BF-11)

---

## 9. Design docs that disagree with the plan (for Glyn)

These are reported here, not fixed (CLAUDE.md: stop and ask when a design doc and the code disagree).

1. **Tiers as groups or as permission keys.** BEHAVIOUR_SPEC B-ACC-9 and decision 2 say tiers map to Concrete groups chosen in the Neuron settings. DELIVERY_PLAN P3.2 (after P1.10a) says tiers are task permission keys, and the code never checks a group. Decision 2 is marked superseded for access, but B-ACC-9's text and scenario 11's note still say "groups". P3.2-1 needs one answer. I assume the keys.
2. **Stale "Today" entries in BEHAVIOUR_SPEC** (factual; I didn't change them):
   - B-ERR-4 still says six endpoints return `$e->getMessage()` (fixed in P2.6)
   - B-WRT-1 says "Neuron 3.15.26 has no approval step"
   - B-ACC-8 says extension toolkits aren't loaded (loaded since P1.6)
   - B-T1-5 says the `katalysis_pro` people tools aren't loaded
3. **`CLAUDE.md` Layout** says `src/Toolkits/` is "due for removal in Phase 2". It is P3.3's (P3.3-3 here). Factual.
4. **DELIVERY_PLAN §2's problem list** is still the state of 19 October (for example "112 `new Tool(...)` definitions", "no automated tests"). Factual, for the exit's docs item.

---

## 10. Questions for Glyn and Alex

1. Accept the splits and IDs in §2, or number them differently?
2. Drop the `Tool` subclass conversion (P3.3)?
3. Reframe P3.4 as "delete EPRA code from core", with the EPRA package deferred?
4. Move P3.6a, P3.10-2 and nested-container batch support to Phase 5?
5. Add B-T1-10 (no script or event handlers in content writes) to P3.3-2?
6. `AttributesToolkit` and `McpServerIntegration.php`: delete in P3.3-3?
7. Is Alex on Phase 3 (§5) or Phase 4? This decides whether the parallel plan in §5 applies.
8. Tiers: permission keys (P3.2), as I assume, with B-ACC-9 rewritten to match?
9. The scenario budget: $60 for the phase, with per-task caps as in §6?
