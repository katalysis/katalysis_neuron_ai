<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Api\Attribute\AttributeValueMapFactory;
use Concrete\Core\Area\Area;
use Concrete\Core\Area\Exception\AreaNotFoundException;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\Command\AddBlockToPageCommand;
use Concrete\Core\Block\Command\DeleteBlockCommand;
use Concrete\Core\Block\Command\UpdatePageBlockCommand;
use Concrete\Core\Block\Exception\BlockNotFoundException;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Block\Traits\ValidateBlockRequestTrait;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Attribute\Category\PageCategory;
use Concrete\Core\Editor\LinkAbstractor;
use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Page\Command\DeletePageCommand;
use Concrete\Core\Page\Template;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\Utilities\AbsoluteUrl;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;
use Katalysis\NeuronAi\Tools\Utilities\BlockIdRemapper;
use Katalysis\NeuronAi\Tools\Utilities\BlockNotFound;
use Katalysis\NeuronAi\Tools\Utilities\BlockRemapException;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\DraftState;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\PageVersionRead;
use Katalysis\NeuronAi\Tools\Utilities\StrictInputTool;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use Katalysis\NeuronAi\Tools\Utilities\TextIntegrity;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Concrete CMS Pages toolkit for Neuron AI
 * 
 * Provides native tools for page management without HTTP overhead.
 * Maps all OpenAPI page operations to direct Concrete CMS calls.
 */
class PagesToolkit extends AbstractToolkit
{
    use GetBlockToEditTrait {
        getBlockToWorkWith as protected coreGetBlockToWorkWith;
        getBlockToEdit as protected coreGetBlockToEdit;
    }
    use ValidateBlockRequestTrait;

    /**
     * The block types whose write payloads get the EPRA row and field renaming (normalizeCustomBlockPayload()). Every
     * other block's fields reach its controller as given (BF-8). EPRA knowledge in core, kept here only as an interim
     * fix: P3.4 moves it to the EPRA package and P3.10's descriptors replace it.
     */
    private const EPRA_PAYLOAD_BLOCK_HANDLES = ['epra_resources_list'];

    public function guidelines(): ?string
    {
        return "Use these tools to manage pages in Concrete CMS. You can list, create, read and update pages. A page made with create_page is unpublished: a person publishes it from Page Versions. Moving and deleting pages are not available yet (they return with approvals), so say so instead of trying another tool. Always check permissions before modifying content.";
    }

    public function provide(): array
    {
        return [
            $this->makeGetCurrentPageContextTool(),
            $this->makeListPagesTool(),
            $this->makeListPageTypesTool(),
            $this->makeGetPageTool(),
            $this->makeGetPageContentTool(),
            $this->makeGetPageBlocksTool(),
            $this->makeBatchUpdatePageContentTool(),
            $this->makeBatchUpdateContentHeadingsTool(),
            $this->makeSyncMultiLinksToEpraResourcesTool(),
            $this->makeCreatePageTool(),
            $this->makeUpdatePageTool(),
            $this->makeMovePageTool(),
            $this->makeDeletePageTool(),
            $this->makeGetPageChildrenTool(),
            $this->makeAddBlockToPageAreaTool(),
            $this->makeUpdateBlockInPageAreaTool(),
            $this->makeDeleteBlockFromPageAreaTool(),
            $this->makeMoveBlockBetweenAreasTool(),
        ];
    }

    protected function makeGetCurrentPageContextTool(): Tool
    {
        $tool = new CallableTool(
            'get_current_page_context',
            'Get context for the page currently being viewed in the browser, including attributes and area/block summary. Shows the page\'s newest version, as editors see it.'
        );

        $tool->setHandler($this->guarded(function (): array {
            [$page, $source, $runtimeContext] = $this->resolveCurrentContextPage();

            if (!$page || $page->isError()) {
                throw new \RuntimeException('Unable to determine the current page context.');
            }

            $permissions = new Checker($page);
            if (!$permissions->canViewPage()) {
                throw new \RuntimeException('Permission denied: Cannot view the current page context.');
            }

            // BF-10: the page is found by its published version; its content comes from the newest one, as editors see it.
            [$page, $versionInfo] = PageVersionRead::load((int) $page->getCollectionID(), null);

            return [
                'source' => $source,
                'runtimeContext' => $runtimeContext,
                'page' => $this->serializePage($page, includeDetails: true),
                'version' => $versionInfo,
                'attributes' => $this->getPageAttributeMap($page),
                'areas' => $this->getPageAreaSnapshot($page),
            ];
        }));

        return $tool;
    }

    protected function makeListPagesTool(): Tool
    {
        $tool = new CallableTool(
            'list_pages',
            'List pages with optional filtering and sorting. Returns most recently modified pages by default. Use includeDescendants=true to get all pages under a path (not just direct children). '
            . 'The result gives the total and whether there are more; use offset for the next pages.'
        );

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of pages to return (1-100, default 20)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->addProperty(new ToolProperty(
            'parentID',
            PropertyType::INTEGER, 
            'Filter by parent page ID (direct children only unless includeDescendants=true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'parentPath',
            PropertyType::STRING,
            'Filter by parent page path (e.g., "/newsroom/press-releases"). Use instead of parentID when you have a path.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'includeDescendants',
            PropertyType::BOOLEAN,
            'If true, include ALL pages under the parent (recursive). If false, only direct children. Default: false',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'pageType',
            PropertyType::STRING,
            'Filter by page type handle (e.g., "page", "blog_entry")',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $limit = 20,
            ?int $parentID = null,
            ?string $parentPath = null,
            ?bool $includeDescendants = false,
            ?string $pageType = null,
            ?bool $onlyVisible = false,
            ?int $offset = null
        ): array {
            $window = ListWindow::of($limit, $offset, 20, 100);
            $list = new PageList();
            // Match API behavior: exclude external links from page collections.
            $list->getQueryObject()->andWhere('cPointerExternalLink is null');
            $list->setPermissionsChecker(function ($page) {
                $permissions = new Checker($page);
                return $permissions->canViewPage();
            });
            $list->sortByDateModifiedDescending();
            // BF-6: a second key, so offset 20 continues where offset 0 stopped.
            $list->getQueryObject()->addOrderBy('p.cID', 'desc');
            
            // Handle parent filtering
            $resolvedParentID = $parentID;
            if ($parentPath && !$resolvedParentID) {
                $parent = Page::getByPath($parentPath);
                if ($parent && !$parent->isError()) {
                    $resolvedParentID = $parent->getCollectionID();
                } else {
                    // Parent path was provided but page not found - this is an error
                    throw new \RuntimeException("Parent page not found at path: {$parentPath}");
                }
            }
            
            if ($resolvedParentID) {
                if ($includeDescendants) {
                    // Get ALL pages under this parent (recursive)
                    // Use path-based filtering to find all descendants
                    $parent = Page::getByID($resolvedParentID);
                    if ($parent && !$parent->isError()) {
                        $parentPathPrefix = $parent->getCollectionPath();
                        // Ensure path ends with / for proper prefix matching
                        if ($parentPathPrefix !== '/' && !str_ends_with($parentPathPrefix, '/')) {
                            $parentPathPrefix .= '/';
                        }
                        // Use LIKE pattern to match all pages under this path
                        $list->filterByPath($parentPathPrefix . '%');
                    }
                } else {
                    // Only direct children (original behavior)
                    $list->filterByParentID($resolvedParentID);
                }
            }
            
            if ($pageType) {
                $list->filterByPageTypeHandle($pageType);
            }
            
            // Only filter by exclude_nav if specifically requested
            if ($onlyVisible) {
                $list->filterByAttribute('exclude_nav', false);
            }
            
            // BF-6: getResults() ignores setItemsPerPage() and returns every match, and with a permission checker
            // Concrete has no count query, so the permitted matches are counted here and the window is sliced from them.
            $results = $list->getResults();
            $pages = array_map(fn($page) => $this->serializePage($page), $window->slice($results));

            return ['pages' => $pages] + $window->summary(count($results), count($pages), 'list_pages', 'parentPath, pageType or includeDescendants=false');
        }));

        return $tool;
    }

    protected function makeListPageTypesTool(): Tool
    {
        $tool = new CallableTool(
            'list_page_types',
            'List Concrete CMS page types by handle, including usage counts and total number of defined types.'
        );

        $tool->setHandler($this->guarded(function (): array {
            $types = PageType::getList(false);
            $results = [];

            foreach ($types as $type) {
                $handle = $type->getPageTypeHandle();

                $pageList = new PageList();
                $pageList->ignorePermissions();
                $pageList->filterByPageTypeHandle($handle);
                $count = $pageList->getTotalResults();

                $results[] = [
                    'handle' => $handle,
                    'name' => $type->getPageTypeDisplayName(),
                    'isInternal' => (bool) $type->isPageTypeInternal(),
                    'usageCount' => (int) $count,
                ];
            }

            usort($results, function (array $a, array $b): int {
                return $b['usageCount'] <=> $a['usageCount'];
            });

            return [
                'totalDefinedPageTypes' => count($results),
                'pageTypes' => $results,
            ];
        }));

        return $tool;
    }

    protected function makeGetPageTool(): Tool
    {
        $tool = new CallableTool(
            'get_page',
            'Retrieve detailed information about a specific page by ID: attributes and an area/block summary. Reads the newest version by default (see version).'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to retrieve',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::DESCRIPTION,
            false
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $version = null): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            // BF-10: the newest version by default, as editors see it; the result says which version it shows.
            [$page, $versionInfo] = PageVersionRead::load($pageID, $version);

            if ($page->isInTrash()) {
                throw new \RuntimeException('This page is pending deletion.');
            }

            return $this->serializePage($page, includeDetails: true) + ['version' => $versionInfo];
        }));

        return $tool;
    }

    protected function makeGetPageContentTool(): Tool
    {
        $tool = new CallableTool(
            'get_page_content',
            'Get page content as a document with HTML and plain text. Prefer this over get_page when you need to review or summarize page copy. '
            . 'Give pageID or pagePath (a page the user names by its path). Reads the newest version by default (see version).'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to read. Give pageID or pagePath.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'pagePath',
            PropertyType::STRING,
            'Path of the page to read, for example /about/team ("/" is the home page). Give pageID or pagePath.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::DESCRIPTION,
            false
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $pagePath = null, ?string $version = null): array {
            $pageID = $this->pageIDFromIdOrPath($pageID, $pagePath);

            // BF-10: the newest version by default, as editors see it; the result says which version it shows.
            [$page, $versionInfo] = PageVersionRead::load($pageID, $version);
            $versionHandle = $versionInfo['shows'] === PageVersionRead::NEWEST ? 'RECENT' : 'ACTIVE';

            // Read the blocks directly. Rendering the page needs a current page and a web
            // request, so it fails under the CLI and in the chat panel's own context.
            $read = $this->readPageBlocks($page);
            // P3.3-1: html is the page as Concrete renders it (links translated, absolute URLs); html_raw is the stored
            // form ({CCM:…} placeholders, <concrete-picture> tags), which the write tools keep.
            $content = $read['html'];
            $rawContent = $read['rawHtml'];
            $renderMethod = 'blocks';
            $renderErrors = $read['errors'];

            $plainText = HtmlToText::convert($content);

            $result = [
                'id' => $page->getCollectionID(),
                'path' => $page->getCollectionPath(),
                'name' => $page->getCollectionName(),
                'description' => $page->getCollectionDescription(),
                'version' => [
                    'cvID' => $page->getVersionID(),
                    'isActive' => $page->isActive(),
                    'versionHandle' => $versionHandle,
                ] + $versionInfo,
                'html' => $content,
                'html_raw' => $rawContent,
                'text' => $plainText,
                'renderMethod' => $renderMethod,
                'blocks' => $read['blocks'],
                'hiddenBlocks' => $read['hiddenBlocks'],
            ];
            
            if (!empty($renderErrors)) {
                $result['renderErrors'] = $renderErrors;
            }
            if ($read['unresolvedLinks'] !== []) {
                $result['unresolvedLinks'] = $read['unresolvedLinks'];
            }
            if (AbsoluteUrl::hasRelativeUrls($content)) {
                $result['imageUrlNote'] = AbsoluteUrl::NOTE;
            }

            return $result;
        }));

        return $tool;
    }

    protected function makeGetPageBlocksTool(): Tool
    {
        $tool = new CallableTool(
            'get_page_blocks',
            'Get detailed inventory of all blocks on a page grouped by area. Use this for debugging or when you need block IDs for updates.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to inspect',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            PageVersionRead::DESCRIPTION,
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Optional: Only return blocks from this specific area',
            false
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $version = null, ?string $areaHandle = null): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            [$page, $versionInfo] = PageVersionRead::load($pageID, $version);

            $areas = [];
            $totalBlocks = 0;
            $errors = [];

            try {
                $areaHandles = $areaHandle ? [$areaHandle] : Area::getHandleList();
                
                foreach ($areaHandles as $handle) {
                    try {
                        $area = Area::get($page, $handle);
                        if (!$area) {
                            if ($areaHandle === $handle) {
                                throw new \RuntimeException("Area '{$handle}' does not exist on this page");
                            }
                            continue;
                        }
                        
                        $blocks = $area->getAreaBlocksArray($page);
                        $blockData = [];
                        
                        foreach ($blocks as $block) {
                            try {
                                $controller = $block->getController();
                                $blockType = $block->getBlockTypeHandle();
                                $blockTypeName = $block->getBlockTypeName();
                                
                                // Try to extract a preview of content
                                $preview = ['contentPreview' => null];
                                try {
                                    if (method_exists($controller, 'getContent')) {
                                        $preview = $this->blockPreviewFields((string) $controller->getContent());
                                    } elseif (method_exists($controller, 'getSearchableContent')) {
                                        $preview = $this->blockPreviewFields((string) $controller->getSearchableContent());
                                    }
                                } catch (\Exception $e) {
                                    $preview = ['contentPreview' => "[Error extracting content: " . $e->getMessage() . "]"];
                                }
                                
                                $blockData[] = [
                                    'blockID' => $block->getBlockID(),
                                    'blockType' => $blockType,
                                    'blockTypeName' => $blockTypeName,
                                    'blockName' => $block->getBlockName(),
                                    'displayOrder' => $block->getBlockDisplayOrder(),
                                ] + $preview;
                                $totalBlocks++;
                            } catch (\Exception $e) {
                                $errors[] = "Error reading block in area '{$handle}': " . $e->getMessage();
                            }
                        }
                        
                        if (!empty($blockData)) {
                            $areas[] = [
                                'areaHandle' => $handle,
                                'blockCount' => count($blockData),
                                'blocks' => $blockData,
                            ];
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Error reading area '{$handle}': " . $e->getMessage();
                    }
                }
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to enumerate areas: " . $e->getMessage());
            }

            $result = [
                'page' => [
                    'id' => $page->getCollectionID(),
                    'path' => $page->getCollectionPath(),
                    'name' => $page->getCollectionName(),
                    'versionID' => $page->getVersionID(),
                    'isActive' => $page->isActive(),
                ],
                'version' => $versionInfo,
                'totalBlocks' => $totalBlocks,
                'areaCount' => count($areas),
                'areas' => $areas,
                'note' => 'contentPreview values are shortened previews for finding blocks; a block marked contentPreviewShortened has more text. Do not use contentPreview as source content for updates: get_block_data returns a block\'s full stored fields, and get_page_content the full text of the page.',
            ] + $this->subAreaSummary($page);

            if (!empty($errors)) {
                $result['errors'] = $errors;
                $result['warning'] = 'Some errors occurred during block inspection. See errors array.';
            }

            return $result;
        }));

        return $tool;
    }

    protected function makeBatchUpdatePageContentTool(): Tool
    {
        $tool = new CallableTool(
            'batch_update_page_content',
            'Safely update multiple blocks on a page. Creates a new page version, remaps block IDs automatically, then updates each block. Does not auto-approve the version.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to update',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blocks',
            PropertyType::ARRAY,
            'Array of block updates. Each item must have: areaHandle (string), blockID (number from current version), value (object with block data). An item may have its own changeMode, which applies to that block instead of the top-level one. With changeMode "scoped_text", the item also has textFields (fields whose text may change) and replacedText (the stored text the change replaces or removes, word for word). Fields left out of value keep their stored values. Every item is checked before anything is saved: a refused item is left unchanged and keeps its block ID.',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'changeMode',
            PropertyType::STRING,
            'Text safety mode: "keep_text" (default: existing text must stay; text may be added), "formatting_only" (text must remain identical), or "scoped_text" (text may change only in each block\'s textFields, and stored text may go only if it is listed in that block\'s replacedText)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Whether to apply the block updates that pass their checks when another one fails (default: true). With false, nothing is applied if any block update fails its check.',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?array $blocks = [],
            ?string $changeMode = null,
            ?bool $continueOnError = true
        ): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            if (empty($blocks) || !is_array($blocks)) {
                throw new \RuntimeException("blocks must be a non-empty array. Provide at least one block update.");
            }

            // Neuron passes null for an input the model left out: the default is true, as the description says.
            $continueOnError ??= true;
            $mode = TextIntegrity::mode($changeMode);

            // Validate block update structure
            $updates = [];
            foreach ($blocks as $index => $block) {
                if (!isset($block['areaHandle']) || !isset($block['blockID']) || !isset($block['value'])) {
                    throw new \RuntimeException("blocks[{$index}] must have areaHandle, blockID, and value. Got: " . json_encode(array_keys($block)));
                }

                if (!is_string($block['areaHandle']) || empty($block['areaHandle'])) {
                    throw new \RuntimeException("blocks[{$index}].areaHandle must be a non-empty string, got: " . gettype($block['areaHandle']));
                }

                if (!is_int($block['blockID']) || $block['blockID'] <= 0) {
                    throw new \RuntimeException("blocks[{$index}].blockID must be a positive integer, got: " . var_export($block['blockID'], true));
                }

                if (!is_array($block['value'])) {
                    throw new \RuntimeException("blocks[{$index}].value must be an object/array, got: " . gettype($block['value']));
                }

                // An item's own changeMode overrides the top-level one for that item (BF-3).
                $itemMode = $mode;
                if (isset($block['changeMode'])) {
                    if (!is_string($block['changeMode']) || !in_array(strtolower(trim($block['changeMode'])), TextIntegrity::MODES, true)) {
                        throw new \RuntimeException("blocks[{$index}].changeMode must be one of: " . implode(', ', TextIntegrity::MODES) . '. Nothing was changed.');
                    }
                    $itemMode = TextIntegrity::mode($block['changeMode']);
                }

                $textFields = null;
                if (isset($block['textFields'])) {
                    if (!is_array($block['textFields'])) {
                        throw new \RuntimeException("blocks[{$index}].textFields must be an array of field names when provided");
                    }
                    $textFields = array_values(array_filter(array_map(static fn($v) => is_string($v) ? trim($v) : '', $block['textFields']), static fn($v) => $v !== ''));
                }

                $replacedText = null;
                if (isset($block['replacedText'])) {
                    if (!is_array($block['replacedText'])) {
                        throw new \RuntimeException("blocks[{$index}].replacedText must be an array of the stored text being replaced when provided");
                    }
                    $replacedText = array_values(array_filter($block['replacedText'], 'is_string'));
                }

                $updates[] = [
                    'areaHandle' => $block['areaHandle'],
                    'blockID' => $block['blockID'],
                    'value' => $block['value'],
                    'changeMode' => $itemMode,
                    'textFields' => $textFields,
                    'replacedText' => $replacedText,
                ];
            }

            try {
                $page = Page::getByID($pageID, 'RECENT');
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to load page {$pageID}: " . $e->getMessage());
            }
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found. The page may not exist or may have been deleted.");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            foreach ($updates as $update) {
                SystemPageGuard::refuseGlobalArea($page, (string) $update['areaHandle']);
                EditModeGuard::refuseOpenBlock($page, (int) $update['blockID']);
            }

            $permissions = new Checker($page);
            if (!$permissions->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}. You need edit permissions for this page.");
            }

            // Blocks in container or area layout columns can't be remapped between versions (the remap
            // only sees top-level areas). Refuse them now, before any draft exists, so nothing is left behind.
            $this->refuseBlocksInSubAreas($page, $updates);
            // A block the newest version doesn't hold gets the reason and the ID to use, before any draft (BF-7).
            foreach ($updates as $update) {
                BlockNotFound::refuseUnlessInNewestVersion($page, (string) $update['areaHandle'], (int) $update['blockID']);
            }

            // Check every item on the newest version before any draft exists (BF-3): the draft shares its blocks with
            // it, so a refused item is never copied and its block ID stays valid for a retry.
            $blockResults = [];
            $checked = [];
            foreach ($updates as $index => $update) {
                try {
                    $checked[$index] = $this->checkBatchItem($page, $update);
                } catch (\Exception $e) {
                    $blockResults[$index] = [
                        'areaHandle' => $update['areaHandle'],
                        'oldBlockID' => $update['blockID'],
                        'changeMode' => $update['changeMode'],
                        'ok' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $failureCount = count($blockResults);
            if ($checked === [] || ($failureCount > 0 && !$continueOnError)) {
                // Nothing is applied: no draft is made.
                foreach ($checked as $index => $item) {
                    $blockResults[$index] = [
                        'areaHandle' => $updates[$index]['areaHandle'],
                        'oldBlockID' => $updates[$index]['blockID'],
                        'changeMode' => $updates[$index]['changeMode'],
                        'ok' => false,
                        'error' => 'Not applied: another block failed its check and continueOnError is false. Nothing was changed.',
                    ];
                }
                ksort($blockResults);

                return $this->batchResult($page, $blockResults, $mode, (bool) $continueOnError) + [
                    'note' => 'Nothing was changed and no draft was made. Fix the blocks listed with ok: false and send the call again; '
                        . 'the block IDs are unchanged.',
                ];
            }

            // Capture block layout BEFORE creating new version
            try {
                $beforeAreas = BlockIdRemapper::captureAreaSnapshot($page);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to capture block layout before versioning: " . $e->getMessage());
            }

            // Create new version
            try {
                $pageToModify = $this->getDraftOf($page);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to create new page version: " . $e->getMessage() . ". This may indicate a database or permissions issue.");
            }

            // Capture block layout AFTER creating new version
            try {
                $afterAreas = BlockIdRemapper::captureAreaSnapshot($pageToModify);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to capture block layout after versioning: " . $e->getMessage());
            }

            // Remap block IDs, for the items that passed their check
            $indexes = array_keys($checked);
            try {
                $remapped = BlockIdRemapper::remap($beforeAreas, $afterAreas, array_map(static fn (int $i): array => $updates[$i], $indexes));
            } catch (BlockRemapException $e) {
                throw new \RuntimeException("Block ID remapping failed: " . $e->getMessage() . ". This usually means blocks couldn't be matched between versions. Use get_page_blocks tool to verify block IDs and areas.");
            }

            // Update each block
            $successCount = 0;

            foreach ($remapped as $position => $update) {
                $index = $indexes[$position];
                $item = $checked[$index];
                $areaHandle = $update['areaHandle'];
                $newBlockID = $update['newBlockID'];
                $itemMode = $updates[$index]['changeMode'];

                try {
                    // Get the area and block
                    $area = $pageToModify->getArea($areaHandle);
                    if (!$area) {
                        throw new \RuntimeException("Area '{$areaHandle}' not found on page version {$pageToModify->getVersionID()}");
                    }

                    $block = Block::getByID($newBlockID, $pageToModify, $area);
                    if (!$block) {
                        throw new \RuntimeException("Block {$newBlockID} not found in area '{$areaHandle}' on page version {$pageToModify->getVersionID()}");
                    }

                    // A new version shares its blocks with the live one. Copy this block into the
                    // draft first (as Concrete's own editor does), so the update can't change the live version.
                    $block = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $newBlockID);
                    if (!$block) {
                        throw new \RuntimeException("Block {$newBlockID} could not be prepared for editing in area '{$areaHandle}'");
                    }
                    $newBlockID = (int) $block->getBlockID();

                    $watchedFields = $item['textPolicy']['watchedFields'];
                    $beforeChecksums = TextIntegrity::checksums($item['beforeRecord'], $watchedFields);

                    // Update the block
                    $block->update($item['value']);

                    $afterRecord = $this->getBlockTableRecord($item['dbTable'], $newBlockID);
                    $afterChecksums = TextIntegrity::checksums($afterRecord, $watchedFields);

                    $blockResults[$index] = [
                        'areaHandle' => $areaHandle,
                        'oldBlockID' => $update['oldBlockID'],
                        'newBlockID' => $newBlockID,
                        'blockType' => $block->getBlockTypeHandle(),
                        'changeMode' => $itemMode,
                        'watchedTextFields' => $watchedFields,
                        'textCheck' => $item['textPolicy']['fields'],
                        'keptFields' => $item['keptFields'],
                        'textChecksumsBefore' => $beforeChecksums,
                        'textChecksumsAfter' => $afterChecksums,
                        'ok' => true,
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $blockResults[$index] = [
                        'areaHandle' => $areaHandle,
                        'oldBlockID' => $update['oldBlockID'],
                        'newBlockID' => $newBlockID,
                        'changeMode' => $itemMode,
                        'ok' => false,
                        'error' => $e->getMessage(),
                    ];

                    if (!$continueOnError) {
                        break;
                    }
                }
            }
            ksort($blockResults);

            $result = $this->batchResult($pageToModify, $blockResults, $mode, (bool) $continueOnError);
            if ($successCount > 0) {
                $result += DraftState::of($pageToModify);
            }

            return $result;
        }));

        return $tool;
    }

    /**
     * One batch item's checks, on the newest version and before any draft (BF-3): the value as it will be saved, and
     * the text check with the item's own mode.
     *
     * @param array{areaHandle: string, blockID: int, value: array, changeMode: string, textFields: ?array, replacedText: ?array} $update
     * @return array{value: array, keptFields: array, textPolicy: array, beforeRecord: array, dbTable: mixed}
     * @throws \RuntimeException with the reason the item is refused; nothing has been changed
     */
    private function checkBatchItem(Page $page, array $update): array
    {
        $areaHandle = (string) $update['areaHandle'];
        $blockID = (int) $update['blockID'];
        $area = $page->getArea($areaHandle);
        $block = $area ? Block::getByID($blockID, $page, $area) : null;
        if (!$block) {
            throw new \RuntimeException(BlockNotFound::explain($page, $areaHandle, $blockID));
        }

        $controller = $block->getController();
        $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
        $beforeRecord = $this->getBlockTableRecord($dbTable, $blockID);

        $value = $this->normalizeCustomBlockPayload($block->getBlockTypeHandle(), (array) ($update['value'] ?? []));
        if (in_array($block->getBlockTypeHandle(), self::EPRA_PAYLOAD_BLOCK_HANDLES, true) && !array_key_exists('slide', $value)) {
            // Preserve existing repeatable rows for layout-only updates in batch mode.
            $value['slide'] = $this->loadEpraResourceEntries($blockID);
        }
        // Fields the update leaves out keep their stored values (BF-5).
        $kept = TextIntegrity::keepOmittedFields($beforeRecord, $value);

        $textPolicy = TextIntegrity::evaluate($beforeRecord, $kept['value'], $update['changeMode'], $update['textFields'], $update['replacedText']);
        if (!$textPolicy['ok']) {
            throw new \RuntimeException($textPolicy['message']);
        }

        return [
            'value' => $kept['value'],
            'keptFields' => $kept['keptFields'],
            'textPolicy' => $textPolicy,
            'beforeRecord' => $beforeRecord,
            'dbTable' => $dbTable,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blockResults
     * @return array<string, mixed>
     */
    private function batchResult(Page $page, array $blockResults, string $mode, bool $continueOnError): array
    {
        $successCount = count(array_filter($blockResults, static fn (array $r): bool => $r['ok'] === true));
        $failureCount = count($blockResults) - $successCount;

        // P3.3-1 (B-TRU-2): a batch in which every item failed is not a success, so the checks that read `ok` see it.
        $result = $successCount === 0 && $blockResults !== [] ? ['ok' => false] : [];
        $result += [
            'id' => $page->getCollectionID(),
            'path' => $page->getCollectionPath(),
            'name' => $page->getCollectionName(),
            'version' => [
                'cvID' => $page->getVersionID(),
                'isActive' => $page->isActive(),
            ],
            'summary' => [
                'totalBlocks' => count($blockResults),
                'successCount' => $successCount,
                'failureCount' => $failureCount,
                'changeMode' => $mode,
                'continueOnError' => $continueOnError,
            ],
            'blocks' => array_values($blockResults),
            'safety' => [
                'textPolicy' => $mode,
                'checksumAlgorithm' => 'sha1(normalizedText)',
            ],
        ];

        if ($failureCount > 0) {
            $result['warning'] = "{$failureCount} block update(s) failed. See blocks array for details.";
        }

        return $result;
    }

    /**
     * Refuses a batch update that names a block inside a container or core area layout column.
     * Those blocks are in sub-areas (an Areas row with a parent area), which the block ID remap
     * doesn't see: it fails once the draft exists and leaves the draft behind. update_block_in_page_area
     * does edit blocks in sub-areas (DraftIsolationTest), one block at a time, so the message names it.
     *
     * @param list<array{areaHandle: string, blockID: int}> $updates
     * @throws \RuntimeException naming the first nested block; nothing has been changed
     */
    private function refuseBlocksInSubAreas(Page $page, array $updates): void
    {
        $db = Application::getFacadeApplication()->make('database')->connection();
        $cID = (int) $page->getCollectionID();
        $cvID = (int) $page->getVersionID();

        foreach ($updates as $index => $update) {
            $blockID = (int) $update['blockID'];
            $areaHandle = (string) $update['areaHandle'];

            // The area the block is really in, if that is a sub-area ...
            $actualHandle = $db->fetchOne(
                'SELECT cvb.arHandle FROM CollectionVersionBlocks cvb
                 JOIN Areas a ON a.cID = cvb.cID AND a.arHandle = cvb.arHandle
                 WHERE cvb.cID = ? AND cvb.cvID = ? AND cvb.bID = ? AND a.arParentID > 0
                 LIMIT 1',
                [$cID, $cvID, $blockID]
            );
            // ... or the area the caller named, if that is a sub-area.
            $namedIsSubArea = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM Areas WHERE cID = ? AND arHandle = ? AND arParentID > 0',
                [$cID, $areaHandle]
            ) > 0;

            if ($actualHandle !== false || $namedIsSubArea) {
                $handle = $actualHandle !== false ? (string) $actualHandle : $areaHandle;
                throw new \RuntimeException(
                    "blocks[{$index}]: block {$blockID} is in the area '{$handle}', which is inside a container or area layout column. "
                    . "batch_update_page_content can't update blocks there yet. Nothing was changed. "
                    . "To edit a block in a container or layout column, use update_block_in_page_area with areaHandle '{$handle}', one block at a time."
                );
            }
        }
    }

    /**
     * @param mixed $dbTable
     * @return array<string, mixed>
     */
    private function getBlockTableRecord($dbTable, int $blockID): array
    {
        if (!is_string($dbTable) || trim($dbTable) === '') {
            return [];
        }

        try {
            $conn = Application::getFacadeApplication()->make('database')->connection();
            $qb = $conn->createQueryBuilder();
            $qb->select('*')
                ->from($dbTable)
                ->where('bID = :bID')
                ->setParameter('bID', $blockID)
                ->setMaxResults(1);

            $row = $qb->execute()->fetchAssociative();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function makeCreatePageTool(): Tool
    {
        return $this->makeCreatePageToolInternal();
    }

    protected function makeBatchUpdateContentHeadingsTool(): Tool
    {
        $tool = new CallableTool(
            'batch_update_content_headings',
            'Find Content blocks on a page by heading text and update heading tag level only (for example bold paragraph to h3) while preserving body text.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to update',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'headingTexts',
            PropertyType::ARRAY,
            'Optional heading texts to find in Content blocks (exact text match after normalization). Required when matchMode="explicit".',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'matchMode',
            PropertyType::STRING,
            'Matching mode: "explicit" (default, uses headingTexts) or "bold_paragraph" (auto-detects <p><strong>...</strong></p> headings).',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandleContains',
            PropertyType::STRING,
            'Optional area handle substring filter (for example: " : Column 1")',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'targetTag',
            PropertyType::STRING,
            'Heading tag to apply (h1-h6). Default: h3',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'includeInactive',
            PropertyType::BOOLEAN,
            'Ignored. This tool always works on the blocks of the new draft version, never on the live version.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Continue processing remaining headings after an error (default: true)',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?array $headingTexts = null,
            ?string $matchMode = 'explicit',
            ?string $areaHandleContains = null,
            ?string $targetTag = 'h3',
            ?bool $includeInactive = true,
            ?bool $continueOnError = true
        ): array {
            if (!$pageID) {
                throw new \RuntimeException('pageID is required');
            }

            $matchMode = strtolower(trim((string) ($matchMode ?? 'explicit')));
            if (!in_array($matchMode, ['explicit', 'bold_paragraph'], true)) {
                throw new \RuntimeException('matchMode must be either explicit or bold_paragraph');
            }

            $areaHandleContains = is_string($areaHandleContains) ? trim($areaHandleContains) : null;
            if ($areaHandleContains === '') {
                $areaHandleContains = null;
            }

            $targetTag = strtolower(trim((string) ($targetTag ?? 'h3')));
            if (!in_array($targetTag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                throw new \RuntimeException('targetTag must be one of h1-h6');
            }

            $normalizedTitles = [];
            // With bold_paragraph, no headingTexts means every bold paragraph (P1.12: a null here raised a warning).
            foreach ($headingTexts ?? [] as $text) {
                if (!is_string($text)) {
                    continue;
                }
                $trimmed = trim($text);
                if ($trimmed === '') {
                    continue;
                }
                $normalizedTitles[$this->normalizeHeadingLookupText($trimmed)] = $trimmed;
            }

            if ($matchMode === 'explicit' && empty($normalizedTitles)) {
                // Scenario 05 (P3.3-1): after a shorter refusal the model retried with bold_paragraph, which changed the
                // wrong content. Nothing was changed here.
                throw new \RuntimeException(
                    'With matchMode explicit (the default) this tool needs the exact heading texts in headingTexts. '
                    . 'get_page_content shows the page\'s headings. matchMode bold_paragraph is only for converting bold paragraphs into headings, '
                    . 'never for changing the level of existing headings. Nothing was changed.'
                );
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);

            $permissions = new Checker($page);
            if (!$permissions->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}");
            }

            // Neuron passes null for an input the model left out: the default is true, as the description says.
            $continueOnError ??= true;

            $contentBt = BlockType::getByHandle('content');
            if (!$contentBt) {
                throw new \RuntimeException('Content block type is not available');
            }

            // Read pass, on the newest version and before any draft (BF-3): find the headings to change. A new draft
            // shares its blocks with the newest version, so the block IDs found here are the draft's until copied.
            $conn = Application::getFacadeApplication()->make('database')->connection();
            $qb = $conn->createQueryBuilder();
            $qb->select('b.bID', 'cvb.arHandle', 'cvb.cvID', 'cv.cvIsApproved')
                ->from('Blocks', 'b')
                ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                ->where('cvb.cID = :cID')
                // Only the blocks of the version being edited: the draft shares blocks with the live
                // version until they are copied, and the live copies must never be updated.
                ->andWhere('cvb.cvID = :cvID')
                ->andWhere('b.btID = :btID')
                ->setParameter('cID', $pageID)
                ->setParameter('cvID', $page->getVersionID())
                ->setParameter('btID', $contentBt->getBlockTypeID())
                ->orderBy('b.bID', 'ASC')
                ->addOrderBy('cv.cvIsApproved', 'DESC')
                ->addOrderBy('cvb.cvID', 'DESC');

            $placements = $qb->execute()->fetchAllAssociative();
            if (empty($placements)) {
                return [
                    'ok' => false,
                    'summary' => [
                        'requestedHeadings' => count($normalizedTitles),
                        'updatedCount' => 0,
                        'failedCount' => 0,
                        'notFoundCount' => count($normalizedTitles),
                    ],
                    'updated' => [],
                    'failed' => [],
                    'notFoundHeadings' => array_values($normalizedTitles),
                    'note' => 'No content block placements found for this page.',
                ];
            }

            // Keep preferred placement per block: approved first, then newest version.
            $preferredPlacements = [];
            foreach ($placements as $placement) {
                $bID = (int) ($placement['bID'] ?? 0);
                if ($bID <= 0) {
                    continue;
                }

                if (!isset($preferredPlacements[$bID])) {
                    $preferredPlacements[$bID] = $placement;
                    continue;
                }

                $current = $preferredPlacements[$bID];
                $currentApproved = (int) ($current['cvIsApproved'] ?? 0) === 1;
                $candidateApproved = (int) ($placement['cvIsApproved'] ?? 0) === 1;
                if ($candidateApproved && !$currentApproved) {
                    $preferredPlacements[$bID] = $placement;
                    continue;
                }
                if ($candidateApproved === $currentApproved && (int) ($placement['cvID'] ?? 0) > (int) ($current['cvID'] ?? 0)) {
                    $preferredPlacements[$bID] = $placement;
                }
            }

            $pendingHeadings = $normalizedTitles;
            $planned = [];
            $updated = [];
            $failed = [];

            foreach ($preferredPlacements as $blockID => $placement) {
                if ($matchMode === 'explicit' && empty($pendingHeadings)) {
                    break;
                }

                $areaHandle = (string) ($placement['arHandle'] ?? '');
                if ($areaHandleContains !== null && stripos($areaHandle, $areaHandleContains) === false) {
                    continue;
                }

                try {
                    $area = $page->getArea($areaHandle);
                    if (!$area) {
                        $area = new Area($areaHandle);
                        $area->c = $page;
                    }

                    $block = Block::getByID((int) $blockID, $page, $area);
                    if (!$block) {
                        continue;
                    }

                    $controller = $block->getController();
                    $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
                    $record = $this->getBlockTableRecord($dbTable, (int) $blockID);
                    $content = is_string($record['content'] ?? null) ? (string) $record['content'] : null;
                    if ($content === null || trim($content) === '') {
                        continue;
                    }

                    // P3.3-1: every matching heading in the block, not only the first (scenarios 05, 06 and 23).
                    $matches = [];
                    if ($matchMode === 'explicit') {
                        $matches = $this->findMatchingHeadingTextsInContent($content, $pendingHeadings);
                    } else {
                        foreach ($this->extractBoldParagraphHeadingTexts($content) as $detectedHeading) {
                            $detectedKey = $this->normalizeHeadingLookupText($detectedHeading);
                            if (empty($pendingHeadings) || isset($pendingHeadings[$detectedKey])) {
                                $matches[$detectedKey] = [
                                    'key' => $detectedKey,
                                    'text' => empty($pendingHeadings) ? $detectedHeading : (string) ($pendingHeadings[$detectedKey] ?? $detectedHeading),
                                ];
                            }
                        }
                    }

                    // Each heading is applied in turn; one whose text is only in the body (not a heading) changes nothing.
                    $transformedContent = $content;
                    $applied = [];
                    foreach ($matches as $match) {
                        $next = $this->applyHeadingLevelToContent($transformedContent, $match['text'], $targetTag);
                        if ($next !== $transformedContent) {
                            $transformedContent = $next;
                            $applied[] = $match;
                        }
                    }

                    if ($applied === []) {
                        continue;
                    }

                    $beforeText = TextIntegrity::normalize($content);
                    $afterText = TextIntegrity::normalize($transformedContent);
                    if ($beforeText !== $afterText) {
                        $names = implode("', '", array_column($applied, 'text'));
                        throw new \RuntimeException("text integrity check failed for '{$names}' (body text changed)");
                    }

                    // Never change a block under the user's open editor (BF-7): reported with the failures.
                    EditModeGuard::refuseOpenBlock($page, (int) $blockID);

                    $planned[] = [
                        'blockID' => (int) $blockID,
                        'areaHandle' => $areaHandle,
                        'dbTable' => $dbTable,
                        'headings' => $applied,
                        'transformedContent' => $transformedContent,
                        'beforeText' => $beforeText,
                    ];
                    foreach ($applied as $match) {
                        unset($pendingHeadings[$match['key']]);
                    }
                } catch (\Throwable $e) {
                    $failed[] = [
                        'blockID' => (int) $blockID,
                        'areaHandle' => $areaHandle,
                        'matchMode' => $matchMode,
                        'error' => $e->getMessage(),
                    ];

                    if (!$continueOnError) {
                        break;
                    }
                }
            }

            // Write pass: only when there is something to change, and (with continueOnError false) nothing failed.
            $pageToModify = null;
            if ($planned !== [] && ($continueOnError || $failed === [])) {
                $pageToModify = $this->getDraftOf($page);

                foreach ($planned as $change) {
                    $blockID = $change['blockID'];
                    $areaHandle = $change['areaHandle'];
                    try {
                        $area = $pageToModify->getArea($areaHandle);
                        if (!$area) {
                            $area = new Area($areaHandle);
                            $area->c = $pageToModify;
                        }
                        $oldBlockID = $blockID;

                        // Copy the block into the draft before changing it (see getBlockToEdit), so the live
                        // version keeps its text. The copy has a new block ID.
                        $editBlock = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $blockID);
                        if (!$editBlock) {
                            throw new \RuntimeException("Block {$blockID} could not be prepared for editing in area '{$areaHandle}'");
                        }
                        $blockID = (int) $editBlock->getBlockID();
                        $editBlock->update(['content' => $change['transformedContent']]);

                        $afterRecord = $this->getBlockTableRecord($change['dbTable'], $blockID);
                        $afterContent = is_string($afterRecord['content'] ?? null) ? (string) $afterRecord['content'] : '';

                        // One entry per heading, as before; headings in the same block share its block IDs.
                        foreach ($change['headings'] as $heading) {
                            $updated[] = [
                                'blockID' => $blockID,
                                'oldBlockID' => $oldBlockID,
                                'areaHandle' => $areaHandle,
                                'headingText' => $heading['text'],
                                'targetTag' => $targetTag,
                                'matchMode' => $matchMode,
                                'textChecksumBefore' => sha1($change['beforeText']),
                                'textChecksumAfter' => sha1(TextIntegrity::normalize($afterContent)),
                            ];
                        }
                    } catch (\Throwable $e) {
                        $failed[] = [
                            'blockID' => $blockID,
                            'areaHandle' => $areaHandle,
                            'matchMode' => $matchMode,
                            'error' => $e->getMessage(),
                        ];
                        foreach ($change['headings'] as $heading) {
                            if (isset($normalizedTitles[$heading['key']])) {
                                $pendingHeadings[$heading['key']] = $normalizedTitles[$heading['key']];
                            }
                        }

                        if (!$continueOnError) {
                            break;
                        }
                    }
                }
            }

            // P3.3-1 (B-TRU-2): nothing updated while a heading failed, wasn't found, or (bold_paragraph) none was
            // detected is not a success, so the checks that read `ok` see it.
            $result = [];
            if ($updated !== []) {
                // What changed, in words the model can repeat (Glyn, after scenario 05 in P3.3-1: a bold_paragraph call
                // converted bold paragraphs and the reply said the h3 headings had changed).
                $texts = implode(', ', array_map(static fn (array $u): string => '"' . $u['headingText'] . '"', $updated));
                $count = count($updated);
                $result['changed'] = $matchMode === 'bold_paragraph'
                    ? "Converted {$count} bold paragraph" . ($count === 1 ? '' : 's') . " to <{$targetTag}>: {$texts}. No existing headings were changed."
                    : "Changed {$count} heading" . ($count === 1 ? '' : 's') . " to <{$targetTag}>: {$texts}.";
            }
            if ($updated === []) {
                if ($failed !== [] || $pendingHeadings !== []) {
                    $result = ['ok' => false];
                } elseif ($matchMode === 'bold_paragraph') {
                    $result = ['ok' => false, 'note' => 'No bold-paragraph headings were found, so nothing was changed.'];
                }
            }

            return $result + [
                'summary' => [
                    'requestedHeadings' => count($normalizedTitles),
                    'updatedCount' => count($updated),
                    'failedCount' => count($failed),
                    'notFoundCount' => count($pendingHeadings),
                    'matchMode' => $matchMode,
                    'areaHandleContains' => $areaHandleContains,
                    'targetTag' => $targetTag,
                    'continueOnError' => (bool) $continueOnError,
                ],
                'updated' => $updated,
                'failed' => $failed,
                'notFoundHeadings' => array_values($pendingHeadings),
                'safety' => [
                    'mode' => 'formatting_only',
                    'checksumAlgorithm' => 'sha1(normalizedText)',
                    'note' => 'Updates are skipped if normalized text content changes.',
                ],
            ] + ($updated !== [] && $pageToModify !== null ? DraftState::of($pageToModify) : []);
        }));

        return $tool;
    }

    protected function makeSyncMultiLinksToEpraResourcesTool(): Tool
    {
        $tool = new CallableTool(
            'sync_multi_links_to_epra_resources_list',
            'For each area on a page that contains Multi Link blocks, sync file-linked entries into EPRA Resources List blocks in the same area. Supports update-only, create-if-missing, and create-new modes, plus append or replace entry behavior.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to process',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'includeInactive',
            PropertyType::BOOLEAN,
            'Include draft/pending placements during area discovery (default: true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Continue processing remaining areas when one area fails (default: true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'createMissingTarget',
            PropertyType::BOOLEAN,
            'Legacy flag: when true and operationMode is not provided, behaves like operationMode=create_if_missing; otherwise ignored',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'operationMode',
            PropertyType::STRING,
            'Target-block behavior: existing_only (default), create_if_missing, or create_only',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'entryMode',
            PropertyType::STRING,
            'Entry behavior when target exists: replace (default) or append',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandleContains',
            PropertyType::STRING,
            'Optional case-insensitive area-handle substring filter (for example: "Container 2")',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?bool $includeInactive = true,
            ?bool $continueOnError = true,
            ?bool $createMissingTarget = false,
            ?string $operationMode = null,
            ?string $entryMode = 'replace',
            ?string $areaHandleContains = null
        ): array {
            if (!$pageID) {
                throw new \RuntimeException('pageID is required');
            }

            $resolvedOperationMode = is_string($operationMode) && trim($operationMode) !== ''
                ? strtolower(trim($operationMode))
                : ((bool) $createMissingTarget ? 'create_if_missing' : 'existing_only');
            $allowedOperationModes = ['existing_only', 'create_if_missing', 'create_only'];
            if (!in_array($resolvedOperationMode, $allowedOperationModes, true)) {
                throw new \RuntimeException('Invalid operationMode. Allowed values: existing_only, create_if_missing, create_only');
            }

            $resolvedEntryMode = is_string($entryMode) && trim($entryMode) !== ''
                ? strtolower(trim($entryMode))
                : 'replace';
            $allowedEntryModes = ['replace', 'append'];
            if (!in_array($resolvedEntryMode, $allowedEntryModes, true)) {
                throw new \RuntimeException('Invalid entryMode. Allowed values: replace, append');
            }

            $areaFilter = is_string($areaHandleContains) ? trim($areaHandleContains) : '';

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);

            $permissions = new Checker($page);
            if (!$permissions->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}");
            }

            $pageToModify = $page->getVersionToModify();

            $multiType = BlockType::getByHandle('multi');
            if (!$multiType) {
                throw new \RuntimeException('Multi block type handle "multi" not found');
            }

            $epraType = BlockType::getByHandle('epra_resources_list');
            if (!$epraType) {
                throw new \RuntimeException('EPRA Resources List block type handle "epra_resources_list" not found');
            }

            $multiPlacements = $this->collectPreferredPlacementsForBlockType($pageID, (int) $multiType->getBlockTypeID(), (bool) $includeInactive);
            $epraPlacements = $this->collectPreferredPlacementsForBlockType($pageID, (int) $epraType->getBlockTypeID(), (bool) $includeInactive);

            $multiByArea = [];
            foreach ($multiPlacements as $blockID => $placement) {
                $areaHandle = trim((string) ($placement['arHandle'] ?? ''));
                if ($areaHandle === '') {
                    continue;
                }
                if ($areaFilter !== '' && stripos($areaHandle, $areaFilter) === false) {
                    continue;
                }
                if (!isset($multiByArea[$areaHandle])) {
                    $multiByArea[$areaHandle] = [];
                }
                $multiByArea[$areaHandle][] = (int) $blockID;
            }

            $epraByArea = [];
            foreach ($epraPlacements as $blockID => $placement) {
                $areaHandle = trim((string) ($placement['arHandle'] ?? ''));
                if ($areaHandle === '') {
                    continue;
                }
                if (!isset($epraByArea[$areaHandle])) {
                    $epraByArea[$areaHandle] = [];
                }
                $epraByArea[$areaHandle][] = (int) $blockID;
            }

            $results = [];
            $updatedAreas = 0;
            $failedAreas = 0;
            $createdTargets = 0;

            foreach ($multiByArea as $areaHandle => $multiBlockIDs) {
                try {
                    $entries = [];
                    $skippedMultiBlocks = [];

                    foreach ($multiBlockIDs as $multiBlockID) {
                        $row = $this->getBlockTableRecord('btMulti', (int) $multiBlockID);
                        if (empty($row)) {
                            $skippedMultiBlocks[] = [
                                'blockID' => (int) $multiBlockID,
                                'reason' => 'No btMulti row found',
                            ];
                            continue;
                        }

                        $fileID = (int) ($row['link_File'] ?? 0);
                        if ($fileID <= 0) {
                            $skippedMultiBlocks[] = [
                                'blockID' => (int) $multiBlockID,
                                'reason' => 'Multi block does not contain a file link (link_File <= 0)',
                            ];
                            continue;
                        }

                        $title = trim((string) ($row['link_Title'] ?? ''));
                        if ($title === '') {
                            $title = 'Resource ' . $multiBlockID;
                        }

                        $entry = [
                            'Title' => $title,
                            'precis' => isset($row['description_1']) ? (string) $row['description_1'] : '',
                            'link' => 'file',
                            'link_Title' => $title,
                            'link_File' => $fileID,
                        ];

                        $imageID = (int) ($row['alternateImage'] ?? 0);
                        if ($imageID > 0) {
                            $entry['Image'] = $imageID;
                        }

                        $entries[] = $entry;
                    }

                    if (empty($entries)) {
                        $results[] = [
                            'areaHandle' => $areaHandle,
                            'ok' => false,
                            'reason' => 'No file-linked Multi blocks found in this area',
                            'multiBlocks' => $multiBlockIDs,
                            'skippedMultiBlocks' => $skippedMultiBlocks,
                        ];
                        $failedAreas++;
                        if (!$continueOnError) {
                            break;
                        }
                        continue;
                    }

                    $targetBlockID = null;
                    $createdTarget = false;
                    if (!empty($epraByArea[$areaHandle])) {
                        sort($epraByArea[$areaHandle]);
                        if ($resolvedOperationMode !== 'create_only') {
                            $targetBlockID = (int) $epraByArea[$areaHandle][0];
                        }
                    }

                    if ($targetBlockID === null && in_array($resolvedOperationMode, ['create_if_missing', 'create_only'], true)) {
                        $areaObject = Area::getOrCreate($pageToModify, $areaHandle);
                        $command = new AddBlockToPageCommand();
                        $command->setPage($pageToModify);
                        $command->setArea($areaObject);
                        $command->setBlockType($epraType);
                        $command->setData([
                            'heading' => '',
                            'headingStyle' => 'h2',
                            'description' => '',
                            'gridStructure' => 'col-12 col-md-3',
                            'bgColor' => 'bg-primary',
                            'slide' => [],
                        ]);
                        $createdBlock = Application::getFacadeApplication()->executeCommand($command);
                        $targetBlockID = $createdBlock ? (int) $createdBlock->getBlockID() : null;
                        $createdTarget = $targetBlockID !== null;
                    }

                    if ($targetBlockID === null && $resolvedOperationMode === 'create_only') {
                        throw new \RuntimeException("Failed to create EPRA Resources List block in area '{$areaHandle}'");
                    }

                    if (!$targetBlockID) {
                        $results[] = [
                            'areaHandle' => $areaHandle,
                            'ok' => false,
                            'reason' => $resolvedOperationMode === 'existing_only'
                                ? 'No EPRA Resources List block found in this area (operationMode=existing_only)'
                                : 'No EPRA Resources List block found in this area',
                            'multiBlocks' => $multiBlockIDs,
                            'skippedMultiBlocks' => $skippedMultiBlocks,
                        ];
                        $failedAreas++;
                        if (!$continueOnError) {
                            break;
                        }
                        continue;
                    }

                    $targetArea = $pageToModify->getArea($areaHandle);
                    if (!$targetArea) {
                        $targetArea = new Area($areaHandle);
                        $targetArea->c = $pageToModify;
                    }

                    $targetBlock = Block::getByID($targetBlockID, $pageToModify, $targetArea);
                    if (!$targetBlock) {
                        throw new \RuntimeException("Target EPRA block {$targetBlockID} could not be loaded in area '{$areaHandle}'");
                    }

                    $mergedEntries = $entries;
                    if (!$createdTarget && $resolvedEntryMode === 'append') {
                        $conn = Application::getFacadeApplication()->make('database')->connection();
                        $existingRows = $conn->executeQuery(
                            'SELECT id, Title, precis, link, link_Title, link_File, Image FROM btEpraResourcesListEntries WHERE bID = ? ORDER BY sortOrder',
                            [$targetBlockID]
                        )->fetchAllAssociative();

                        $existingEntries = [];
                        foreach ($existingRows as $existingRow) {
                            $existingEntry = [
                                'Title' => isset($existingRow['Title']) ? (string) $existingRow['Title'] : '',
                                'precis' => isset($existingRow['precis']) ? (string) $existingRow['precis'] : '',
                                'link' => isset($existingRow['link']) ? (string) $existingRow['link'] : '',
                                'link_Title' => isset($existingRow['link_Title']) ? (string) $existingRow['link_Title'] : '',
                                'link_File' => (int) ($existingRow['link_File'] ?? 0),
                            ];
                            $imageID = (int) ($existingRow['Image'] ?? 0);
                            if ($imageID > 0) {
                                $existingEntry['Image'] = $imageID;
                            }
                            $existingEntries[] = $existingEntry;
                        }

                        $seen = [];
                        $mergedEntries = [];
                        $candidateEntries = array_merge($existingEntries, $entries);
                        foreach ($candidateEntries as $candidate) {
                            $dedupeKey = sprintf(
                                '%s|%d|%s',
                                (string) ($candidate['link'] ?? ''),
                                (int) ($candidate['link_File'] ?? 0),
                                trim((string) ($candidate['Title'] ?? ''))
                            );
                            if (isset($seen[$dedupeKey])) {
                                continue;
                            }
                            $seen[$dedupeKey] = true;
                            $mergedEntries[] = $candidate;
                        }
                    }

                    $base = $this->getBlockTableRecord('btEpraResourcesList', $targetBlockID);
                    $payload = [
                        'heading' => isset($base['heading']) ? (string) $base['heading'] : '',
                        'headingStyle' => isset($base['headingStyle']) && is_string($base['headingStyle']) && $base['headingStyle'] !== '' ? (string) $base['headingStyle'] : 'h2',
                        'description' => isset($base['description']) ? (string) $base['description'] : '',
                        'gridStructure' => isset($base['gridStructure']) && is_string($base['gridStructure']) && $base['gridStructure'] !== '' ? (string) $base['gridStructure'] : 'col-12 col-md-3',
                        'bgColor' => isset($base['bgColor']) && is_string($base['bgColor']) && $base['bgColor'] !== '' ? (string) $base['bgColor'] : 'bg-primary',
                        'slide' => $mergedEntries,
                    ];

                    $targetBlock->update($payload);

                    $conn = Application::getFacadeApplication()->make('database')->connection();
                    $savedRows = $conn->executeQuery(
                        'SELECT id, Title, link, link_File FROM btEpraResourcesListEntries WHERE bID = ? ORDER BY sortOrder',
                        [$targetBlockID]
                    )->fetchAllAssociative();

                    $validFileRows = 0;
                    foreach ($savedRows as $savedRow) {
                        if ((string) ($savedRow['link'] ?? '') === 'file' && (int) ($savedRow['link_File'] ?? 0) > 0) {
                            $validFileRows++;
                        }
                    }

                    $expectedFileLinkedRows = 0;
                    foreach ($mergedEntries as $entry) {
                        if ((string) ($entry['link'] ?? '') === 'file' && (int) ($entry['link_File'] ?? 0) > 0) {
                            $expectedFileLinkedRows++;
                        }
                    }

                    if ($validFileRows < $expectedFileLinkedRows) {
                        throw new \RuntimeException('Post-save verification failed: not all entries have link=file and link_File > 0');
                    }

                    if ($createdTarget) {
                        $createdTargets++;
                    }

                    $results[] = [
                        'areaHandle' => $areaHandle,
                        'ok' => true,
                        'targetEpraBlockID' => $targetBlockID,
                        'targetAction' => $createdTarget ? 'created' : 'updated',
                        'entryMode' => $createdTarget ? 'replace' : $resolvedEntryMode,
                        'multiBlocks' => $multiBlockIDs,
                        'inputEntryCount' => count($entries),
                        'savedEntryCount' => count($mergedEntries),
                        'verifiedFileLinkedEntries' => $validFileRows,
                        'skippedMultiBlocks' => $skippedMultiBlocks,
                    ];
                    $updatedAreas++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'areaHandle' => $areaHandle,
                        'ok' => false,
                        'reason' => $e->getMessage(),
                        'multiBlocks' => $multiBlockIDs,
                    ];
                    $failedAreas++;
                    if (!$continueOnError) {
                        break;
                    }
                }
            }

            return [
                'page' => [
                    'id' => $pageToModify->getCollectionID(),
                    'path' => $pageToModify->getCollectionPath(),
                    'name' => $pageToModify->getCollectionName(),
                    'versionID' => $pageToModify->getVersionID(),
                ],
                'summary' => [
                    'areasWithMultiBlocks' => count($multiByArea),
                    'updatedAreas' => $updatedAreas,
                    'createdTargets' => $createdTargets,
                    'failedAreas' => $failedAreas,
                    'includeInactive' => (bool) $includeInactive,
                    'operationMode' => $resolvedOperationMode,
                    'entryMode' => $resolvedEntryMode,
                    'areaHandleContains' => $areaFilter !== '' ? $areaFilter : null,
                    'createMissingTarget' => (bool) $createMissingTarget,
                    'continueOnError' => (bool) $continueOnError,
                ],
                'results' => $results,
                'note' => 'Synced Multi file links to EPRA resources per area with explicit target and entry modes, including post-save file-link verification.',
            ];
        }));

        return $tool;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectPreferredPlacementsForBlockType(int $pageID, int $blockTypeID, bool $includeInactive = true): array
    {
        $conn = Application::getFacadeApplication()->make('database')->connection();
        $qb = $conn->createQueryBuilder();
        $qb->select('b.bID', 'cvb.arHandle', 'cvb.cvID', 'cv.cvIsApproved')
            ->from('Blocks', 'b')
            ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
            ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
            ->where('cvb.cID = :cID')
            ->andWhere('b.btID = :btID')
            ->setParameter('cID', $pageID)
            ->setParameter('btID', $blockTypeID)
            ->orderBy('b.bID', 'ASC')
            ->addOrderBy('cv.cvIsApproved', 'DESC')
            ->addOrderBy('cvb.cvID', 'DESC');

        if (!$includeInactive) {
            $qb->andWhere('cv.cvIsApproved = 1');
        }

        $rows = $qb->execute()->fetchAllAssociative();
        $preferred = [];
        foreach ($rows as $row) {
            $bID = (int) ($row['bID'] ?? 0);
            if ($bID <= 0) {
                continue;
            }

            if (!isset($preferred[$bID])) {
                $preferred[$bID] = $row;
                continue;
            }

            $current = $preferred[$bID];
            $currentApproved = (int) ($current['cvIsApproved'] ?? 0) === 1;
            $candidateApproved = (int) ($row['cvIsApproved'] ?? 0) === 1;
            if ($candidateApproved && !$currentApproved) {
                $preferred[$bID] = $row;
                continue;
            }
            if ($candidateApproved === $currentApproved && (int) ($row['cvID'] ?? 0) > (int) ($current['cvID'] ?? 0)) {
                $preferred[$bID] = $row;
            }
        }

        ksort($preferred);
        return $preferred;
    }

    protected function makeCreatePageToolInternal(): Tool
    {
        $tool = new CallableTool(
            'create_page',
            'Create a new page in Concrete CMS. The page is created unpublished: it has no approved version, so visitors'
            . ' cannot see it until a person publishes it from Page Versions in the CMS. This assistant cannot publish.'
        );

        $tool->addProperty(new ToolProperty(
            'parentID',
            PropertyType::INTEGER,
            'Parent page ID where the new page will be created',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Page name/title',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'pageType',
            PropertyType::STRING,
            'Page type handle (default: "page")',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'template',
            PropertyType::STRING,
            'Page template handle (default: "full")',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'description',
            PropertyType::STRING,
            'Page description',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'attributes',
            PropertyType::OBJECT,
            'Optional page attributes map keyed by attribute handle',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $parentID = null,
            ?string $name = null,
            ?string $pageType = 'page',
            ?string $template = 'full',
            ?string $description = null,
            ?array $attributes = null
        ): array {
            if (!$parentID || !$name) {
                throw new \RuntimeException("parentID and name are required");
            }
            
            // Default to 'page' type if not specified or empty
            if (empty($pageType)) {
                $pageType = 'page';
            }

            if ($template === null || trim($template) === '') {
                $template = 'full';
            }
            
            $parent = Page::getByID($parentID);
            
            if (!$parent || $parent->isError()) {
                throw new \RuntimeException("Parent page with ID {$parentID} not found");
            }
            SystemPageGuard::refuse($parent, 'parent page');
            
            // Check permissions
            $checker = new Checker($parent);

            $pt = PageType::getByHandle($pageType);
            if (!$pt) {
                throw new \RuntimeException("Page type '{$pageType}' not found");
            }

            $pageTemplate = Template::getByHandle((string) $template);
            if (!$pageTemplate) {
                throw new \RuntimeException("Page template '{$template}' not found");
            }

            if (!$checker->canAddSubCollection($pt)) {
                throw new \RuntimeException("Permission denied: Cannot add pages of type '{$pageType}' under parent {$parentID}");
            }
            
            // No approved version: the page is not live until a person approves it from Page Versions (P1.13, B-WRT-3).
            $data = ['name' => $name, 'cvIsApproved' => 0];
            if ($description) {
                $data['description'] = $description;
            }
            
            // Refuse an unknown handle and build the attribute values before the page exists, as update_page does (BF-3).
            $attributeMap = null;
            if (!empty($attributes)) {
                foreach (array_keys($attributes) as $handle) {
                    if (!CollectionKey::getByHandle((string) $handle)) {
                        throw new \RuntimeException("'{$handle}' is not a page attribute handle, so nothing was changed. Use list_page_attributes to see the handles.");
                    }
                }
                $category = Application::getFacadeApplication()->make(PageCategory::class);
                $attributeValueMapFactory = Application::getFacadeApplication()->make(AttributeValueMapFactory::class);
                $attributeMap = $attributeValueMapFactory->createFromRequestData($category, $attributes);
            }

            $page = $parent->add($pt, $data, $pageTemplate);
            // If the call fails from here on, the page it made is deleted under DraftCleanup's rule (BF-3).
            DraftCleanup::createdPage($page);

            if ($attributeMap !== null) {
                foreach ($attributeMap->getEntries() as $entry) {
                    $page->setAttribute($entry->getAttributeKey(), $entry->getAttributeValue());
                    DraftCleanup::ownChange($page);
                }
            }
            
            return $this->serializePage($page) + DraftState::ofNewPage($page) + [
                'published' => false,
                'note' => 'The page is unpublished: visitors cannot see it. A person publishes it from Page Versions in the CMS;'
                    . ' this assistant cannot publish.',
            ];
        }));

        return $tool;
    }

    /**
     * @param string $content
     * @param array<string, string> $pendingHeadings
     * @return array{key:string,text:string}|null
     */
    /**
     * Every pending heading whose text is in the block (P3.3-1: not only the first).
     *
     * @return array<string, array{key: string, text: string}>
     */
    private function findMatchingHeadingTextsInContent(string $content, array $pendingHeadings): array
    {
        $plain = $this->normalizeHeadingLookupText(TextIntegrity::normalize($content));
        $found = [];
        foreach ($pendingHeadings as $key => $text) {
            $key = (string) $key;
            if ($key !== '' && strpos($plain, $key) !== false) {
                $found[$key] = ['key' => $key, 'text' => $text];
            }
        }

        return $found;
    }

    /**
     * The text of every bold paragraph (<p><strong>…</strong></p> or <b>) in the block, in order (P3.3-1: not only the first).
     *
     * @return list<string>
     */
    private function extractBoldParagraphHeadingTexts(string $html): array
    {
        $pattern = '~<p\b[^>]*>\s*(?:<strong\b[^>]*>|<b\b[^>]*>)\s*(.*?)\s*(?:</strong>|</b>)\s*</p>~isu';
        if (!preg_match_all($pattern, $html, $matches)) {
            return [];
        }

        $texts = [];
        foreach ($matches[1] as $candidate) {
            $candidate = html_entity_decode(strip_tags((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate) ?: '');
            if ($candidate !== '') {
                $texts[] = $candidate;
            }
        }

        return $texts;
    }

    private function normalizeHeadingLookupText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        return trim($value);
    }

    private function applyHeadingLevelToContent(string $html, string $headingText, string $targetTag): string
    {
        $escaped = preg_quote($headingText, '~');

        // Existing heading tag with exact heading text.
        $headingPattern = '~<h([1-6])\b([^>]*)>\s*' . $escaped . '\s*</h\1>~iu';
        $updated = preg_replace_callback($headingPattern, function (array $matches) use ($targetTag, $headingText): string {
            $attrs = isset($matches[2]) ? (string) $matches[2] : '';
            return '<' . $targetTag . $attrs . '>' . $headingText . '</' . $targetTag . '>';
        }, $html, -1, $count); // every heading with this text in the block (P3.3-1)

        if (is_string($updated) && $count > 0) {
            return $updated;
        }

        // Bold paragraph heading: <p><strong>Title</strong></p> or <p><b>Title</b></p>
        $boldParagraphPattern = '~<p\b[^>]*>\s*(?:<strong\b[^>]*>|<b\b[^>]*>)\s*' . $escaped . '\s*(?:</strong>|</b>)\s*</p>~iu';
        $updated = preg_replace($boldParagraphPattern, '<' . $targetTag . '>' . $headingText . '</' . $targetTag . '>', $html, -1, $count);
        if (is_string($updated) && $count > 0) {
            return $updated;
        }

        return $html;
    }

    /**
     * P1.13: with move_page disabled the model tried to move a page through update_page, with a parent or path key
     * the tool silently dropped, and then said the page had moved. These are refused with a message that says so.
     */
    public const MOVE_UNAVAILABLE_MESSAGE = "Moving pages isn't available yet. It will return with approvals.";

    private static function looksLikeAMoveKey(string $key): bool
    {
        return (bool) preg_match('/parent|path|destination|move|location/i', $key);
    }

    protected function makeUpdatePageTool(): Tool
    {
        $tool = (new StrictInputTool(
            'update_page',
            'Update an existing page\'s name, description or template, or set page attributes by handle. It cannot move a page: '
            . 'moving pages is not available yet. Only the listed parameters are accepted; anything else is refused and nothing is changed.'
        ))->refuseUnknownInputsWith(function (array $unknown): ?string {
            foreach ($unknown as $key) {
                if (self::looksLikeAMoveKey($key)) {
                    return self::MOVE_UNAVAILABLE_MESSAGE;
                }
            }

            return null;
        });

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to update',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'New page name/title',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'description',
            PropertyType::STRING,
            'New page description',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'pageType',
            PropertyType::STRING,
            'Not supported: a page type can only be changed by a person in the CMS. A different value is refused; the page'
            . ' type the page already has is accepted and ignored.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'template',
            PropertyType::STRING,
            'New page template handle',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'attributes',
            PropertyType::OBJECT,
            'Optional page attributes map keyed by an existing page attribute handle. An unknown handle is refused and nothing is changed. Not a way to move a page.',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $name = null,
            ?string $description = null,
            ?string $pageType = null,
            ?string $template = null,
            ?array $attributes = null
        ): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }
            $page = Page::getByID($pageID, 'RECENT');
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            
            // Check permissions
            $checker = new Checker($page);
            if (!$checker->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}");
            }

            // Page::update() writes the page type (Pages.ptID) straight to the live page: it isn't
            // part of a page version, so it can't go to a draft or be undone with version history.
            if ($pageType !== null && trim($pageType) !== '' && trim($pageType) !== (string) $page->getPageTypeHandle()) {
                throw new \RuntimeException(
                    "Changing a page's type isn't possible with this assistant: the change would be live at once and "
                    . 'could not be undone from the page versions. A person must change the page type in the CMS.'
                );
            }

            $targetTemplate = null;
            if ($template !== null && trim($template) !== '') {
                $targetTemplate = Template::getByHandle($template);
                if (!$targetTemplate) {
                    throw new \RuntimeException("Page template '{$template}' not found");
                }
            }

            // Refuse a handle that is not a page attribute before a draft is made: the factory below would drop it
            // without a word, and the reply would then claim a change that never happened.
            foreach (array_keys($attributes ?? []) as $handle) {
                if (CollectionKey::getByHandle((string) $handle)) {
                    continue;
                }
                throw new \RuntimeException(
                    self::looksLikeAMoveKey((string) $handle)
                        ? self::MOVE_UNAVAILABLE_MESSAGE
                        : "'{$handle}' is not a page attribute handle, so nothing was changed. Use list_page_attributes to see the handles."
                );
            }

            // Nothing to change makes no draft (BF-3).
            if ($name === null && $description === null && !$targetTemplate && empty($attributes)) {
                throw new \RuntimeException('No change was given: pass name, description, template or attributes. Nothing was changed.');
            }

            // Build the attribute values before the draft, so a bad value leaves nothing behind (BF-3).
            $attributeMap = null;
            if (!empty($attributes)) {
                $category = Application::getFacadeApplication()->make(PageCategory::class);
                $attributeValueMapFactory = Application::getFacadeApplication()->make(AttributeValueMapFactory::class);
                $attributeMap = $attributeValueMapFactory->createFromRequestData($category, $attributes);
            }

            $pageToModify = $this->getDraftOf($page);

            $data = [];
            if ($name !== null) {
                $data['cName'] = $name;
            }
            if ($description !== null) {
                $data['cDescription'] = $description;
            }
            if ($targetTemplate) {
                $data['pTemplateID'] = $targetTemplate->getPageTemplateID();
            }
            
            if (!empty($data)) {
                $pageToModify->update($data);
            }

            if ($attributeMap !== null) {
                foreach ($attributeMap->getEntries() as $entry) {
                    $pageToModify->setAttribute($entry->getAttributeKey(), $entry->getAttributeValue());
                }
            }

            return $this->serializePage($pageToModify) + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    protected function makeMovePageTool(): Tool
    {
        $tool = new CallableTool(
            'move_page',
            'Move a page to a new parent location in the sitemap'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to move',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'newParentID',
            PropertyType::INTEGER,
            'ID of the new parent page',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?int $newParentID = null
        ): array {
            if (!$pageID || !$newParentID) {
                throw new \RuntimeException("pageID and newParentID are required");
            }
            
            $page = Page::getByID($pageID);
            if (!$page || $page->isError() || !($page instanceof Page)) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            
            $newParent = Page::getByID($newParentID);
            if (!$newParent || $newParent->isError()) {
                throw new \RuntimeException("New parent page with ID {$newParentID} not found");
            }
            SystemPageGuard::refuse($newParent, 'new parent page');
            
            // Check permissions on both pages
            $pageChecker = new Checker($page);
            if (!$pageChecker->canMoveOrCopyPage()) {
                throw new \RuntimeException("Permission denied: Cannot move page {$pageID}");
            }
            
            $parentChecker = new Checker($newParent);
            if (!$parentChecker->canAddSubpage()) {
                throw new \RuntimeException("Permission denied: Cannot add subpage to parent {$newParentID}");
            }
            
            // Move the page
            $page->move($newParent);
            
            return $this->serializePage($page);
        }));

        return $tool;
    }

    protected function makeDeletePageTool(): Tool
    {
        $tool = new CallableTool(
            'delete_page',
            'Delete a page (moves to trash)'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to delete',
            true
        ));

        $tool->setHandler($this->guarded(function (int $pageID): array {
            $page = Page::getByID($pageID, 'ACTIVE');
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);

            if ($page->isInTrash()) {
                throw new \RuntimeException('This page is pending deletion.');
            }
            
            // Check permissions
            $checker = new Checker($page);
            if (!$checker->canDeletePage()) {
                throw new \RuntimeException("Permission denied: Cannot delete page {$pageID}");
            }

            $user = Application::getFacadeApplication()->make(User::class);
            $command = new DeletePageCommand($page->getCollectionID(), $user->getUserID());
            Application::getFacadeApplication()->executeCommand($command);
            
            return [
                'id' => $pageID,
                'object' => 'pages',
                'deleted' => true
            ];
        }));

        return $tool;
    }

    protected function makeGetPageChildrenTool(): Tool
    {
        $tool = new CallableTool(
            'get_page_children',
            'Get the child pages of a specific page. Provide either pageID or parentPath. '
            . 'The result gives the total and whether there are more; use offset for the next children.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'Parent page ID (use this OR parentPath)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'parentPath',
            PropertyType::STRING,
            'Parent page path (e.g., "/newsroom/press-releases"). Use this OR pageID.',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of children to return (1-100, default 100)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $parentPath = null, ?int $limit = 100, ?int $offset = null): array {
            $window = ListWindow::of($limit, $offset, 100, 100);
            if (!$pageID && !$parentPath) {
                throw new \RuntimeException("Either pageID or parentPath is required");
            }
            
            // Resolve the parent page
            if ($parentPath) {
                $page = Page::getByPath($parentPath);
                if (!$page || $page->isError()) {
                    throw new \RuntimeException("Parent page not found at path: {$parentPath}");
                }
            } else {
                $page = Page::getByID($pageID);
                if (!$page || $page->isError()) {
                    throw new \RuntimeException("Page with ID {$pageID} not found");
                }
            }

            $parentPermissions = new Checker($page);
            if (!$parentPermissions->canViewPage()) {
                throw new \RuntimeException('Permission denied: Cannot view the specified parent page.');
            }
            
            $list = new PageList();
            $list->filterByParentID($page->getCollectionID());
            $list->sortByDisplayOrder();
            $list->getQueryObject()->addOrderBy('p.cID', 'asc');
            $list->setPermissionsChecker(function ($childPage) {
                $permissions = new Checker($childPage);
                return $permissions->canViewPage();
            });

            // BF-6: every permitted child is loaded to count them (as in list_pages); the window is sliced from them.
            $children = $list->getResults();
            $shown = array_map(fn($child) => $this->serializePage($child), $window->slice($children));

            return [
                'parent' => $this->serializePage($page),
                'children' => $shown,
            ] + $window->summary(count($children), count($shown), 'get_page_children');
        }));

        return $tool;
    }

    protected function makeAddBlockToPageAreaTool(): Tool
    {
        $tool = new CallableTool(
            'add_block_to_page_area',
            'Add a new block to a specific page area. '
            . 'blockType must be the handle of the block type the user asked for. Never substitute a different type '
            . '(for example, do not use "content" when the user asked for an HTML block). '
            . 'Common handles: '
            . 'content = rich text (formatted text, headings, paragraphs); '
            . 'html = raw HTML, for markup that must be kept exactly as written; '
            . 'image = a single image; '
            . 'file = a link to a downloadable file; '
            . 'page_list = a list of pages; '
            . 'autonav = a navigation menu; '
            . 'youtube or video = an embedded video; '
            . 'google_map = a map; '
            . 'form = a Concrete form; '
            . 'accordion, faq, feature = structured content blocks. '
            . 'If the requested type does not exist or you are unsure of its handle, say so instead of choosing another.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page that contains the target area',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Handle of the area (for example: "Main")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockType',
            PropertyType::STRING,
            'Handle of the block type the user asked for, used exactly as requested '
            . '("content" = rich text, "html" = raw HTML, "image", "file", "page_list", ...). Do not pick a different type.',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'value',
            PropertyType::OBJECT,
            'Block data payload matching the block type fields',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?string $blockType = null,
            ?array $value = []
        ): array {
            if (!$pageID || !$areaHandle || !$blockType) {
                throw new \RuntimeException('pageID, areaHandle, and blockType are required');
            }

            $page = Page::getByID($pageID);
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $areaHandle);

            $bt = BlockType::getByHandle($blockType);
            if (!$bt) {
                throw new \RuntimeException("Invalid block type handle '{$blockType}'");
            }

            $normalizedValue = $this->normalizeCustomBlockPayload((string) $blockType, $value ?? []);
            // No escaped or broken HTML in a new block either (BF-7, browser check 6).
            $valueProblem = TextIntegrity::checkNewValue($normalizedValue);
            if ($valueProblem !== null) {
                throw new \RuntimeException($valueProblem);
            }

            /** @var mixed $pageForArea */
            $pageForArea = $page;
            $area = Area::getOrCreate($pageForArea, $areaHandle);
            $checker = new Checker($area);
            if (!$checker->canAddBlock($bt)) {
                throw new \RuntimeException("Permission denied: Cannot add '{$blockType}' blocks to area '{$areaHandle}' on page {$pageID}");
            }

            // The command makes its own draft from the page it is given. Give it the draft, so a
            // second write in this request adds to the same draft (see PageDraft::of()).
            $draft = $this->getDraftOf($page);
            $command = new AddBlockToPageCommand();
            $command->setPage($draft);
            $command->setArea($area);
            $command->setBlockType($bt);
            $command->setData($normalizedValue);

            $block = Application::getFacadeApplication()->executeCommand($command);

            return $this->serializeBlock($block, true) + DraftState::of($draft);
        }));

        return $tool;
    }

    protected function makeUpdateBlockInPageAreaTool(): Tool
    {
        $tool = new CallableTool(
            'update_block_in_page_area',
            'Update an existing block in a specific page area, in a draft. Existing text is kept unless changeMode says otherwise, and fields left out of value keep their stored values. Read the full block with get_block_data first.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page that contains the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Handle of the area containing the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'ID of the block to update',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'value',
            PropertyType::OBJECT,
            'Updated block data payload',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'changeMode',
            PropertyType::STRING,
            'Text safety mode: "keep_text" (default: existing text must stay; text may be added), "formatting_only" (text must remain identical), or "scoped_text" (text may change only in textFields, and stored text may go only if it is listed in replacedText)',
            false
        ));

        $tool->addProperty(new ArrayProperty(
            'textFields',
            'With changeMode "scoped_text": the fields whose text may change, e.g. ["content"]',
            false,
            new ToolProperty('field', PropertyType::STRING, 'A field name of the block', true)
        ));

        $tool->addProperty(new ArrayProperty(
            'replacedText',
            'With changeMode "scoped_text": the stored text the change replaces or removes, word for word, one string per paragraph or heading',
            false,
            new ToolProperty('text', PropertyType::STRING, 'Stored text, as get_block_data returns it', true)
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?int $blockID = null,
            ?array $value = [],
            ?string $changeMode = null,
            ?array $textFields = null,
            ?array $replacedText = null
        ): array {
            if (!$pageID || !$areaHandle || !$blockID) {
                throw new \RuntimeException('pageID, areaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $areaHandle);
            EditModeGuard::refuseOpenBlock($page, $blockID);

            try {
                [$area, $block] = $this->getBlockToWorkWith($page, $areaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                // Look again on the newest version, as a plain lookup. This must not make a draft:
                // a block that isn't there is an error, and an error must leave no draft behind.
                $fallbackArea = $page->getArea($areaHandle);
                if (!$fallbackArea) {
                    $fallbackArea = new Area($areaHandle);
                    $fallbackArea->c = $page;
                }
                $fallbackBlock = Block::getByID($blockID, $page, $fallbackArea);
                if (!$fallbackBlock) {
                    // Where the block is instead, and the ID to use (BF-7, browser check 6).
                    throw new \RuntimeException(BlockNotFound::explain($page, $areaHandle, $blockID));
                }
                $area = $fallbackArea;
                $block = $fallbackBlock;
            }

            $checker = new Checker($block);
            if (!$checker->canEditBlock()) {
                throw new \RuntimeException("Permission denied: Cannot edit block {$blockID} on page {$pageID}");
            }

            $body = $this->normalizeCustomBlockPayload($block->getBlockTypeHandle(), (array) ($value ?? []));
            if (in_array($block->getBlockTypeHandle(), self::EPRA_PAYLOAD_BLOCK_HANDLES, true) && !array_key_exists('slide', $body)) {
                // Preserve existing repeatable rows for layout-only updates (e.g., gridStructure changes).
                $body['slide'] = $this->loadEpraResourceEntries((int) $blockID);
            }

            // The text check, on the block as it is stored now, before any draft exists (BF-5): a refusal changes nothing.
            $mode = TextIntegrity::mode($changeMode);
            $controller = $block->getController();
            $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
            $beforeRecord = $this->getBlockTableRecord($dbTable, (int) $block->getBlockID());
            $kept = TextIntegrity::keepOmittedFields($beforeRecord, $body);
            $body = $kept['value'];
            $textPolicy = TextIntegrity::evaluate($beforeRecord, $body, $mode, $textFields, $replacedText);
            if (!$textPolicy['ok']) {
                throw new \RuntimeException($textPolicy['message']);
            }

            $validation = $this->validateBlock($block, $body);
            if ($validation instanceof JsonResponse) {
                $validationBody = (string) $validation->getContent();
                throw new \RuntimeException("Block validation failed: {$validationBody}");
            }

            // Make the draft only now that the checks have passed. If it exists already this
            // returns it, so getBlockToEdit doesn't make a second one.
            $pageToModify = $this->getDraftOf($page);
            $blockToEdit = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $blockID);

            $command = new UpdatePageBlockCommand();
            $command->setPage($pageToModify);
            $command->setData($body);
            $command->setBlock($blockToEdit);

            $updatedBlock = Application::getFacadeApplication()->executeCommand($command);
            $blockToEdit->update($body);

            $afterRecord = $this->getBlockTableRecord($dbTable, (int) $updatedBlock->getBlockID());

            return $this->serializeBlock($updatedBlock, true) + [
                'textCheck' => [
                    'mode' => $mode,
                    'fields' => $textPolicy['fields'],
                    'keptFields' => $kept['keptFields'],
                    'checksumsBefore' => TextIntegrity::checksums($beforeRecord, $textPolicy['watchedFields']),
                    'checksumsAfter' => TextIntegrity::checksums($afterRecord, $textPolicy['watchedFields']),
                ],
            ] + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    private function normalizeCustomBlockPayload(string $blockTypeHandle, array $payload): array
    {
        if ($payload === [] || !in_array($blockTypeHandle, self::EPRA_PAYLOAD_BLOCK_HANDLES, true)) {
            return $payload;
        }

        $normalized = $payload;
        $hasSlide = array_key_exists('slide', $normalized) && is_array($normalized['slide']);
        $aliasCandidates = ['entries', 'items', 'resources', 'slideItems', 'resourceItems', 'rows', 'slides', 'children', 'listItems'];

        if (!$hasSlide) {
            foreach ($aliasCandidates as $alias) {
                if (isset($normalized[$alias]) && is_array($normalized[$alias])) {
                    $normalized['slide'] = $this->normalizeRepeatableBlockRows($normalized[$alias]);
                    unset($normalized[$alias]);
                    break;
                }
            }
        }

        if (isset($normalized['slide']) && is_array($normalized['slide'])) {
            $normalized['slide'] = $this->normalizeRepeatableBlockRows($normalized['slide']);
        }

        $normalized = $this->normalizeBlockFieldAliasesRecursive($normalized);

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEpraResourceEntries(int $blockID): array
    {
        $conn = Application::getFacadeApplication()->make('database')->connection();
        $rows = $conn->executeQuery(
            'SELECT Title, precis, link, link_Title, link_URL, link_Page, link_File, Image FROM btEpraResourcesListEntries WHERE bID = ? ORDER BY sortOrder',
            [$blockID]
        )->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $entry = [
                'Title' => isset($row['Title']) ? (string) $row['Title'] : '',
                'precis' => isset($row['precis']) ? (string) $row['precis'] : '',
                'link' => isset($row['link']) ? (string) $row['link'] : '',
                'link_Title' => isset($row['link_Title']) ? (string) $row['link_Title'] : '',
                'link_URL' => isset($row['link_URL']) ? (string) $row['link_URL'] : '',
                'link_Page' => (int) ($row['link_Page'] ?? 0),
                'link_File' => (int) ($row['link_File'] ?? 0),
            ];

            $imageID = (int) ($row['Image'] ?? 0);
            if ($imageID > 0) {
                $entry['Image'] = $imageID;
            }

            $entries[] = $entry;
        }

        return $this->normalizeRepeatableBlockRows($entries);
    }

    private function normalizeRepeatableBlockRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[$index] = $this->normalizeBlockFieldAliasesRecursive($row);
            $rows[$index] = $this->normalizeRepeatableLinkedRecord($rows[$index]);
            $rows[$index] = $this->inferRepeatableLinkContract($rows[$index]);
        }

        return $rows;
    }

    private function normalizeRepeatableLinkedRecord(array $row): array
    {
        if (isset($row['resolvedLink']) && is_array($row['resolvedLink'])) {
            $resolvedLink = $this->normalizeBlockFieldAliasesRecursive($row['resolvedLink']);

            if (!isset($row['link']) && isset($resolvedLink['link'])) {
                $row['link'] = $resolvedLink['link'];
            }

            if (!isset($row['link_Title']) && isset($resolvedLink['link_Title'])) {
                $row['link_Title'] = $resolvedLink['link_Title'];
            }

            if (!isset($row['link_URL']) && isset($resolvedLink['link_URL'])) {
                $row['link_URL'] = $resolvedLink['link_URL'];
            }

            if (!isset($row['link_Page']) && isset($resolvedLink['page'])) {
                $resolvedPageId = $this->extractNestedIdentifier($resolvedLink['page'], ['cID', 'pageID', 'id']);
                if ($resolvedPageId !== null) {
                    $row['link_Page'] = $resolvedPageId;
                }
            }

            if (!isset($row['link_File']) && isset($resolvedLink['file'])) {
                $resolvedFileId = $this->extractNestedIdentifier($resolvedLink['file'], ['fID', 'fileID', 'id']);
                if ($resolvedFileId !== null) {
                    $row['link_File'] = $resolvedFileId;
                }
            }

            if (!isset($row['link_Document_ID']) && isset($resolvedLink['document'])) {
                $resolvedDocumentId = $this->extractNestedIdentifier($resolvedLink['document'], ['id', 'documentId', 'value']);
                if ($resolvedDocumentId !== null) {
                    $row['link_Document_ID'] = $resolvedDocumentId;
                }
            }
        }

        if (!isset($row['link_File']) && isset($row['file'])) {
            $fileId = $this->extractNestedIdentifier($row['file'], ['fID', 'fileID', 'id']);
            if ($fileId !== null) {
                $row['link_File'] = $fileId;
            }
        }

        if (!isset($row['link_Page']) && isset($row['page'])) {
            $pageId = $this->extractNestedIdentifier($row['page'], ['cID', 'pageID', 'id']);
            if ($pageId !== null) {
                $row['link_Page'] = $pageId;
            }
        }

        if (!isset($row['link_Document_ID']) && isset($row['document'])) {
            $documentId = $this->extractNestedIdentifier($row['document'], ['id', 'documentId', 'value']);
            if ($documentId !== null) {
                $row['link_Document_ID'] = $documentId;
            }
        }

        if (!isset($row['Image']) && isset($row['image'])) {
            $imageId = $this->extractNestedIdentifier($row['image'], ['fID', 'fileID', 'id']);
            if ($imageId !== null) {
                $row['Image'] = $imageId;
            }
        }

        if (($row['link'] ?? null) === 'file' && empty($row['link_Title'] ?? '') && isset($row['file']) && is_array($row['file'])) {
            $row['link_Title'] = (string) ($row['file']['title'] ?? $row['file']['filename'] ?? '');
        }

        if (($row['link'] ?? null) === 'page' && empty($row['link_Title'] ?? '') && isset($row['page']) && is_array($row['page'])) {
            $row['link_Title'] = (string) ($row['page']['name'] ?? $row['page']['title'] ?? '');
        }

        if (($row['link'] ?? null) === 'document_api' && empty($row['link_Title'] ?? '') && isset($row['document']) && is_array($row['document'])) {
            $row['link_Title'] = (string) ($row['document']['title'] ?? '');
        }

        return $row;
    }

    private function normalizeBlockFieldAliasesRecursive(array $value): array
    {
        $aliases = [
            'title' => 'Title',
            'name' => 'Title',
            'heading' => 'Title',
            'description' => 'precis',
            'summary' => 'precis',
            'link_title' => 'link_Title',
            'linkTitle' => 'link_Title',
            'link_url' => 'link_URL',
            'linkUrl' => 'link_URL',
            'link_relative_url' => 'link_Relative_URL',
            'linkRelativeUrl' => 'link_Relative_URL',
            'document_id' => 'link_Document_ID',
            'documentId' => 'link_Document_ID',
            'document_title' => 'link_Document_Title',
            'documentTitle' => 'link_Document_Title',
            'page_id' => 'link_Page',
            'pageId' => 'link_Page',
            'file_id' => 'link_File',
            'fileId' => 'link_File',
            'image_id' => 'Image',
            'imageId' => 'Image',
            'link_type' => 'link',
            'linkType' => 'link',
        ];

        foreach ($aliases as $alias => $canonical) {
            if (!array_key_exists($alias, $value)) {
                continue;
            }
            if (!array_key_exists($canonical, $value)) {
                $value[$canonical] = $value[$alias];
            }
            unset($value[$alias]);
        }

        foreach ($value as $key => $subValue) {
            if (is_array($subValue)) {
                $value[$key] = $this->normalizeBlockFieldAliasesRecursive($subValue);
            }
        }

        return $value;
    }

    private function inferRepeatableLinkContract(array $row): array
    {
        if (isset($row['link']) && is_string($row['link'])) {
            $link = strtolower(trim((string) $row['link']));
            $link = str_replace([' ', '-'], '_', $link);
            $link = preg_replace('/_+/', '_', $link) ?? $link;
            $row['link'] = $link;
        }

        if (empty($row['link'] ?? '') && !empty($row['link_URL'] ?? '')) {
            $row['link'] = 'url';
        } elseif (empty($row['link'] ?? '') && !empty($row['link_Document_ID'] ?? 0)) {
            $row['link'] = 'document_api';
        } elseif (empty($row['link'] ?? '') && !empty($row['link_Page'] ?? 0)) {
            $row['link'] = 'page';
        } elseif (empty($row['link'] ?? '') && !empty($row['link_File'] ?? 0)) {
            $row['link'] = 'file';
        } elseif (empty($row['link'] ?? '') && !empty($row['link_Relative_URL'] ?? '')) {
            $row['link'] = 'relative_url';
        }

        if (($row['link'] ?? '') === 'document-api') {
            $row['link'] = 'document_api';
        }

        if (($row['link'] ?? '') === 'relative_url' && !isset($row['link_Relative_URL']) && isset($row['url'])) {
            $row['link_Relative_URL'] = $row['url'];
        }

        return $row;
    }

    private function extractNestedIdentifier(mixed $value, array $candidateKeys): ?int
    {
        if (is_numeric($value)) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($candidateKeys as $candidateKey) {
            if (!array_key_exists($candidateKey, $value) || !is_numeric($value[$candidateKey])) {
                continue;
            }

            $id = (int) $value[$candidateKey];
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    protected function makeDeleteBlockFromPageAreaTool(): Tool
    {
        $tool = new CallableTool(
            'delete_block_from_page_area',
            'Delete a block from a specific page area'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page that contains the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Handle of the area containing the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'ID of the block to delete',
            true
        ));

        $tool->setHandler($this->guarded(function (?int $pageID = null, ?string $areaHandle = null, ?int $blockID = null): array {
            if (!$pageID || !$areaHandle || !$blockID) {
                throw new \RuntimeException('pageID, areaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $areaHandle);
            EditModeGuard::refuseOpenBlock($page, $blockID);

            try {
                [$area, $block] = $this->getBlockToWorkWith($page, $areaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                throw new \RuntimeException(BlockNotFound::explain($page, $areaHandle, $blockID));
            }

            $checker = new Checker($block);
            if (!$checker->canDeleteBlock()) {
                throw new \RuntimeException("Permission denied: Cannot delete block {$blockID} on page {$pageID}");
            }

            // Delete in a draft, never in the version the page may be showing. The block stays
            // in the live version, and discarding the draft restores the page.
            $pageToModify = $this->getDraftOf($page);

            $command = new DeleteBlockCommand(
                $block->getBlockID(),
                $pageToModify->getCollectionID(),
                $pageToModify->getVersionID(),
                $area->getAreaHandle()
            );

            // Report only what happened (BF-2): the command does nothing, silently, when the draft has no such block.
            $placements = fn (): int => (int) Application::getFacadeApplication()->make('database')->connection()->fetchOne(
                'SELECT COUNT(*) FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? AND bID = ?',
                [$pageToModify->getCollectionID(), $pageToModify->getVersionID(), $block->getBlockID()]
            );
            $before = $placements();
            Application::getFacadeApplication()->executeCommand($command);
            if ($before === 0 || $placements() > 0) {
                throw new \RuntimeException("Block {$blockID} was not deleted from page {$pageID}: it is not in the draft being edited.");
            }

            return [
                'pageID' => $pageID,
                'areaHandle' => $areaHandle,
                'blockID' => $blockID,
                'deleted' => true,
            ] + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    protected function makeMoveBlockBetweenAreasTool(): Tool
    {
        $tool = new CallableTool(
            'move_block_between_areas',
            'Move an existing block to a different area on the same page'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page containing the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'fromAreaHandle',
            PropertyType::STRING,
            'Current area handle for the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'toAreaHandle',
            PropertyType::STRING,
            'Destination area handle for the block',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'blockID',
            PropertyType::INTEGER,
            'ID of the block to move',
            true
        ));

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $fromAreaHandle = null,
            ?string $toAreaHandle = null,
            ?int $blockID = null
        ): array {
            if (!$pageID || !$fromAreaHandle || !$toAreaHandle || !$blockID) {
                throw new \RuntimeException('pageID, fromAreaHandle, toAreaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            SystemPageGuard::refuseGlobalArea($page, $fromAreaHandle);
            SystemPageGuard::refuseGlobalArea($page, $toAreaHandle);
            EditModeGuard::refuseOpenBlock($page, $blockID);

            try {
                [, $block] = $this->getBlockToWorkWith($page, $fromAreaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                throw new \RuntimeException(BlockNotFound::explain($page, $fromAreaHandle, $blockID));
            }

            $sourceChecker = new Checker($block);
            if (!$sourceChecker->canEditBlock()) {
                throw new \RuntimeException("Permission denied: Cannot move block {$blockID} on page {$pageID}");
            }

            // Check the destination before making a draft, so a refused move leaves no draft behind.
            // (Areas belong to the page, not to a version.)
            $destinationBlockType = $block->getBlockTypeObject();
            $destinationChecker = new Checker(Area::getOrCreate($page, $toAreaHandle));
            if ($destinationBlockType && !$destinationChecker->canAddBlock($destinationBlockType)) {
                throw new \RuntimeException("Permission denied: Cannot move '{$block->getBlockTypeHandle()}' blocks to area '{$toAreaHandle}' on page {$pageID}");
            }

            $pageToModify = $this->getDraftOf($page);
            $destinationArea = Area::getOrCreate($pageToModify, $toAreaHandle);

            $editableBlock = $this->getBlockToEdit($pageToModify, Area::getOrCreate($pageToModify, $fromAreaHandle), $fromAreaHandle, $blockID);
            if (!$editableBlock) {
                throw new \RuntimeException("Block {$blockID} could not be prepared for moving");
            }

            $editableBlock->move($pageToModify, $destinationArea);
            $pageToModify->rescanDisplayOrder($fromAreaHandle);
            $pageToModify->rescanDisplayOrder($toAreaHandle);

            $movedBlock = Block::getByID($editableBlock->getBlockID(), $pageToModify, $destinationArea);

            return $this->serializeBlock($movedBlock ?: $editableBlock, true) + DraftState::of($pageToModify);
        }));

        return $tool;
    }

    /**
     * Concrete's block lookup, refusing a global area first (BF-2): for a global area it reaches into the stack.
     */
    public function getBlockToWorkWith(Page $page, string $areaHandle, $blockID): array
    {
        SystemPageGuard::refuseGlobalArea($page, $areaHandle);

        return $this->coreGetBlockToWorkWith($page, $areaHandle, $blockID);
    }

    /**
     * Concrete's "block to edit", refusing a global area first (BF-2): for a global area it makes a stack version.
     */
    public function getBlockToEdit(Page $page, Area $area, string $areaHandle, $blockID): ?Block
    {
        SystemPageGuard::refuseGlobalArea($page, $areaHandle);

        return $this->coreGetBlockToEdit($page, $area, $areaHandle, $blockID);
    }

    protected function guarded(callable $callable): callable
    {
        return function (...$args) use ($callable) {
            $user = new User();
            if (!$user->isRegistered()) {
                throw new \RuntimeException('Authentication required: Please log in to use this tool.');
            }

            // A call that fails leaves no draft or page it made behind (BF-3).
            return DraftCleanup::run(static fn () => $callable(...$args));
        };
    }

    /**
     * The version of the page to edit (see PageDraft::of()).
     */
    protected function getDraftOf(Page $page): Page
    {
        return PageDraft::of($page);
    }

    /**
     * The ID of the page a read names by pageID or pagePath (P3.3-1). In P2.9's browser check 6 the user named a page by
     * its path, the tool took only an ID, and the model read the current page instead. An ID and a path that name
     * different pages are refused, so a stale ID never wins silently.
     *
     * @throws \RuntimeException when neither is given, the path matches no page, or the two disagree
     */
    private function pageIDFromIdOrPath(?int $pageID, ?string $pagePath): int
    {
        $pagePath = is_string($pagePath) ? trim($pagePath) : '';
        if ($pagePath === '') {
            if (!$pageID) {
                throw new \RuntimeException('Give pageID or pagePath.');
            }

            return $pageID;
        }

        $page = PageContextResolver::pageForPath($pagePath);
        if ($page === null) {
            // A page that was never published has no active version for the path lookup to find.
            $page = Page::getByPath(rtrim($pagePath, '/'), 'RECENT');
            $page = $page instanceof Page && !$page->isError() ? $page : null;
        }
        if ($page === null) {
            throw new \RuntimeException("No page at path {$pagePath}. Nothing was read.");
        }

        $found = (int) $page->getCollectionID();
        if ($pageID && $pageID !== $found) {
            throw new \RuntimeException("pageID {$pageID} and pagePath {$pagePath} (page {$found}) name different pages. Nothing was read; give the one the user meant.");
        }

        return $found;
    }

    protected function resolveCurrentContextPage(): array
    {
        $request = Application::getFacadeApplication()->make('request');
        $runtimeContext = [
            'id' => null,
            'path' => null,
            'title' => null,
            'url' => null,
        ];

        $sessionContext = null;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['katalysis_neuron_ai_page_context']) && is_array($_SESSION['katalysis_neuron_ai_page_context'])) {
            $sessionContext = $_SESSION['katalysis_neuron_ai_page_context'];
        }

        if ($request) {
            $runtimeContext['id'] = $request->request->get('pageID') ?? $request->query->get('pageID');
            $runtimeContext['path'] = $request->request->get('pagePath') ?? $request->query->get('pagePath');
            $runtimeContext['title'] = $request->request->get('pageTitle') ?? $request->query->get('pageTitle');
            $runtimeContext['url'] = $request->request->get('pageUrl') ?? $request->query->get('pageUrl');
        }

        // Fallback to persisted frontend chat context when request-scoped fields are not provided.
        if (is_array($sessionContext)) {
            if (!is_numeric($runtimeContext['id']) && isset($sessionContext['id']) && is_numeric($sessionContext['id'])) {
                $runtimeContext['id'] = (int) $sessionContext['id'];
            }

            if ((!is_string($runtimeContext['path']) || trim((string) $runtimeContext['path']) === '') && isset($sessionContext['path']) && is_string($sessionContext['path'])) {
                $runtimeContext['path'] = trim((string) $sessionContext['path']);
            }

            if ((!is_string($runtimeContext['title']) || trim((string) $runtimeContext['title']) === '') && isset($sessionContext['title']) && is_string($sessionContext['title'])) {
                $runtimeContext['title'] = trim((string) $sessionContext['title']);
            }

            if ((!is_string($runtimeContext['url']) || trim((string) $runtimeContext['url']) === '') && isset($sessionContext['url']) && is_string($sessionContext['url'])) {
                $runtimeContext['url'] = trim((string) $sessionContext['url']);
            }
        }

        $page = null;
        $source = 'current';

        if (is_numeric($runtimeContext['id'])) {
            $page = Page::getByID((int) $runtimeContext['id'], 'ACTIVE');
            $source = is_array($sessionContext) ? 'runtime-session-id' : 'runtime-id';
        }

        if ((!$page || $page->isError()) && is_string($runtimeContext['path']) && trim($runtimeContext['path']) !== '') {
            // "/" is the home page and /index.php/... a dashboard URL: a plain path lookup finds neither (BF-1).
            $page = PageContextResolver::pageForPath(trim((string) $runtimeContext['path']));
            $source = is_array($sessionContext) ? 'runtime-session-path' : 'runtime-path';
        }

        if (!$page || $page->isError()) {
            $page = Page::getCurrentPage();
            $source = 'current';
        }

        return [$page, $source, array_filter($runtimeContext, static fn($value) => $value !== null && $value !== '')];
    }

    protected function serializePage(Page $page, bool $includeDetails = false): array
    {
        $data = [
            'id' => $page->getCollectionID(),
            'name' => (string) $page->getCollectionName(),
            'path' => (string) $page->getCollectionPath(),
            'handle' => (string) $page->getCollectionHandle(),
            'description' => (string) $page->getCollectionDescription(),
            'pageType' => $page->getPageTypeHandle() ?: null,
            'pageTypeName' => $page->getPageTypeName() ?: null,
            'template' => $page->getPageTemplateHandle() ?: null,
            'isActive' => (bool) $page->isActive(),
            'versionID' => $page->getVersionID(),
            'isInTrash' => (bool) $page->isInTrash(),
            'url' => method_exists($page, 'getCollectionLink') ? (string) $page->getCollectionLink() : null,
        ];

        if ($includeDetails) {
            $data['attributes'] = $this->getPageAttributeMap($page);
            $data['areas'] = $this->getPageAreaSnapshot($page);
            $data += $this->subAreaSummary($page);
        }

        return $data;
    }

    protected function getPageAttributeMap(Page $page): array
    {
        $attributes = [];

        try {
            foreach (CollectionKey::getList() as $key) {
                $handle = (string) $key->getAttributeKeyHandle();
                if ($handle === '') {
                    continue;
                }

                $value = $page->getAttribute($handle);
                $normalized = $this->normalizeAttributeValue($value);
                if ($normalized === null) {
                    continue;
                }

                $attributes[$handle] = $normalized;
            }
        } catch (\Throwable $e) {
        }

        return $attributes;
    }

    protected function getPageAreaSnapshot(Page $page): array
    {
        $areas = [];

        foreach (Area::getHandleList() as $handle) {
            try {
                $area = Area::get($page, $handle);
                if (!$area) {
                    continue;
                }

                $blocks = $area->getAreaBlocksArray($page);
                if (!is_array($blocks) || $blocks === []) {
                    continue;
                }

                $areas[] = [
                    'areaHandle' => $handle,
                    'blockCount' => count($blocks),
                    'blocks' => array_map(fn($block) => $this->serializeBlock($block), $blocks),
                ];
            } catch (\Throwable $e) {
            }
        }

        return $areas;
    }

    /**
     * Reads the text of every block the current user may view, in the version the page
     * object holds. Container sub-areas are included (Area::getListOnPage lists them).
     *
     * A block that cannot be read is reported in 'errors' and the rest are still returned.
     * Blocks and areas the user cannot view are counted in 'hiddenBlocks' and never read.
     *
     * Blocks are loaded with Page::getBlocks(), which reads the version the page object holds,
     * rather than from the Area objects, which Concrete caches per page ID for the request.
     *
     * @return array{html: string, blocks: array<int, array<string, mixed>>, hiddenBlocks: int, errors: array<int, string>}
     */
    protected function readPageBlocks(Page $page): array
    {
        $sections = [];
        $rawSections = [];
        $blocks = [];
        $errors = [];
        $unresolved = [];
        $hidden = 0;

        // Area::getListOnPage() is typed against the global `Page` alias. PHP does not
        // autoload an alias for a type check, so load it first.
        class_exists('Page');

        try {
            $areas = Area::getListOnPage($page);
        } catch (\Throwable $e) {
            $areas = [];
            $errors[] = 'Could not list the page areas: ' . $this->safeMessage($e);
        }

        foreach ($areas as $area) {
            $handle = (string) $area->getAreaHandle();

            try {
                $areaBlocks = $page->getBlocks($handle);
                if (!(new Checker($area))->canViewArea()) {
                    $hidden += count($areaBlocks);
                    continue;
                }
            } catch (\Throwable $e) {
                $errors[] = "Area {$handle}: " . $this->safeMessage($e);
                continue;
            }

            foreach ($areaBlocks as $block) {
                $blockID = (int) $block->getBlockID();
                $type = (string) $block->getBlockTypeHandle();

                try {
                    if (!(new Checker($block))->canViewBlock()) {
                        $hidden++;
                        continue;
                    }

                    $text = $this->extractBlockText($block);
                } catch (\Throwable $e) {
                    $errors[] = "Block {$blockID} ({$type}) in area {$handle}: " . $this->safeMessage($e);
                    $text = null;
                }

                $hasText = $text !== null && trim($text) !== '';
                $entry = [
                    'blockID' => $blockID,
                    'blockType' => $type,
                    'area' => $handle,
                    'hasText' => $hasText,
                ];

                // An image block has no text, but its file and alt text are what it shows (P3.3-1).
                $image = $hasText ? null : $this->blockImage($block);
                if ($image !== null) {
                    $entry['image'] = $image;
                }
                $blocks[] = $entry;

                $open = sprintf(
                    '<section data-area="%s" data-block-type="%s" data-block-id="%d"><div>',
                    htmlspecialchars($handle, ENT_QUOTES),
                    htmlspecialchars($type, ENT_QUOTES),
                    $blockID
                );
                if ($hasText) {
                    // The block text is HTML (content blocks) or plain text. It is not escaped:
                    // HtmlToText strips the tags to give 'text', and 'html' keeps the markup.
                    $rawSections[] = $open . $text . '</div></section>';
                    $sections[] = $open . $this->readableBlockHtml($text, $unresolved) . '</div></section>';
                } elseif ($image !== null) {
                    $img = sprintf(
                        '<img%s alt="%s">',
                        $image['url'] !== null ? ' src="' . htmlspecialchars($image['url'], ENT_QUOTES) . '"' : '',
                        htmlspecialchars($image['alt'], ENT_QUOTES)
                    );
                    $rawSections[] = $open . $img . '</div></section>';
                    $sections[] = $open . $img . '</div></section>';
                }
            }
        }

        return [
            'html' => implode("\n", $sections),
            'rawHtml' => implode("\n", $rawSections),
            'blocks' => $blocks,
            'hiddenBlocks' => $hidden,
            'errors' => $errors,
            'unresolvedLinks' => array_values($unresolved),
        ];
    }

    /**
     * A block's stored HTML as Concrete renders it (P3.3-1): {CCM:…} placeholders and <concrete-picture> tags translated
     * by LinkAbstractor::translateFrom(), as the content block's view does, and site-relative URLs made absolute.
     * A link to a page that is gone or inactive comes out of translateFrom() with an empty href, so it is listed in
     * $unresolved (by page ID) rather than left as a silent empty link.
     *
     * @param array<int, array{pageID: int, reason: string}> $unresolved
     */
    private function readableBlockHtml(string $html, array &$unresolved): string
    {
        if (str_contains($html, '{CCM:') || stripos($html, '<concrete-picture') !== false) {
            if (preg_match_all('~\{CCM:CID_([0-9]+)\}~i', $html, $matches)) {
                foreach (array_unique(array_map('intval', $matches[1])) as $cID) {
                    $linked = Page::getByID($cID, 'ACTIVE');
                    if (!$linked || $linked->isError()) {
                        $unresolved[$cID] = ['pageID' => $cID, 'reason' => 'no such page'];
                    } elseif (!$linked->isActive()) {
                        $unresolved[$cID] = ['pageID' => $cID, 'reason' => 'page is inactive, so the link is empty'];
                    }
                }
            }
            $html = (string) LinkAbstractor::translateFrom($html);
        }

        return AbsoluteUrl::inHtml($html, AbsoluteUrl::siteBase());
    }

    /**
     * The file and alt text of an image block (core image, or any block whose controller has getFileID() and
     * getAltText()), or null.
     *
     * @return array{fileID: int, url: ?string, alt: string, title: string}|null
     */
    private function blockImage(Block $block): ?array
    {
        $controller = $block->getController();
        if (!$controller || !method_exists($controller, 'getFileID') || !method_exists($controller, 'getAltText')) {
            return null;
        }
        $fID = (int) $controller->getFileID();
        if ($fID <= 0) {
            return null;
        }

        return [
            'fileID' => $fID,
            'url' => AbsoluteUrl::forFile($fID),
            'alt' => (string) $controller->getAltText(),
            'title' => method_exists($controller, 'getTitle') ? (string) $controller->getTitle() : '',
        ];
    }

    /** One line, keys removed, no stack trace. */
    private function safeMessage(\Throwable $e): string
    {
        return mb_substr(SecretRedactor::redact($e->getMessage()), 0, 300);
    }

    protected function serializeBlock(?Block $block, bool $includeDetails = false): array
    {
        if (!$block) {
            return [];
        }

        $data = [
            'blockID' => $block->getBlockID(),
            'blockType' => $block->getBlockTypeHandle(),
            'blockTypeName' => $block->getBlockTypeName(),
            'blockName' => $block->getBlockName(),
            'areaHandle' => $block->getAreaHandle(),
            'displayOrder' => $block->getBlockDisplayOrder(),
        ];

        $preview = $this->extractBlockPreview($block);
        if ($preview !== null && $preview !== '') {
            $data += $this->blockPreviewFields($preview);
        }

        if ($includeDetails) {
            $controller = $block->getController();
            $databaseTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
            $data['details'] = [
                'collectionID' => $block->getBlockCollectionID(),
                'databaseTable' => $databaseTable ?: null,
            ];
        }

        return $data;
    }

    /**
     * A block's text preview, marked when it is shortened, with the tool that returns the full text (BF-5, B-TRU-7).
     *
     * @return array{contentPreview: string, contentPreviewShortened?: array{fullLength: int, fullTextTool: string}}
     */
    private function blockPreviewFields(string $text): array
    {
        $info = HtmlToText::previewInfo($text, 200);
        $fields = ['contentPreview' => $info['preview']];
        if ($info['shortened']) {
            $fields['contentPreviewShortened'] = ['fullLength' => $info['fullLength'], 'fullTextTool' => 'get_block_data'];
        }

        return $fields;
    }

    /**
     * How many blocks the page version has in container and layout columns. The area lists that use
     * Area::getHandleList() (top-level areas only) leave them out, so the result says so (BF-5 R11).
     *
     * @return array{subAreaBlocks: int, subAreaNote?: string}
     */
    protected function subAreaSummary(Page $page): array
    {
        $count = (int) Application::getFacadeApplication()->make('database')->connection()->fetchOne(
            'SELECT COUNT(*) FROM CollectionVersionBlocks cvb
             JOIN Areas a ON a.cID = cvb.cID AND a.arHandle = cvb.arHandle
             WHERE cvb.cID = ? AND cvb.cvID = ? AND a.arParentID > 0',
            [(int) $page->getCollectionID(), (int) $page->getVersionID()]
        );

        $summary = ['subAreaBlocks' => $count];
        if ($count > 0) {
            $summary['subAreaNote'] = "{$count} block(s) in container or layout columns are not listed here; get_page_content lists them.";
        }

        return $summary;
    }

    private function extractBlockPreview(Block $block): ?string
    {
        try {
            return $this->extractBlockText($block);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The text of a block, or null. Unlike extractBlockPreview() this lets a failing
     * block controller throw, so get_page_content can report it.
     */
    protected function extractBlockText(Block $block): ?string
    {
        $controller = $block->getController();
        if (!$controller) {
            return null;
        }

        if (method_exists($controller, 'getSearchableContent')) {
            $content = (string) $controller->getSearchableContent();
            if (trim($content) !== '') {
                return $content;
            }
        }

        if (method_exists($controller, 'getContent')) {
            $content = (string) $controller->getContent();
            if (trim($content) !== '') {
                return $content;
            }
        }

        if (method_exists($controller, 'getContentEditMode')) {
            $content = (string) $controller->getContentEditMode();
            if (trim($content) !== '') {
                return $content;
            }
        }

        return null;
    }

    private function normalizeAttributeValue($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof Page) {
            return [
                'id' => $value->getCollectionID(),
                'name' => $value->getCollectionName(),
                'path' => $value->getCollectionPath(),
            ];
        }

        // An image or file attribute (P3.3-1, BF-12). The File entity has getTitle() only through __call, so the test
        // below left it out, and the model couldn't see a page's thumbnail.
        if ($value instanceof FileEntity) {
            $version = $value->getApprovedVersion();
            if (!$version) {
                return ['fileID' => (int) $value->getFileID(), 'missing' => true];
            }
            $url = AbsoluteUrl::forFile((int) $value->getFileID());
            $file = ['fileID' => (int) $value->getFileID(), 'title' => (string) $version->getTitle(), 'url' => $url];

            return $url === null ? $file + ['urlNote' => AbsoluteUrl::NOTE] : $file;
        }

        if (is_object($value) && method_exists($value, 'getFileID') && method_exists($value, 'getTitle')) {
            return [
                'id' => $value->getFileID(),
                'title' => $value->getTitle(),
            ];
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $stringValue = trim((string) $value);
            return $stringValue !== '' ? $stringValue : null;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $itemValue = $this->normalizeAttributeValue($item);
                if ($itemValue !== null) {
                    $normalized[$key] = $itemValue;
                }
            }
            return $normalized;
        }

        return null;
    }
}
