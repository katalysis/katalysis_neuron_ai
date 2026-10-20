<?php

namespace Katalysis\NeuronAi;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\AssistantMessage;
use Katalysis\NeuronAi\DatabaseChatHistory;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\UserAttributesToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\ToolNameResolver;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Page\Page;
use Concrete\Core\Area\Area;
use Concrete\Core\Attribute\Key\CollectionKey;

class ConcreteCmsAgent extends Agent
{
    private ?int $chatId = null;
    private ?DatabaseChatHistory $chatHistoryInstance = null;
    private ?array $runtimePageContext = null;
    
    public function __construct(?int $chatId = null)
    {
        $this->chatId = $chatId;
        
        parent::__construct();
        
        // Increase tool max runs to prevent premature failures
        // Default is 10, but complex queries may require more iterations
        $this->toolMaxRuns(20);
        
        // Set error handler for better error messages to the LLM
        $this->toolErrorHandler(function(\Throwable $e, $tool) {
            $safeMessage = SecretRedactor::redact($e->getMessage());
            $errorMsg = "Tool '{$tool->getName()}' failed: " . $safeMessage;
            \Concrete\Core\Support\Facade\Log::error('Neuron AI Tool Error: ' . $errorMsg);
            
            // Return a clear error message that the LLM can understand
            return "Error executing tool: " . $safeMessage . ". Please try a different approach or ask for clarification.";
        });
    }

    protected function tools(): array
    {
        $core = [
            new PagesToolkit(),
            new BlocksToolkit(),
            new FilesToolkit(),
            new UsersToolkit(),
            new PageAttributesToolkit(),
            new UserAttributesToolkit(),
            new FileAttributesToolkit(),
            new AttributeSetsToolkit(),
            new TopicsToolkit(),
            new PeopleToolkit(),
            new VersionsToolkit(),
        ];

        // Toolkits other packages registered (e.g. katalysis_pro's people, places and reviews).
        // On a name clash the external tool wins; then the disabled-tools list is applied to everything.
        $tools = ToolNameResolver::resolve($core, ExternalToolkitRegistry::getExternalToolkits());

        return DisabledTools::apply($tools);
    }
    
    /**
     * Set the chat ID for the current conversation
     * This allows the agent to persist chat history to the database
     */
    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;
        
        // IMPORTANT: Always call chatHistory() to ensure instance exists
        // This matches the pattern from katalysis_pro_ai RagAgent
        $chatHistory = $this->chatHistory();
        
        if ($chatHistory instanceof DatabaseChatHistory && $chatId) {
            $chatHistory->setChatId($chatId);
        }
        
        return $this;
    }

    /**
     * Set page context collected from the frontend request.
     *
     * The page context is part of instructions(). Neuron builds the instructions, the start event that carries them
     * and the tool bootstrap once, on the first chat(), and keeps them for the life of the agent. So a new context
     * only reaches the model if those caches are dropped here, and the next chat() rebuilds them.
     */
    public function setRuntimePageContext(?array $context): self
    {
        $this->runtimePageContext = is_array($context) ? $context : null;

        // Both are typed properties, so unset() puts them back to "not built yet".
        unset($this->instructions, $this->startEvent);
        $this->toolsBootstrapCache = [];

        return $this;
    }
    
    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: Config::get('katalysis.ai.open_ai_key'),
            model: Config::get('katalysis.ai.open_ai_model', 'gpt-4o')
        );
    }

    /**
     * Use database-backed chat history for persistent storage
     * Uses singleton pattern to ensure chat ID persistence
     * 
     * @return \NeuronAI\Chat\History\AbstractChatHistory
     */
    protected function chatHistory(): \NeuronAI\Chat\History\AbstractChatHistory
    {
        // Use singleton pattern to ensure chat ID persistence
        if ($this->chatHistoryInstance === null) {
            $this->chatHistoryInstance = new DatabaseChatHistory(50000);
            
            // Set chat ID directly on the instance if available (don't call setChatId to avoid recursion)
            if ($this->chatId) {
                $this->chatHistoryInstance->setChatId($this->chatId);
            }
        }
        
        return $this->chatHistoryInstance;
    }

    protected function instructions(): string
    {
        $context = $this->getCurrentContext();
        return <<<INST
You are an AI assistant for Concrete CMS with comprehensive site management capabilities.

CURRENT CONTEXT:
{$context}

CAPABILITIES:
You have access to 71 tools across 11 categories.

**Blocks (5 tools):**
- Search for all instances of a specific block type across the site (finds blocks in regular areas AND inside containers)
- Get detailed field data and configuration from a specific block (raw database fields)
- Get block controller runtime data with resolved relationships (users, files, pages) - use for understanding actual content
- Count block usage by type (quick stats for all block types or specific ones)
- List all blocks nested inside a specific container block (by container block ID)

**Content Writes:**
- Use `add_block_to_page_area`, `update_block_in_page_area`, `delete_block_from_page_area`, and `batch_update_page_content` for content changes whenever the page/area/block context is available
- Use `create_page`, `update_page`, `move_page`, and `delete_page` for page structure changes whenever the page context is available
- Do not tell the user to make manual edits when one of these tools can perform the change directly
- Only fall back to step-by-step guidance when the required write tool is genuinely unavailable or the target context is incomplete

GUIDELINES:
- When the user says "this page" or asks for counts/edits on the current page, start by calling `get_current_page_context` and use that page ID/path as the primary scope
- When the user asks "what page are we on?", says they moved/navigated pages, or asks for content "on this page", call `get_current_page_context` first in that same turn and do not rely on prior-turn page context
- For questions like "how many content blocks are on this page", prefer `list_page_blocks_by_type(pageID, blockTypeHandle)` over site-wide searches
- Never state block counts or "none found" conclusions unless a tool call result explicitly supports it; if lookup fails, report the failure and retry with an alternate tool/query path
- For batch edits across many blocks, continue processing remaining blocks if one block fails; report failures separately and do not stop the whole run
- For HTML/text edits inside Content blocks, preserve all existing non-heading content exactly (paragraphs, links, lists, inline formatting)
- When asked to change only heading level/tag, perform a minimal edit: only replace the heading element/tag, keep heading text unchanged, keep all following body text unchanged
- Before saving a Content block, verify the updated HTML still contains the original non-heading body text; if body text is missing/truncated, do not save that version
- For heading-only requests, do not rewrite or summarize full HTML content
- When searching for specific block types across the site, use search_blocks_by_type instead of listing all pages and checking each one
- When inspecting block configuration/fields, use get_block_data to extract specific field values instead of manual parsing
- When working with third-party/custom blocks, use get_block_controller_data to see resolved content instead of raw IDs
- When a block exposes an API resource or controller-level enriched data, prefer that data over prompt-specific mapping rules
- If content creation is requested and the needed page/area/block identifiers are known, proceed with the write operation and report progress instead of asking the user to perform the edit themselves
- For formatting/style-only edits across blocks, prefer `batch_update_page_content` with `changeMode="formatting_only"` so text checks are enforced and checksums are reported
- For targeted text updates, use `batch_update_page_content` with `changeMode="scoped_text"` and explicit `textFields` per block
- For multi-block heading-level changes in Content blocks, prefer `batch_update_content_headings` to find and update all matching headings on-page with built-in text-integrity checks
- Use `batch_update_content_headings(matchMode="bold_paragraph", areaHandleContains=" : Column 1")` when asked to update all bold paragraph headings in a specific column across many blocks
- For Multi Link to EPRA Resources List migrations on a page, prefer `sync_multi_links_to_epra_resources_list` so updates happen per-area on existing target blocks with file-link verification
- For requests like "get title and file IDs from Doc Download blocks on this page", use `get_doc_download_files_on_page` first; it handles display-name variants and nested/container areas
- Never use `contentPreview` (from page block listings) as source HTML for writes; it is discovery-only and may be truncated
- Before updating Content blocks, fetch full raw content field values (not previews) and apply minimal in-place transforms to those full values
- If any candidate source text includes ellipsis (`...`) or appears truncated, treat it as invalid for write-back and re-fetch full content
- For block usage statistics, use count_blocks_by_type for quick overview before diving into specific searches
- When asked for pages "below", "under", or "within" a path, use get_page_children with parentPath for direct children, or list_pages with includeDescendants=true for all pages in that tree
- By default, list_pages and get_page_children show all pages including those with "exclude_nav" set; use onlyVisible=true only when the user explicitly wants navigation-visible pages
- If a custom block handle is not found, check both the installed handle and the display name before concluding the block is unavailable
- Provide specific, actionable information and include relevant IDs, paths, and counts in responses

RESPONSE STYLE:
- Concise and helpful
INST;

    }

    private function getCurrentContext(): string
    {
        $context = [];

        // Under the CLI there is no current page, so build page context only when the caller passed one
        // (the scenario runner does). Outside the CLI this is always true, as before.
        if (php_sapi_name() !== 'cli' || $this->runtimePageContext !== null) {
            try {
                $contextPage = $this->resolveContextPage();
                if ($contextPage && !$contextPage->isError()) {
                    $context = array_merge($context, $this->buildPageContextLines($contextPage));
                } elseif ($this->runtimePageContext) {
                    $context = array_merge($context, $this->buildRuntimeFallbackLines());
                }
            } catch (\Exception $e) {
                // Ignore - might be CLI or bootstrap not complete
            }
        }

        try {
            $u = new \Concrete\Core\User\User();
            if ($u->isRegistered()) {
                $context[] = 'User: ' . $u->getUserName();
                $context[] = 'User ID: ' . $u->getUserID();
            }
        } catch (\Exception $e) {
            // Ignore - might be CLI or bootstrap not complete
        }

        return implode("\n", $context);
    }

    private function resolveContextPage(): ?Page
    {
        $runtimePath = $this->runtimePageContext['path'] ?? null;
        $runtimeId = $this->runtimePageContext['id'] ?? null;
        $hasRuntimeHint = is_numeric($runtimeId) || (is_string($runtimePath) && $runtimePath !== '');

        if (is_numeric($runtimeId)) {
            $page = Page::getByID((int) $runtimeId, 'ACTIVE');
            if ($page && !$page->isError()) {
                return $page;
            }
        }

        if (is_string($runtimePath) && $runtimePath !== '') {
            $page = Page::getByPath($runtimePath, 'ACTIVE');
            if ($page && !$page->isError()) {
                return $page;
            }
        }

        if ($hasRuntimeHint) {
            return null;
        }

        return Page::getCurrentPage();
    }

    private function buildPageContextLines(Page $page): array
    {
        $lines = [
            'Current Page ID: ' . $page->getCollectionID(),
            'Current Page Name: ' . $page->getCollectionName(),
            'Current Page Path: ' . $page->getCollectionPath(),
            'Current Page Type: ' . ($page->getPageTypeHandle() ?: 'N/A'),
            'Current Page Template: ' . ($page->getPageTemplateHandle() ?: 'N/A'),
        ];

        $description = trim((string) $page->getCollectionDescription());
        if ($description !== '') {
            $lines[] = 'Current Page Description: ' . $description;
        }

        $attributeLines = $this->getPageAttributeLines($page);
        if (!empty($attributeLines)) {
            $lines[] = 'Current Page Attributes:';
            foreach ($attributeLines as $attributeLine) {
                $lines[] = '- ' . $attributeLine;
            }
        }

        $contentLines = $this->getPageContentLines($page);
        if (!empty($contentLines)) {
            $lines[] = 'Current Page Content Snapshot:';
            foreach ($contentLines as $contentLine) {
                $lines[] = '- ' . $contentLine;
            }
        }

        return $lines;
    }

    private function buildRuntimeFallbackLines(): array
    {
        if (!$this->runtimePageContext) {
            return [];
        }

        $lines = [];

        $runtimeId = $this->runtimePageContext['id'] ?? null;
        if (is_numeric($runtimeId)) {
            $lines[] = 'Current Page ID: ' . (int) $runtimeId;
        }

        $runtimeTitle = trim((string) ($this->runtimePageContext['title'] ?? ''));
        if ($runtimeTitle !== '') {
            $lines[] = 'Current Page Name: ' . $runtimeTitle;
        }

        $runtimePath = trim((string) ($this->runtimePageContext['path'] ?? ''));
        if ($runtimePath !== '') {
            $lines[] = 'Current Page Path: ' . $runtimePath;
        }

        $runtimeUrl = trim((string) ($this->runtimePageContext['url'] ?? ''));
        if ($runtimeUrl !== '') {
            $lines[] = 'Current Page URL: ' . $runtimeUrl;
        }

        return $lines;
    }

    private function getPageAttributeLines(Page $page): array
    {
        $lines = [];

        try {
            $keys = CollectionKey::getList();
            foreach ($keys as $key) {
                $handle = (string) $key->getAttributeKeyHandle();
                if ($handle === '') {
                    continue;
                }

                $rawValue = $page->getAttribute($handle);
                $normalized = $this->normalizeContextValue($rawValue);
                if ($normalized === null || $normalized === '') {
                    continue;
                }

                $lines[] = $handle . ': ' . $normalized;
                if (count($lines) >= 20) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Ignore failures and continue without attribute context.
        }

        return $lines;
    }

    private function getPageContentLines(Page $page): array
    {
        $lines = [];

        try {
            $areaHandles = Area::getHandleList();
            foreach ($areaHandles as $areaHandle) {
                $blocks = $page->getBlocks($areaHandle);
                if (!is_array($blocks) || empty($blocks)) {
                    continue;
                }

                $lines[] = sprintf('Area "%s" has %d block(s)', $areaHandle, count($blocks));

                $contentPreviewCount = 0;
                foreach ($blocks as $block) {
                    if (!method_exists($block, 'getBlockTypeHandle')) {
                        continue;
                    }

                    $blockType = (string) $block->getBlockTypeHandle();
                    if ($blockType !== 'content') {
                        continue;
                    }

                    $preview = $this->extractContentBlockPreview($block);
                    if ($preview === null || $preview === '') {
                        continue;
                    }

                    $lines[] = sprintf('Area "%s" content: %s', $areaHandle, $preview);
                    $contentPreviewCount++;
                    if ($contentPreviewCount >= 2) {
                        break;
                    }
                }

                if (count($lines) >= 12) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Ignore failures and continue without content snapshot.
        }

        return $lines;
    }

    private function extractContentBlockPreview($block): ?string
    {
        try {
            if (!method_exists($block, 'getInstance')) {
                return null;
            }

            $instance = $block->getInstance();
            if (!$instance || !method_exists($instance, 'getController')) {
                return null;
            }

            $controller = $instance->getController();
            if (!$controller || !method_exists($controller, 'getContentEditMode')) {
                return null;
            }

            $html = (string) $controller->getContentEditMode();
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
            if ($text === '') {
                return null;
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($text) > 180) {
                    $text = mb_substr($text, 0, 180) . '...';
                }
            } elseif (strlen($text) > 180) {
                $text = substr($text, 0, 180) . '...';
            }

            return $text;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeContextValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $stringValue = trim((string) $value);
            return $stringValue !== '' ? $stringValue : null;
        }

        return null;
    }
    
    /**
     * The user's message with the page tag in front (see PageContextTag), from the page context set with
     * setRuntimePageContext(). Unchanged when there is no page context.
     */
    public function tagMessage(string $message): string
    {
        return PageContextTag::apply($message, $this->runtimePageContext);
    }

    /**
     * What the chat endpoint and the scenario runner call for a user's message: handle() with the page tag added.
     * Both go through here, so the model gets the same message in the scenarios as in the panel.
     */
    public function handleUserMessage(string $message): string
    {
        return $this->handle($this->tagMessage($message));
    }

    public function handle(string $message): string
    {
        try {
            // Send message and get response using Neuron AI's built-in chat method
            $userMessage = new UserMessage($message);
            $response = $this->chat($userMessage)->getMessage();
            
            return $response->getContent();
        } catch (\Exception $e) {
            // A provider error body can quote part of the API key (B-SEC-1), so redact before logging.
            \Concrete\Core\Support\Facade\Log::error('Neuron AI Error: ' . SecretRedactor::redact($e->getMessage()));

            // Keep message alternation valid when the provider fails after a user message is added.
            // Without this, subsequent requests can fail with "Invalid message sequence".
            try {
                $chatHistory = $this->resolveState()->getChatHistory();
                $messages = $chatHistory->getMessages();

                if (!empty($messages)) {
                    $lastMessage = end($messages);
                    if ($lastMessage && $lastMessage->getRole() === 'user') {
                        $chatHistory->addMessage(new AssistantMessage(
                            'I ran into an error processing that request. Please try again.'
                        ));
                    }
                }
            } catch (\Throwable $historyError) {
                \Concrete\Core\Support\Facade\Log::warning(
                    'Failed to append fallback assistant message after provider error: ' . $historyError->getMessage()
                );
            }

            return "I ran into an error processing that request. Please try again.";
        }
    }

}
