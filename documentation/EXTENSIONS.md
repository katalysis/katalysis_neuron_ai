# Neuron AI Extension Model

Status: design draft, 19 October 2026. This feeds Phases 2 and 3 of `DELIVERY_PLAN.md`.

## The problem

The core package should handle anything in Concrete core (pages, core blocks, files, users, attributes, Express) without configuration. Third-party blocks and packages, including our own (`katalysis_pro`, the EPRA theme), need a way to tell the assistant:

- what their blocks *mean*
- how to read and write them safely
- what extra operations they offer

Today, that knowledge has leaked into the core as guesswork. `normalizeCustomBlockPayload()` tries nine alias names for repeatable rows. `inferRepeatableLinkContract()` guesses link types from field prefixes. `loadEpraResourceEntries()` queries `btEpraResourcesListEntries` by name. The system prompt carries EPRA rules. All of this is fragile, it can't be tested in general, and none of it would work on someone else's site.

## Principles

1. **Describe, don't code, wherever possible.** Most blocks only need a description of their fields. Only real operations need code.
2. **Extensions never touch the AI library directly.** They use *our* contract (`Katalysis\NeuronAi\Extension\*`), and we adapt that to whichever Neuron AI version is bundled. Swapping Neuron 3 for Neuron 4, or a later framework, must not break `katalysis_pro` or a client package.
3. **The core enforces safety for everyone.** Extension tools are wrapped by the core: permission check, tier gating, approval for writes, the response envelope, logging. An extension can't opt out of these.
4. **No hard dependency.** A package must load fine when Neuron AI isn't installed. No `class_exists` guards around class declarations.
5. **Discovery is cheap.** The model sees a compact list of what's available and fetches detail only when it needs it.

## Three levels of integration

### Level 0: Automatic (any block, no work by the package author)
The core introspects every installed block type using information Concrete already has:
- Name, description and handle (`BlockType`)
- Table columns from `$btTable` and the package's `db.xml` or Doctrine schema
- **Reference columns from the export metadata** already on block controllers:
  - `$btExportFileColumns`
  - `$btExportPageColumns`
  - `$btExportPageTypeColumns`
  - `$btExportPageFeedColumns`
  - `$btExportTables` (child tables such as `...Entries`, used for repeatable rows)
  
  These tell us which columns are file IDs, page IDs and so on without guessing.
- Read: the block's record plus child tables, with references resolved (file → name/URL, page → path).
- Write: through the block controller's own `save($args)` via the core update command, the same path the edit form uses.

This turns the current guesswork into a **generic, testable block adapter**. It works reasonably well for blocks built with Block Designer-style tools, which follow predictable conventions (a `btX` table, a `btXEntries` child table, `link_*` field groups). It still can't know *meaning*. Level 1 adds that.

### Level 1: Declarative descriptors (a JSON file, no PHP)
A package ships `neuron/blocks/<block_handle>.json`. The core finds these files in installed packages automatically. No code runs and no Neuron classes are needed.

```json
{
  "schema": "neuron-block/1",
  "block": "epra_resources_list",
  "summary": "A list of downloadable resources (documents, reports) with title, summary, image and a link.",
  "use_when": "Showing a set of documents or publications for download. Preferred over Multi Link for documents.",
  "fields": {
    "title":   { "type": "text", "column": "title", "description": "Optional heading above the list" },
    "entries": {
      "type": "repeatable",
      "table": "btEpraResourcesListEntries",
      "order_column": "sortOrder",
      "save_key": "slide",
      "fields": {
        "Title":  { "type": "text", "required": true },
        "precis": { "type": "richtext", "description": "Short summary, 1–2 sentences" },
        "Image":  { "type": "file", "accept": "image" },
        "link":   { "type": "link", "variants": ["url", "page", "file", "relative_url", "document_api"], "prefix": "link_" }
      }
    }
  },
  "examples": [
    { "description": "Two PDF reports", "data": { "entries": [ { "Title": "Annual Report 2025", "link": { "file": 1234 } } ] } }
  ]
}
```

The core uses the descriptor to:
- **show the model** a clean schema (`describe_block_type`) instead of raw columns
- **validate** payloads *before* saving, and reject bad ones with a clear error the model can fix
- **map** clean field names (e.g. `entries`, `link.file`) to what `save()` expects (`slide`, `link_File`). This replaces all the alias guessing.
- **pick the right block** when the user doesn't name one (`summary`, `use_when`)

Field types (v1): `text`, `richtext`, `number`, `boolean`, `select`, `date`, `file`, `image`, `page`, `link`, `user`, `express_entry`, `topic`, `repeatable`, `json`. Each type has a core validator, a normaliser and a resolver for reading.

**Content transforms** can be declared too, so moving content between block types needs no code:

```json
{
  "schema": "neuron-transform/1",
  "from": "multi_link",
  "to": "epra_resources_list",
  "description": "Convert file links in a Multi Link block into EPRA resources",
  "map": {
    "entries[]": { "from": "links[]", "where": { "link.type": "file" },
                   "fields": { "Title": "links[].title", "link": "links[].link" } }
  }
}
```

The core gets one generic tool, `convert_blocks(pageID, from, to, scope)`, which uses any registered transform. That covers today's `sync_multi_links_to_epra_resources_list` and any future equivalent.

### Level 2: Capability providers (PHP, for real operations)
When a package needs more than descriptors (custom entities, business operations, extra context), it registers a **capability provider**.

```php
// In the package controller's on_start(). Only runs if Neuron AI is installed and dispatches the event.
Events::addListener('on_katalysis_neuron_ai_register', function ($event) {
    $event->getRegistry()->add(new \Concrete\Package\KatalysisPro\Ai\NeuronProvider());
});
```

```php
use Katalysis\NeuronAi\Extension\CapabilityProvider;
use Katalysis\NeuronAi\Extension\ToolDefinition;
use Katalysis\NeuronAi\Extension\Tier;

class NeuronProvider implements CapabilityProvider
{
    public function id(): string        { return 'katalysis_pro'; }
    public function apiVersion(): string { return '1.0'; }   // our extension API, not the Neuron library version

    public function groups(): array     // used by the intent router
    {
        return ['people' => 'Manage staff/people profiles', 'places' => 'Office locations', 'reviews' => 'Client reviews'];
    }

    public function tools(): array
    {
        return [
            ToolDefinition::make('add_person', 'Create or update a person profile (safe upsert by name/email)')
                ->group('people')
                ->tier(Tier::CONTENT)
                ->writes()                                   // → approval required, write permission checked
                ->permission(fn ($ctx) => $ctx->user()->canAccessDashboardPage('/dashboard/katalysis_pro/people'))
                ->input('name', 'string', required: true)
                ->input('email', 'string')
                ->input('imagePath', 'string', 'Concrete file path; resolved to a file ID')
                ->handler(fn (array $in, $ctx) => (new PeopleService())->upsert($in)),
            // …
        ];
    }

    public function contextProviders(): array   // extra lines in the fixed context, when relevant
    {
        return [ fn ($ctx) => $ctx->groupActive('people') ? ['People: ' . PeopleList::count() . ' records'] : [] ];
    }

    public function guidelines(): array          // short instructions, loaded only when the group is active
    {
        return ['people' => 'Match existing people by email before creating new ones.'];
    }
}
```

`ToolDefinition` is our own fluent builder. In practice it's the P2.1 `CallableTool` adapter, promoted to a public contract. The core turns it into a Neuron `Tool`, wrapped with:
1. Tier gate: hidden unless the user's tier allows it.
2. Permission check, using `permission()`. If none is declared, write tools default to admin-only.
3. Approval when `writes()` is set, using Neuron 4's approval flow.
4. Input validation and casting.
5. The `{ok, data | error}` envelope, error capture and logging.

## How the model sees it
- **Always in context** (compact):
  - the current page and areas
  - the active capability groups
  - a one-line catalogue of the block types *used on this page or site*, flagged where a descriptor exists
- **On demand:**
  - `describe_block_type(handle)` returns the descriptor schema, or the Level 0 introspection
  - `list_capabilities()` returns the groups and providers
- **Routing:** the intent router picks the relevant groups (core plus provider groups) for each request, so only those tools are sent.

## Admin and safety
- Dashboard → System → AI → Extensions lists:
  - the providers found, with their API version and status
  - descriptors found, with validation errors
  - an enable/disable switch per provider and per tool
- Descriptors are checked against a JSON Schema when packages are installed or updated, and the results show on that page.
- Version handling: a provider declares the `apiVersion` it targets. The core supports the current and previous major version, and warns on mismatch rather than crashing.
- Dependency safety: listeners only run when Neuron dispatches the event, and descriptors are plain JSON, so a package never fails to load without Neuron.
- A toolkit that fails to build is skipped, not fatal (since P2.2, decided after P2.1): its tools are built once per agent inside a try; one that throws is left out, logged with secrets redacted, and listed on the settings page (later the Extensions page), so the rest of the assistant keeps working and the loss is visible.

## Applied to what we have

| Today | Becomes |
|---|---|
| `normalizeCustomBlockPayload`, alias lists, `inferRepeatableLinkContract` in `PagesToolkit` | The Level 0 generic block adapter, plus a `link` field type in the descriptor engine |
| `loadEpraResourceEntries` (hard-coded table) | Level 0 child-table read, or the `entries` field in the EPRA descriptor |
| `sync_multi_links_to_epra_resources_list` | A transform JSON in the EPRA theme package, plus the core `convert_blocks` tool |
| `get_doc_download_files_on_page` | A `doc_download` descriptor in the EPRA package, plus the core `list_page_blocks_by_type` |
| EPRA rules in `instructions()` | `use_when` in the descriptors, plus EPRA guidelines |
| `KatalysisProEntitiesToolkit` (extends Neuron classes, guarded by `class_exists`) | A `katalysis_pro` `CapabilityProvider` registered via the event. No Neuron classes. |
| `PeopleToolkit` in core (duplicates katalysis_pro's) | Removed from core. People belongs to `katalysis_pro`. |
| `ExternalToolkitRegistry` (loaded since P1.6; `registerToolDefinitions()` since P2.8) | Replaced by the event and `CapabilityRegistry` in P3.10. Keep `registerToolkitFactory()` and `registerToolDefinitions()` as a shim for one release. |

## Payoff for later phases
- **Tier 3 generator:** every package the generator produces also produces its own descriptors and provider. Everything it builds can be edited by the assistant from day one.
- **Community:** third-party developers get a simple path. A JSON file makes their blocks AI-friendly, and a provider class adds real operations. Both work without our code being installed.
- **Block Designer-style blocks:** a CLI command (`neuron:describe <block_handle>`) drafts a descriptor from Level 0 introspection for a developer to refine.
