# Katalysis Neuron AI: Delivery Plan

Status: Draft for Glyn and Alex to review, 19 October 2026.
Supersedes the "Next Steps" section of `KATALYSIS_NEURON_AI_PLAN.md`. Keep that file for its tool policy.

---

## 1. What we're building

Neuron AI is an AI assistant built into Concrete CMS. It helps people **edit, configure and build** Concrete sites. Its guard rails are tight enough that anyone who owns a Concrete site could use it safely.

The design rests on one principle: **for anything beyond content edits, the AI never writes free-form code.** It produces structured definitions, and fixed, tested code carries them out.

| Tier | Capability | What the AI produces | Who can use it |
|---|---|---|---|
| 1. Content | Edit pages and blocks, move content between block types, import structured content (e.g. People from pasted HTML) | Tool calls, previewed before saving | Editors |
| 2. Configure | Express objects, forms, entry lists, single pages using approved components | Tool calls against Concrete's own APIs. No code | Site admins |
| 3. Generate | Custom packages: Doctrine entities, dashboard CRUD, search, blocks, single-page apps | An **app spec** (JSON), checked against a schema. A fixed generator writes the code, which goes to staging and is reviewed | Admins, with review |
| 4. Custom | Free-form code | Code | Developers only, in Claude Code, not inside the CMS |

The first real users are EPRA (content editing and their Claude-built data apps) and Katalysis's own build team.

---

## 2. Current state (code review, 19 October 2026)

### What works and is worth keeping
- The chat panel UI, the routes, and chat persistence (save, list, load, new, delete).
- Page context injection (`ConcreteCmsAgent::getCurrentContext`). The assistant knowing where it is was the strongest part.
- The native-toolkit policy in `KATALYSIS_NEURON_AI_PLAN.md`: reuse core API logic and enforce Concrete permissions. It's sound, so keep it.
- `BlockIdRemapper` and the text-integrity checks (checksums, `formatting_only`, `scoped_text` modes). These are real safety ideas.
- The People toolkit and the heading and batch edit tools, as working behaviour to reproduce.

### Problems found
1. **Most of the work isn't in git.** The last commit was 28 July 2026. Since then there are 11 modified files (+3,034 / −611 lines) and 10 untracked toolkits (Blocks, Attributes, People, Topics, Versions and others). The September edits to `PagesToolkit.php`, `BlocksToolkit.php`, `controllers/chat.php` and `ConcreteCmsAgent.php` are most likely the "wrong turn". There is no committed copy of the working August state to compare against or roll back to.
2. **Too many tools in one agent.** There are roughly 70–130 tools across 11 toolkits, all loaded on every request, and `PagesToolkit.php` alone is 3,073 lines. Model accuracy at choosing tools falls off sharply at this scale, and every request pays the token cost of carrying every tool definition.
3. **The prompt is being used as a patch layer.** `instructions()` holds dozens of situation-specific rules (heading edits, truncation checks, EPRA migrations). Each bug fix has added a rule. Rules like these belong in tool behaviour and tests, not in prose.
4. **Client-specific code is mixed into the core.** `sync_multi_links_to_epra_resources_list`, `epra_resources_list` handling and `get_doc_download_files_on_page` are EPRA-only, but they sit inside the generic Pages toolkit and the system prompt.
5. **There is dead or duplicate code.** `src/Toolkits/` duplicates the older `src/Tools/` files. There are also stray root scripts (`test_tools.php`, `repair_chat_history.php`, `validate_all_chats.php`, `test_container_search.php`) and committed `.DS_Store` files.
6. **The provider is hardcoded.** The agent always uses OpenAI `gpt-4o`. The settings for Anthropic (still `claude-2`) and Ollama exist but aren't used.
7. **The schema is managed by hand.** `CREATE TABLE IF NOT EXISTS` runs on **every request** in `on_start()`, even though `src/Entity/Chat.php` exists. Concrete/Doctrine should manage the table instead.
8. **There are no automated tests**, so nothing catches a regression like the one that happened.
9. **The repo lives inside the EPRA site** (`epra_com_kat/httpdocs/packages/`). Product development is tied to one client's install.

---

## 3. Guard rails (apply to all work)

- **Context is assembled by fixed code.** The model is handed "where am I, what's here, what can this user do". It never has to work that out with exploratory tool calls.
- **Every write is previewed first and can be undone.** Writes go to a new page version, the user sees a diff or summary, and nothing is published without an explicit OK. Undo uses Concrete's version history.
- **Permissions come from the Concrete user**, and every tool checks them (existing policy). A tool is only offered to the model if the user's tier allows it.
- **Each tool has a typed contract** (input schema plus an `{ok, data | error}` envelope) and a test.
- **Rules belong in tools, not prompts.** If behaviour must always hold (e.g. "don't drop body text"), enforce it in code and test it.
- **Client-specific tools live in their own packages.** They register through `ExternalToolkitRegistry`.
- **Behaviour scenarios must pass** before anything is merged.

---

## 4. Target architecture

```
Chat panel (JS)
   │
Chat controller ── Conversation store (Doctrine entity)
   │
Agent orchestrator
   ├── ContextBuilder       fixed: page, areas, blocks, user, permissions, tier
   ├── Intent router        picks 1–3 capability groups per request
   ├── Capability registry  tool groups, tagged by tier and permission
   │     ├── core: pages, blocks, content, files, users, attributes, versions
   │     ├── configure: express, forms, single pages
   │     ├── generate: app-spec authoring and validation
   │     └── external: per-client/per-package toolkits (e.g. EPRA)
   ├── Change set + preview  stage writes, show diff, apply on confirm
   └── Provider adapter     Anthropic / OpenAI / Ollama from settings
   
Generator (separate from the agent)
   app spec (JSON Schema) → templates → package on a staging/branch → review → install
```

---

## 5. Phases

Sizes: S = up to 2 days, M = about a week, L = 2–3 weeks of focused work.

### Phase 0: Make it safe to change anything (S, do this first)
1. **Look for the August working versions** before anything else changes:
   - VS Code: Explorer → Timeline (Local History) on `PagesToolkit.php`, `BlocksToolkit.php`, `ConcreteCmsAgent.php` and `controllers/chat.php`
   - Time Machine, if enabled
   - other copies on dev, staging or live servers
2. Commit the current state as-is on a branch `wip/sept-2026-snapshot` and push it. That way nothing else can be lost.
3. If an August version turns up, commit it on `wip/aug-2026-working` too, so the two can be diffed.
4. Add a `.gitignore` covering `.DS_Store` and local scripts, and remove the `.DS_Store` files from the repo.
5. Clone the repo into a dedicated dev site (e.g. `neuron-dev.test`) with seeded content: a People list, Content blocks, containers and an Express object. The EPRA copy then just consumes released versions.
6. Add `CLAUDE.md` (see the draft) and turn on branch protection on `main` (changes only through PRs).

Exit: all code is in git, there's a dev site, and `main` is protected.

### Phase 1: Behaviour spec and test harness (M)
1. Write `documentation/BEHAVIOUR_SPEC.md`: what the assistant must do, must ask about, and must refuse, per tier.
2. Write about 15 **golden scenarios** as fixtures plus expected outcomes, for example:
   - "What page am I on?" and "How many content blocks are on this page?"
   - Create a People list from a pasted HTML chunk
   - Move content from a Content block into a different block type, keeping the text
   - Change all h3 headings to h2 on this page without altering the body text
   - Create a child page under /about with page type X
   - A user without permission asks to delete a page → refused, with a clear reason
   - Follow-up questions ("and are there others?") resolve from prior context
3. Build two test layers:
   - **PHPUnit** for tool contracts (deterministic, runs on every commit)
   - **Scenario evals** that run the real agent against the dev site and check the end state, run before each merge or release
4. A runner command: `concrete/bin/concrete neuron:eval`.

Exit: scenarios run automatically, and we know which ones pass today.

**Quick win in parallel (S): EPRA app kit.** A spec document and starter template EPRA staff give to their own Claude, so new data apps come out with the right structure. It covers the approved chart library, theme tokens, the API proxy pattern and file layout. This doesn't depend on the package and becomes the seed of the Tier 3 app spec.

### Phase 2: Core refactor (L)
1. Add the **ContextBuilder** class: pull the context code out of the agent, make it richer (area and block tree, permissions, tier), and cover it with unit tests.
2. Add the **capability registry and intent router**: group the tools, tag them by tier and permission, and send only the relevant groups per request.
3. **Split `PagesToolkit.php`** into Pages, Content (page blocks and areas) and Batch Edit. Remove `src/Toolkits/` and the root scripts.
4. **Move the EPRA tools out** into a `katalysis_epra_tools` package (or the EPRA theme package) that registers through `ExternalToolkitRegistry`.
5. **Cut the prompt down** to identity, tier and how to use context. Move each rule it holds into a tool check or a scenario.
6. **Provider adapter**: pick the provider and model from settings, update the default model choices, and support Anthropic properly.
7. **Housekeeping**:
   - store chats through the Doctrine entity and remove the per-request `CREATE TABLE`
   - use framework JSON responses in the controllers
   - add a tool-loop guard (stop repeated calls with the same arguments)

Exit: all Phase 1 scenarios pass, the tool count per request is at most about 25, and the prompt is under about 60 lines.

### Phase 3: Editor assistant v1, Tier 1 (M)
1. Change sets: every write is staged, the panel shows a diff or summary, and the user confirms or rejects.
2. Polish the import flows: pasted HTML or a URL → structured blocks or entities (People, lists, resources).
3. Move and convert content between block types, using per-block-type field mappers.
4. Undo the last change using page versions.
5. Release to EPRA editors as the first live users, and log failures as new scenarios.

Exit: EPRA uses it for real tasks, and every reported failure has become a scenario.

### Phase 4: Twig/Tailwind base theme (L, a parallel track that could be Alex's)
1. A lightweight Twig/Tailwind v4 theme built with Vite, plus design tokens.
2. Override templates for the core blocks so they don't depend on Bootstrap/Bedrock.
3. A policy for editor content: semantic HTML styled with the typography plugin, and a controlled safelist rather than utility classes in content.
4. Document the theme's structure as the target the generator writes into.

### Phase 5: Configure, Tier 2 (M)
1. Express tools: create objects, attributes, associations, forms and entry list/detail blocks.
2. A single page with approved components (for EPRA: chart and data components plus the API proxy).
3. Scenarios: "set up a bookable rooms list" built entirely in Express.

### Phase 6: App spec and generator, Tier 3 (L+)
1. `app-spec.schema.json` covering entities, fields, relations, validation, list and search views, blocks, permissions, and data sources and API proxies.
2. A generator console command (`neuron:generate spec.json`) with templates for:
   - the package controller and Doctrine entities/migrations
   - dashboard CRUD and search pages
   - blocks and Twig/Tailwind views
3. Extension points: generated code goes in `src/Generated/`, custom code in `src/Custom/` and overrides, and regeneration never touches custom code.
4. Output goes to staging or a git branch, a person reviews it, and then it's installed.
5. An agent tool that writes and validates the spec with the user. It never writes PHP.
6. Pilots: an EPRA data app, plus a re-creation of one past Katalysis booking or admin system.

### Phase 7: Community release (M)
1. Separate the open-source core from the Katalysis-only parts (theme, generator templates, client tools).
2. Docs, a demo site, a video, and a case study (EPRA).
3. Talk to Portland Labs early, ideally before or during Phase 3.

---

## 6. Working practices

- **One task = one branch = one PR.** Tasks come from the phase lists above, ideally as GitHub issues.
- **Claude Code**: plan before editing, keep diffs small, never delete or rewrite files wholesale without tests passing, and run the PHPUnit tests (plus the scenarios for agent changes) before proposing a merge.
- **Review**: Glyn or Alex reviews each PR. The other person reviews anything touching permissions, the generator, or applying writes.
- **Releases**: bump `pkgVersion`, keep a changelog entry, and run the scenarios on the dev site before updating EPRA.

## 7. Decisions needed

1. Open-source licence and where the open core ends and paid/private begins.
2. The default model and provider for production, and who pays for API usage (the client's key or Katalysis's).
3. Whether EPRA's tools live in a separate package or the EPRA theme package.
4. Who owns which track (suggested: Glyn on agent/spec, Alex on the theme/generator templates, or the reverse).
5. When to approach Portland Labs.
