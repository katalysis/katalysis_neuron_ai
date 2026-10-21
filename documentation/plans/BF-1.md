# BF-1 plan: the current page resolves on the home page, dashboard pages and in edit mode

Status: **approved (Glyn, 2026-10-21) with option A; decisions in §9.** Significant task: it touches a tool (`get_current_page_context`), the chat endpoint's page context (part of the trust boundary: an ID from the browser), and the scenario runner. Branch `task/BF-1-page-context` from `development`, PR into `development`. After merge, a sync PR `development` → `integration/neuron4`, merged with a merge commit (Phase 2 sync rule).

## 1. Goal
The chat endpoint, the page tag and `get_current_page_context` find the page the user is on for the home page, dashboard pages and front-end pages in edit mode, using only a page the user can view.

## 2. The bug (investigated 2026-10-21, on `development`)
- Logs `#66117`, `#66138`: `Tool 'get_current_page_context' failed: Unable to determine the current page context.` Chats 1485 and 1498 (admin, location `/`): every call has that error result, and no user message has the page tag.
- Cause 1, the panel: `getCurrentPageId()` (`js/chat-panel.js:318`) reads only `<body>`'s class and a `data-page-id` attribute. This theme puts `ccm-page-id-N` on the `div.ccm-page` wrapper and renders a bare `<body>`, so the ID is always null. Concrete prints `CCM_CID = N;` on the page; the panel doesn't read it.
- Cause 2, the server: `extractFrontendPageContext()` (`controllers/chat.php:231`) and the tool (`PagesToolkit::resolveCurrentContextPage()`, line 2909) use `Page::getByPath()`, which fails for `/` (the home page's path is null) and for `/index.php/dashboard/...`. Then the tool falls back to `Page::getCurrentPage()`, which is null in the chat route, and throws.
- Code from `c06959c` (28 July 2026, before Phase 1). Identical on `integration/neuron4`. P2.3 doesn't touch it.

## 3. Changes

### 3.1 One resolver, server side
New `src/PageContextResolver.php` (`Katalysis\NeuronAi\PageContextResolver`), used by the chat endpoint and by the tool:
- `fromBrowser(array $payload, ?string $referrerPath, ?callable $logger = null): array` returns the context the endpoint stores (`id`, `path`, `url`, `title`), the same shape as today.
  1. **ID candidates, in order:** the panel's `id` (from `CCM_CID`, 3.2); then a `cID` query parameter in the panel's `url` (edit-mode and `index.php?cID=` URLs).
  2. **Path:** the panel's `path`, else the path of its `url`, else the referrer path (as today). Normalised: a **leading** `/index.php` segment is stripped (`/index.php/dashboard/system` → `/dashboard/system`; `/index.php` alone → `/`; `/foo/index.php` is left alone). `/` resolves to **the current site's home page ID** (`$app->make('site')->getSite()->getSiteHomePageID()`), not through a path lookup. Any other path is looked up with `Page::getByPath()`.
  3. **The ID is untrusted input.** Each candidate page, by ID or by path, must exist and pass `Checker::canViewPage()` for the current user. A page that fails is treated as unresolved and logged as a warning ("page context: page N not viewable by user U, ignored"); the next candidate is tried. The ID wins over the path when both resolve: it is the page the browser rendered.
  4. Nothing resolves: `id` and `path` are null. The tag isn't added and the tool reports "unable to determine" (today's behaviour). The raw `title` and `url` are kept, as today, for the instructions' fallback lines.
- `pageForPath(string $path): ?Page` is the normalised path lookup from step 2. The tool's path fallback uses it.
- The controller's `extractFrontendPageContext()`, `getReferrerPath()` and `normalizePagePath()` move into the resolver. The controller calls `fromBrowser()`; nothing else in `controllers/chat.php` changes.

### 3.2 The panel (`js/chat-panel.js`, `getCurrentPageId()`)
The ID comes from `window.CCM_CID`. If that's missing, it comes from the `ccm-page-id-N` class on `.ccm-page`, then from `<body>` (today's check). If `CCM_CID` and the wrapper class disagree, `CCM_CID` wins. Nothing else in the panel changes.

### 3.3 The tool (`PagesToolkit::resolveCurrentContextPage()`)
- The path fallback uses `PageContextResolver::pageForPath()`.
- The ID from the session is already checked by the endpoint, and the tool keeps its own `canViewPage()` check.
- No change to its name, description, inputs or result shape.
- `PageContextTag` and `ConcreteCmsAgent` are unchanged: they get an ID the endpoint has checked.

### 3.4 Scenario runner and a new scenario
- `ScenarioRunner::setPageContext()` resolves `current_page` with `PageContextResolver::pageForPath()`, so a scenario can start on `/`.
- New check `succeeded: [tool, ...]` (README "tools" table): each named tool was called and returned a successful result in that turn (`ToolKind::succeeded`). There is no check today that a read tool succeeded.
- New `tests/Scenarios/22-home-page-context.yaml`:
  - user `neuron_editor`, `current_page: /`, says "What page am I on?";
  - checks `succeeded: [get_current_page_context]` (primary, a tool check), `no_write_tool_succeeded`, `notice_absent`, `nothing_changed`;
  - secondary reply check: `Home`.
- Run after approval: `--scenario=22-home-page-context --repeat=3` (about $0.17).

**Limit, a finding for P3.1 (noted in the PR):** the scenario runner never goes through the chat endpoint or the panel. It injects a server-resolved context. So no scenario covers the browser → endpoint path, which is where this bug was. Scenario 22 covers the home-page lookup, not the panel or endpoint. Those are covered by PHPUnit (3.5) and Glyn's browser checks (5).

### 3.5 Tests (red first)
`tests/Integration/PageContextResolverTest.php`, against the dev site, acting as `neuron_editor` and as the superuser:

| Case | Expected |
|---|---|
| Home: `{path: "/"}`, no ID | the site's home page ID |
| `{path: "/index.php/dashboard/system"}` as superuser | `/dashboard/system`'s ID |
| Same path as `neuron_editor` (can't view the dashboard) | unresolved, a warning logged |
| `{id: <headings>}`, no path | that page |
| `{id: <a page neuron_editor can't view>, path: "/neuron-test/headings"}` | the ID is ignored and logged; resolves `/neuron-test/headings` by path |
| An ID the user can't view, and no path | unresolved, logged |
| Edit mode: `{id: <headings>, path: "/index.php", url: ".../index.php?cID=<headings>&ctask=check-out-first"}`, and the same without `id` (cID from the URL) | headings |
| `/foo/index.php` | `/index.php` is not stripped |
| A nonexistent ID | unresolved, no exception |

The page `neuron_editor` can't view is a throwaway page under `/neuron-test`, with view permission removed for its group, using `AreaGrantFixture` or `TaskKeyFixture`-style restore. If neither fits, a small page-permission fixture that restores what it changed. It passes the P1.12 leak check.

Plus:
- `tests/Unit/Eval/SnapshotAndHelpersTest.php`: the `succeeded` check, a tool that succeeded and one that returned an error.
- The JS change has no automated test (the package has no JS tests). It's covered by the browser checks.

### 3.6 Dashboard and system pages: what the write tools do today (finding, **stop: decision needed**)
By reading the code: **no page-scoped write tool refuses a dashboard or system page.** They look pages up with `Page::getByID()` / `Page::getByPath()` and rely on Concrete permissions only:
- `update_page`
- `set_page_attribute_value` (two toolkits)
- `add_block_to_page_area`, `update_block_in_page_area`, `delete_block_from_page_area`, `move_block_between_areas`
- `batch_update_page_content`, `batch_update_content_headings`
- `create_page_draft`, `discard_draft_version`
- `create_page` (parent)

Nothing checks `isSystemPage()` or `/dashboard`. So a superuser asking on a dashboard page could get a draft of a dashboard single page edited (writes go to a draft and are never published, P1.8/P1.13). `neuron_editor` is stopped by permissions. Today that's unlikely only because the context never resolved there, and **this fix makes the dashboard page the current page**, so the risk goes up. Options:
- **A (recommended):** in BF-1, refuse writes to system pages (`Page::isSystemPage()`: dashboard, account and other single pages under system paths) in one shared check that every page-scoped write tool calls, with a red-first test for each. The read tools keep working.
- **B:** BF-1 resolves the context everywhere, but treats a system page as "read-only context", and a separate task adds the write refusal.
- **C:** BF-1 doesn't resolve context on dashboard pages at all (out of scope), until a decision.

Either way a test is added. With A, a test per write tool, red first. With B or C, a test that pins today's behaviour and is named for the follow-up, plus a resolver test.

## 4. Files
- **New:** `src/PageContextResolver.php`, `tests/Integration/PageContextResolverTest.php`, `tests/Scenarios/22-home-page-context.yaml`, `documentation/plans/BF-1.md`. With option A: a shared system-page check (e.g. `src/Tools/Utilities/SystemPageGuard.php`) and its tests.
- **Changed:**
  - `controllers/chat.php`: `send_message` calls the resolver, and the three private helpers are removed (about 90 lines move to the resolver; a planned move)
  - `js/chat-panel.js`
  - `src/Tools/PagesToolkit.php` (`resolveCurrentContextPage()` only; with A, also the write tools' check)
  - `tests/Eval/ScenarioRunner.php`, `tests/Eval/ToolChecks.php` and `tests/Eval/ScenarioLoader.php` (the new check and its validation), `tests/Unit/Eval/SnapshotAndHelpersTest.php`, `tests/Scenarios/README.md`
  - `documentation/DELIVERY_PLAN.md` (the BF-1 entry; tick), `documentation/PROGRESS.md`
  - With A: `PageAttributesToolkit.php`, `AttributesToolkit.php` and `VersionsToolkit.php`, for the check only.

## 5. Manual checks (Glyn, browser, after the PR is up)
As admin, on `development` with BF-1 checked out and the cache cleared. Send "What page am I on?" in a new chat on each page:

| Where | Expected reply | Also |
|---|---|---|
| Home page (`/`) | names **Home** and its path `/` (or "the home page") | no "issue retrieving the current page context"; the stored message has the tag `[Current page for this message: Home, ...]` |
| A dashboard page, e.g. Dashboard → System (`/index.php/dashboard/system`) | names **System & Settings** (or the dashboard page's own title) and `/dashboard/system` | with option A, "Change this page's title" is refused as a system page. (Whether dashboard pages print `CCM_CID` isn't checked; the path normalisation resolves them either way.) |
| `/neuron-test/headings` in edit mode | names **Headings**, `/neuron-test/headings` | same as outside edit mode |
| As `neuron_chat_editor` on the home page | names **Home** | |

I check the Concrete log for "Unable to determine the current page context" (none expected) and for the "not viewable" warning (none expected on these pages).

## 6. Out of scope
The page tag's text and the instructions' context lines (unchanged). The P2 integration branch, except the sync PR after merge. Any other tool. The panel's other behaviour.

## 7. Acceptance
- PHPUnit fast tier after each change; the full suite before the PR: everything passes on `development`.
- Scenario 22 ×3: passes, or partial only on the reply check.
- The manual checks in 5.
- The sync PR into `integration/neuron4` merges without conflicts; `integration/neuron4`'s known-failures list is unchanged (BF-1's new tests don't build v3 tools).

## 8. Risks and questions
1. **Option A, B or C (3.6).** Needed before implementing.
2. The `cID` query fallback: the edit-mode URL shape on Concrete 9.5.5 isn't confirmed from here (no logged-in browser). `CCM_CID` covers edit mode anyway; the query fallback is a second source, also permission-checked.
3. `isSystemPage()` (option A) also covers account pages and other single pages under system paths. If you want only `/dashboard`, say so.
4. DELIVERY_PLAN entry for the `PLAN:` commit, under §6 "Working-practice tasks" or a new "Bug fixes (BF)" list next to it (proposed):
   > - [ ] BF-1 **The current page resolves on the home page, dashboard pages and in edit mode** (significant; its own PR into `development`, then synced into `integration/neuron4`). Found after P2.2: `get_current_page_context` failed on those pages because the panel never sent a page ID and the server couldn't look up `/` or `/index.php/...`. The browser's ID is untrusted: used only for a page the user can view. Plan: [plans/BF-1.md](plans/BF-1.md).

## 9. Decisions (Glyn, 2026-10-21, on approving this plan)
1. **Option A, full scope:** one shared check refuses a system page (`Page::isSystemPage()`, everything Concrete marks as one) in every write tool that takes a page, with a clear refusal message the model can relay.
2. **Tests:** red first, one per tool, plus a registry-level test that every tool `ToolKind` classes as a write and that takes a page refuses a system page, so a future tool can't skip it.
3. **Stacks and global areas:** if Concrete marks their pages as system pages, they are now refused too. The PR says so, as a decision to revisit later.
4. **BEHAVIOUR_SPEC:** a new rule, B-WRT-5 "writes to system pages are refused", in the `PLAN:` commit, along with the DELIVERY_PLAN entry (wording as in 8.4).
5. **Scenario 22 and the `succeeded:` check are approved:** run ×3 after implementation. Then open the PR into `development` and stop. After Glyn merges it, open the sync PR `development` → `integration/neuron4`.
6. **For P2.3:** its v3 tool baseline is captured after BF-1 is synced, so the refusal isn't seen as a difference (in DELIVERY_PLAN's BF-1 entry).
