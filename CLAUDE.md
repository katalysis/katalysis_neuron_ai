# CLAUDE.md: Katalysis Neuron AI

A Concrete CMS 9.3+ package (PHP 8.1+) that adds an AI assistant to the CMS. It's built on Neuron AI (`neuron-core/neuron-ai`, bundled in `vendor/`). It's on 3.15.26 now, and an upgrade to 4.x is planned in Phase 2 of the delivery plan.

Dev site: `katalysis_pro_theme` (Herd, Concrete 9.5.5). Integration branch: `development`.

**Read first:** `documentation/DELIVERY_PLAN.md` covers the product tiers, guard rails, architecture and the current phase. `documentation/KATALYSIS_NEURON_AI_PLAN.md` holds the tool implementation policy.

## Workflow (required)
- Start every task with `/start-task <ID>` and end it with `/finish-task`. Work only on tasks listed in `documentation/DELIVERY_PLAN.md`.
- **Never** change `DELIVERY_PLAN.md`, `EXTENSIONS.md` or the decisions in `PROGRESS.md`. Propose changes in your progress entry instead. Glyn or Alex decide, with design review in Cowork.
- Stop and ask whenever any of these apply:
  - the task needs files outside the approved plan
  - a test or scenario that used to pass now fails
  - you're about to delete more than about 20 lines of working code
  - a design doc and the code disagree
- Report results honestly. "Not tested" is an acceptable answer; claiming something works when it wasn't checked is not.

## Layout
- `controller.php`: package controller (routes, chat panel injection, install/upgrade)
- `controllers/chat.php`: chat endpoints
- `src/ConcreteCmsAgent.php`: the agent (provider, instructions, tools, history)
- `src/Tools/*Toolkit.php`: native toolkits (extend `NeuronAI\Tools\Toolkits\AbstractToolkit`)
- `src/Tools/ExternalToolkitRegistry.php`: lets other packages register toolkits
- `src/Entity/`, `src/DatabaseChatHistory.php`: chat persistence
- `js/chat-panel.js`, `css/chat-panel.css`, `elements/chat_panel.php`: UI
- `src/Toolkits/` is legacy and duplicated. Don't edit it; it's due for removal in Phase 2.

## Rules
1. **Never delete or rewrite a file or a large block of code to "rebuild" it.** Make small, incremental edits. If a rewrite seems necessary, stop and propose a plan first.
2. **Work on a branch** and keep each change to a single task. Never commit to `main` directly.
3. **Plan before editing** anything in `src/ConcreteCmsAgent.php`, `controllers/chat.php` or any toolkit's write tools.
4. **Every tool:**
   - has a clear name, description and typed properties
   - returns the `{ok: true, data}` / `{ok: false, error}` envelope through `guarded()`
   - checks Concrete permissions for the active user
   - has a PHPUnit test
5. **Put behavioural rules in code, not in `instructions()`.** If the model keeps getting something wrong, fix the tool or add a check and a scenario. Don't add another prompt rule.
6. **No client-specific code in this package** (e.g. EPRA block handles). It belongs in a separate package that registers through `ExternalToolkitRegistry`.
7. **Writes must be undoable:** edit a new page version and never publish silently. Keep the text-integrity checks (checksums, `formatting_only` / `scoped_text`) when editing content tools.
8. **Don't edit `vendor/`.** Dependencies are bundled. Changes to them go through composer and a deliberate dependency update.
   - A dependency upgrade gets its own PR.
   - It must not be combined with refactoring.
   - It must keep scenario results the same.
9. **Don't touch secrets:** API keys live in Concrete config (`katalysis.ai.*`) and must never be logged or returned to the frontend.

## Testing
- PHPUnit tool tests: `vendor/bin/phpunit` (set up in Phase 1).
- Behaviour scenarios: `concrete/bin/concrete neuron:eval` against the dev site (Phase 1). All of them must pass before merging any change to the agent, context or tools.
- After changing an entity: `concrete/bin/concrete c5:package:update katalysis_neuron_ai` and clear the cache.

## Concrete conventions
- Use core services and commands (e.g. the page/block command bus, `Page::getByID`, the permission `Checker`) and follow the same paths as the `/ccm/api/v1` endpoints, per the tool policy.
- Namespace: `Katalysis\NeuronAi\...` (autoloaded from `src/`). The package controller is in `Concrete\Package\KatalysisNeuronAi`.
