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
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Page\Command\DeletePageCommand;
use Concrete\Core\Page\Template;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;
use Katalysis\NeuronAi\Tools\Utilities\BlockIdRemapper;
use Katalysis\NeuronAi\Tools\Utilities\BlockRemapException;
use NeuronAI\Tools\Tool;
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
    use GetBlockToEditTrait;
    use ValidateBlockRequestTrait;

    public function guidelines(): ?string
    {
        return "Use these tools to manage pages in Concrete CMS. You can list, create, read, update, move, and delete pages. Always check permissions before modifying content. When creating or moving pages, ensure you have the parent page information before executing the tool.";
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
        $tool = new Tool(
            'get_current_page_context',
            'Get context for the page currently being viewed in the browser, including attributes and area/block summary.'
        );

        $tool->setCallable($this->guarded(function (): array {
            [$page, $source, $runtimeContext] = $this->resolveCurrentContextPage();

            if (!$page || $page->isError()) {
                throw new \RuntimeException('Unable to determine the current page context.');
            }

            $permissions = new Checker($page);
            if (!$permissions->canViewPage()) {
                throw new \RuntimeException('Permission denied: Cannot view the current page context.');
            }

            return [
                'source' => $source,
                'runtimeContext' => $runtimeContext,
                'page' => $this->serializePage($page, includeDetails: true),
                'attributes' => $this->getPageAttributeMap($page),
                'areas' => $this->getPageAreaSnapshot($page),
            ];
        }));

        return $tool;
    }

    protected function makeListPagesTool(): Tool
    {
        $tool = new Tool(
            'list_pages',
            'List pages with optional filtering and sorting. Returns most recently modified pages by default. Use includeDescendants=true to get all pages under a path (not just direct children).'
        );

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of pages to return (1-100)',
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

        $tool->setCallable($this->guarded(function (
            ?int $limit = 20,
            ?int $parentID = null,
            ?string $parentPath = null,
            ?bool $includeDescendants = false,
            ?string $pageType = null,
            ?bool $onlyVisible = false
        ): array {
            $list = new PageList();
            // Match API behavior: exclude external links from page collections.
            $list->getQueryObject()->andWhere('cPointerExternalLink is null');
            $list->setPermissionsChecker(function ($page) {
                $permissions = new Checker($page);
                return $permissions->canViewPage();
            });
            $list->sortByDateModifiedDescending();
            
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
            
            $list->setItemsPerPage(min(max($limit, 1), 100));
            $results = $list->getResults();
            
            return array_map(fn($page) => $this->serializePage($page), $results);
        }));

        return $tool;
    }

    protected function makeListPageTypesTool(): Tool
    {
        $tool = new Tool(
            'list_page_types',
            'List Concrete CMS page types by handle, including usage counts and total number of defined types.'
        );

        $tool->setCallable($this->guarded(function (): array {
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
        $tool = new Tool(
            'get_page',
            'Retrieve detailed information about a specific page by ID. Supports active and recent versions.'
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
            'Page version to load: "active" (default) or "recent"',
            false
        ));

        $tool->setCallable($this->guarded(function (?int $pageID = null, ?string $version = 'active'): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            $versionHandle = (is_string($version) && strtolower($version) === 'recent') ? 'RECENT' : 'ACTIVE';
            $page = Page::getByID($pageID, $versionHandle);
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

            if ($page->isInTrash()) {
                throw new \RuntimeException('This page is pending deletion.');
            }

            $permissions = new Checker($page);
            $canViewPage = $versionHandle === 'RECENT'
                ? $permissions->canViewPageVersions()
                : $permissions->canViewPage();
            if (!$canViewPage) {
                if ($versionHandle === 'RECENT') {
                    throw new \RuntimeException('Permission denied: Cannot read the most recent unapproved version of this page.');
                }
                throw new \RuntimeException('Permission denied: Cannot read this page.');
            }
            
            return $this->serializePage($page, includeDetails: true);
        }));

        return $tool;
    }

    protected function makeGetPageContentTool(): Tool
    {
        $tool = new Tool(
            'get_page_content',
            'Get page content as a document with HTML and plain text. Prefer this over get_page when you need to review or summarize page copy.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to read',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'version',
            PropertyType::STRING,
            'Page version to read: "active" (published) or "recent" (latest draft). Defaults to "active".',
            false
        ));

        $tool->setCallable($this->guarded(function (?int $pageID = null, ?string $version = 'active'): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            $versionHandle = ($version === 'recent') ? 'RECENT' : 'ACTIVE';
            
            try {
                $page = Page::getByID($pageID, $versionHandle);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to load page {$pageID}: " . $e->getMessage());
            }
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found. The page may not exist or may have been deleted.");
            }

            $permissions = new Checker($page);
            $canView = ($versionHandle === 'RECENT')
                ? $permissions->canViewPageVersions()
                : $permissions->canViewPage();

            if (!$canView) {
                throw new \RuntimeException("Permission denied: Cannot read page {$pageID}. You need view permissions for this page" . ($versionHandle === 'RECENT' ? ' version.' : '.'));
            }

            // Get rendered page content
            $content = '';
            $rawContent = '';
            $renderMethod = 'fallback';
            $renderErrors = [];
            
            try {
                // Render the page to get HTML content
                $renderer = Application::getFacadeApplication()->make('Concrete\Core\Page\View\PageView');
                $renderer->setPageObject($page);
                
                // Try to get content - this may not work in all contexts
                ob_start();
                try {
                    $renderer->render();
                    $rawContent = ob_get_clean();
                    $content = $rawContent;
                    $renderMethod = 'rendered';
                } catch (\Exception $e) {
                    ob_end_clean();
                    $renderErrors[] = "Page render failed: " . $e->getMessage();
                    // Fall back to getting content from blocks directly
                    $content = $this->extractContentFromBlocks($page);
                    $rawContent = $content;
                }
            } catch (\Exception $e) {
                $renderErrors[] = "Page view setup failed: " . $e->getMessage();
                $content = $this->extractContentFromBlocks($page);
                $rawContent = $content;
            }

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
                ],
                'html' => $content,
                'html_raw' => $rawContent,
                'text' => $plainText,
                'renderMethod' => $renderMethod,
            ];
            
            if (!empty($renderErrors)) {
                $result['renderErrors'] = $renderErrors;
            }
            
            return $result;
        }));

        return $tool;
    }

    protected function makeGetPageBlocksTool(): Tool
    {
        $tool = new Tool(
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
            'Page version: "active" (published) or "recent" (latest draft). Defaults to "recent".',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'areaHandle',
            PropertyType::STRING,
            'Optional: Only return blocks from this specific area',
            false
        ));

        $tool->setCallable($this->guarded(function (?int $pageID = null, ?string $version = 'recent', ?string $areaHandle = null): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            $versionHandle = ($version === 'active') ? 'ACTIVE' : 'RECENT';
            $page = Page::getByID($pageID, $versionHandle);
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

            $permissions = new Checker($page);
            $canView = ($versionHandle === 'RECENT')
                ? $permissions->canViewPageVersions()
                : $permissions->canViewPage();

            if (!$canView) {
                throw new \RuntimeException("Permission denied: Cannot view this page version");
            }

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
                                $contentPreview = null;
                                try {
                                    if (method_exists($controller, 'getContent')) {
                                        $content = $controller->getContent();
                                        $contentPreview = HtmlToText::preview($content, 200);
                                    } elseif (method_exists($controller, 'getSearchableContent')) {
                                        $content = $controller->getSearchableContent();
                                        $contentPreview = HtmlToText::preview($content, 200);
                                    }
                                } catch (\Exception $e) {
                                    $contentPreview = "[Error extracting content: " . $e->getMessage() . "]";
                                }
                                
                                $blockData[] = [
                                    'blockID' => $block->getBlockID(),
                                    'blockType' => $blockType,
                                    'blockTypeName' => $blockTypeName,
                                    'blockName' => $block->getBlockName(),
                                    'displayOrder' => $block->getBlockDisplayOrder(),
                                    'contentPreview' => $contentPreview,
                                ];
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
                'totalBlocks' => $totalBlocks,
                'areaCount' => count($areas),
                'areas' => $areas,
                'note' => 'contentPreview values are intentionally truncated previews for discovery only. Do not use contentPreview as source content for updates.',
            ];

            if (!empty($errors)) {
                $result['errors'] = $errors;
                $result['note'] = 'Some errors occurred during block inspection. See errors array.';
            }

            return $result;
        }));

        return $tool;
    }

    protected function makeBatchUpdatePageContentTool(): Tool
    {
        $tool = new Tool(
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
            'Array of block updates. Each item must have: areaHandle (string), blockID (number from current version), value (object with block data)',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'changeMode',
            PropertyType::STRING,
            'Text safety mode: "freeform" (default), "formatting_only" (text must remain identical), or "scoped_text" (text changes only in explicitly allowed textFields per block)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Whether to continue processing remaining block updates after a failure (default: true)',
            false
        ));

        $tool->setCallable($this->guarded(function (
            ?int $pageID = null,
            ?array $blocks = [],
            ?string $changeMode = 'freeform',
            ?bool $continueOnError = true
        ): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }

            if (empty($blocks) || !is_array($blocks)) {
                throw new \RuntimeException("blocks must be a non-empty array. Provide at least one block update.");
            }

            $mode = strtolower(trim((string) ($changeMode ?? 'freeform')));
            if (!in_array($mode, ['freeform', 'formatting_only', 'scoped_text'], true)) {
                throw new \RuntimeException("changeMode must be one of: freeform, formatting_only, scoped_text");
            }

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

                $textFields = null;
                if (isset($block['textFields'])) {
                    if (!is_array($block['textFields'])) {
                        throw new \RuntimeException("blocks[{$index}].textFields must be an array of field names when provided");
                    }
                    $textFields = array_values(array_filter(array_map(static fn($v) => is_string($v) ? trim($v) : '', $block['textFields']), static fn($v) => $v !== ''));
                }

                $updates[] = [
                    'areaHandle' => $block['areaHandle'],
                    'blockID' => $block['blockID'],
                    'value' => $block['value'],
                    'textFields' => $textFields,
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

            $permissions = new Checker($page);
            if (!$permissions->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}. You need edit permissions for this page.");
            }

            // Capture block layout BEFORE creating new version
            try {
                $beforeAreas = BlockIdRemapper::captureAreaSnapshot($page);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to capture block layout before versioning: " . $e->getMessage());
            }

            // Create new version
            try {
                $pageToModify = $page->getVersionToModify();
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to create new page version: " . $e->getMessage() . ". This may indicate a database or permissions issue.");
            }

            // Capture block layout AFTER creating new version
            try {
                $afterAreas = BlockIdRemapper::captureAreaSnapshot($pageToModify);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to capture block layout after versioning: " . $e->getMessage());
            }

            // Remap block IDs
            try {
                $remapped = BlockIdRemapper::remap($beforeAreas, $afterAreas, $updates);
            } catch (BlockRemapException $e) {
                throw new \RuntimeException("Block ID remapping failed: " . $e->getMessage() . ". This usually means blocks couldn't be matched between versions. Use get_page_blocks tool to verify block IDs and areas.");
            }

            // Update each block
            $blockResults = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($remapped as $update) {
                $areaHandle = $update['areaHandle'];
                $newBlockID = $update['newBlockID'];
                $value = $update['value'];
                $textFields = $update['textFields'] ?? null;

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

                    $controller = $block->getController();
                    $dbTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
                    $beforeRecord = $this->getBlockTableRecord($dbTable, $newBlockID);

                    $textPolicy = $this->evaluateTextChangePolicy($beforeRecord, $value, $mode, $textFields);
                    if (!($textPolicy['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($textPolicy['message'] ?? 'Text safety policy rejected this update'));
                    }

                    $watchedFields = $textPolicy['watchedFields'] ?? [];
                    $beforeChecksums = $this->buildTextChecksumMap($beforeRecord, $watchedFields);

                    $normalizedValue = $this->normalizeCustomBlockPayload($block->getBlockTypeHandle(), (array) ($value ?? []));
                    if ($block->getBlockTypeHandle() === 'epra_resources_list' && !array_key_exists('slide', $normalizedValue)) {
                        // Preserve existing repeatable rows for layout-only updates in batch mode.
                        $normalizedValue['slide'] = $this->loadEpraResourceEntries((int) $newBlockID);
                    }

                    // Update the block
                    $block->update($normalizedValue);

                    $afterRecord = $this->getBlockTableRecord($dbTable, $newBlockID);
                    $afterChecksums = $this->buildTextChecksumMap($afterRecord, $watchedFields);

                    $blockResults[] = [
                        'areaHandle' => $areaHandle,
                        'oldBlockID' => $update['oldBlockID'],
                        'newBlockID' => $newBlockID,
                        'blockType' => $block->getBlockTypeHandle(),
                        'changeMode' => $mode,
                        'watchedTextFields' => $watchedFields,
                        'textChecksumsBefore' => $beforeChecksums,
                        'textChecksumsAfter' => $afterChecksums,
                        'ok' => true,
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $blockResults[] = [
                        'areaHandle' => $areaHandle,
                        'oldBlockID' => $update['oldBlockID'],
                        'newBlockID' => $newBlockID,
                        'changeMode' => $mode,
                        'ok' => false,
                        'error' => $e->getMessage(),
                    ];
                    $failureCount++;

                    if (!$continueOnError) {
                        break;
                    }
                }
            }

            $result = [
                'id' => $pageToModify->getCollectionID(),
                'path' => $pageToModify->getCollectionPath(),
                'name' => $pageToModify->getCollectionName(),
                'version' => [
                    'cvID' => $pageToModify->getVersionID(),
                    'isActive' => $pageToModify->isActive(),
                ],
                'summary' => [
                    'totalBlocks' => count($blockResults),
                    'successCount' => $successCount,
                    'failureCount' => $failureCount,
                    'changeMode' => $mode,
                    'continueOnError' => (bool) $continueOnError,
                ],
                'blocks' => $blockResults,
                'note' => 'Page version was not auto-approved. Approve it manually if needed.',
                'safety' => [
                    'textPolicy' => $mode,
                    'checksumAlgorithm' => 'sha1(normalizedText)',
                ],
            ];
            
            if ($failureCount > 0) {
                $result['warning'] = "{$failureCount} block update(s) failed. See blocks array for details.";
            }
            
            return $result;
        }));

        return $tool;
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

    /**
     * @param array<string, mixed> $beforeRecord
     * @param array<string, mixed> $proposedValue
     * @param string $mode
     * @param array<int, string>|null $requestedFields
     * @return array{ok:bool,message:string,watchedFields:array<int,string>}
     */
    private function evaluateTextChangePolicy(array $beforeRecord, array $proposedValue, string $mode, ?array $requestedFields = null): array
    {
        if ($mode === 'freeform') {
            return [
                'ok' => true,
                'message' => 'freeform mode',
                'watchedFields' => $this->resolveWatchedTextFields($beforeRecord, $proposedValue, $requestedFields),
            ];
        }

        $watchedFields = $this->resolveWatchedTextFields($beforeRecord, $proposedValue, $requestedFields);
        if ($mode === 'scoped_text' && empty($requestedFields)) {
            return [
                'ok' => false,
                'message' => 'scoped_text mode requires textFields for each block update',
                'watchedFields' => $watchedFields,
            ];
        }

        $allowedSet = array_fill_keys($requestedFields ?? [], true);

        foreach ($watchedFields as $field) {
            if (!array_key_exists($field, $proposedValue)) {
                continue;
            }

            $beforeText = $this->normalizeTextForComparison($beforeRecord[$field] ?? null);
            $afterText = $this->normalizeTextForComparison($proposedValue[$field] ?? null);

            if ($mode === 'formatting_only' && $beforeText !== $afterText) {
                return [
                    'ok' => false,
                    'message' => "formatting_only violation in field '{$field}': textual content changed",
                    'watchedFields' => $watchedFields,
                ];
            }

            if ($mode === 'scoped_text' && $beforeText !== $afterText && !isset($allowedSet[$field])) {
                return [
                    'ok' => false,
                    'message' => "scoped_text violation in field '{$field}': field is not in allowed textFields",
                    'watchedFields' => $watchedFields,
                ];
            }
        }

        return [
            'ok' => true,
            'message' => 'text policy passed',
            'watchedFields' => $watchedFields,
        ];
    }

    /**
     * @param array<string, mixed> $beforeRecord
     * @param array<string, mixed> $proposedValue
     * @param array<int, string>|null $requestedFields
     * @return array<int, string>
     */
    private function resolveWatchedTextFields(array $beforeRecord, array $proposedValue, ?array $requestedFields = null): array
    {
        if (!empty($requestedFields)) {
            return array_values(array_unique(array_filter($requestedFields, static fn($f) => is_string($f) && $f !== '')));
        }

        $fields = [];
        foreach ($proposedValue as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            if (array_key_exists($field, $beforeRecord) && is_string($beforeRecord[$field] ?? null)) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    private function normalizeTextForComparison($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        return trim($text);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, string> $fields
     * @return array<string, string>
     */
    private function buildTextChecksumMap(array $record, array $fields): array
    {
        $checksums = [];
        foreach ($fields as $field) {
            $checksums[$field] = sha1($this->normalizeTextForComparison($record[$field] ?? null));
        }

        return $checksums;
    }

    protected function makeCreatePageTool(): Tool
    {
        return $this->makeCreatePageToolInternal();
    }

    protected function makeBatchUpdateContentHeadingsTool(): Tool
    {
        $tool = new Tool(
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
            'Include draft/pending placements when resolving candidate blocks (default: true)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'continueOnError',
            PropertyType::BOOLEAN,
            'Continue processing remaining headings after an error (default: true)',
            false
        ));

        $tool->setCallable($this->guarded(function (
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
            foreach ($headingTexts as $text) {
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
                throw new \RuntimeException('headingTexts must contain at least one non-empty string when matchMode is explicit');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

            $permissions = new Checker($page);
            if (!$permissions->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}");
            }

            $pageToModify = $page->getVersionToModify();
            $contentBt = BlockType::getByHandle('content');
            if (!$contentBt) {
                throw new \RuntimeException('Content block type is not available');
            }

            $conn = Application::getFacadeApplication()->make('database')->connection();
            $qb = $conn->createQueryBuilder();
            $qb->select('b.bID', 'cvb.arHandle', 'cvb.cvID', 'cv.cvIsApproved')
                ->from('Blocks', 'b')
                ->innerJoin('b', 'CollectionVersionBlocks', 'cvb', 'b.bID = cvb.bID')
                ->innerJoin('cvb', 'CollectionVersions', 'cv', 'cvb.cID = cv.cID AND cvb.cvID = cv.cvID')
                ->where('cvb.cID = :cID')
                ->andWhere('b.btID = :btID')
                ->setParameter('cID', $pageID)
                ->setParameter('btID', $contentBt->getBlockTypeID())
                ->orderBy('b.bID', 'ASC')
                ->addOrderBy('cv.cvIsApproved', 'DESC')
                ->addOrderBy('cvb.cvID', 'DESC');

            if (!$includeInactive) {
                $qb->andWhere('cv.cvIsApproved = 1');
            }

            $placements = $qb->execute()->fetchAllAssociative();
            if (empty($placements)) {
                return [
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
                    $area = $pageToModify->getArea($areaHandle);
                    if (!$area) {
                        $area = new Area($areaHandle);
                        $area->c = $pageToModify;
                    }

                    $block = Block::getByID((int) $blockID, $pageToModify, $area);
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

                    $match = null;
                    if ($matchMode === 'explicit') {
                        $match = $this->findMatchingHeadingTextInContent($content, $pendingHeadings);
                    } else {
                        $detectedHeading = $this->extractBoldParagraphHeadingText($content);
                        if ($detectedHeading !== null) {
                            $detectedKey = $this->normalizeHeadingLookupText($detectedHeading);
                            if (empty($pendingHeadings) || isset($pendingHeadings[$detectedKey])) {
                                $match = [
                                    'key' => $detectedKey,
                                    'text' => empty($pendingHeadings) ? $detectedHeading : (string) ($pendingHeadings[$detectedKey] ?? $detectedHeading),
                                ];
                            }
                        }
                    }

                    if ($match === null) {
                        continue;
                    }

                    $headingKey = $match['key'];
                    $headingText = $match['text'];
                    $transformedContent = $this->applyHeadingLevelToContent($content, $headingText, $targetTag);

                    if ($transformedContent === $content) {
                        continue;
                    }

                    $beforeText = $this->normalizeTextForComparison($content);
                    $afterText = $this->normalizeTextForComparison($transformedContent);
                    if ($beforeText !== $afterText) {
                        throw new \RuntimeException("text integrity check failed for '{$headingText}' (body text changed)");
                    }

                    $block->update(['content' => $transformedContent]);

                    $afterRecord = $this->getBlockTableRecord($dbTable, (int) $blockID);
                    $afterContent = is_string($afterRecord['content'] ?? null) ? (string) $afterRecord['content'] : '';

                    $updated[] = [
                        'blockID' => (int) $blockID,
                        'areaHandle' => $areaHandle,
                        'headingText' => $headingText,
                        'targetTag' => $targetTag,
                        'matchMode' => $matchMode,
                        'textChecksumBefore' => sha1($beforeText),
                        'textChecksumAfter' => sha1($this->normalizeTextForComparison($afterContent)),
                    ];

                    if (isset($pendingHeadings[$headingKey])) {
                        unset($pendingHeadings[$headingKey]);
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

            return [
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
            ];
        }));

        return $tool;
    }

    protected function makeSyncMultiLinksToEpraResourcesTool(): Tool
    {
        $tool = new Tool(
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

        $tool->setCallable($this->guarded(function (
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
        $tool = new Tool(
            'create_page',
            'Create a new page in Concrete CMS'
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

        $tool->setCallable($this->guarded(function (
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
            
            $data = ['name' => $name];
            if ($description) {
                $data['description'] = $description;
            }
            
            $page = $parent->add($pt, $data, $pageTemplate);

            if ($attributes !== null) {
                $category = Application::getFacadeApplication()->make(PageCategory::class);
                $attributeValueMapFactory = Application::getFacadeApplication()->make(AttributeValueMapFactory::class);
                $attributeMap = $attributeValueMapFactory->createFromRequestData($category, $attributes);
                foreach ($attributeMap->getEntries() as $entry) {
                    $page->setAttribute($entry->getAttributeKey(), $entry->getAttributeValue());
                }
            }
            
            return $this->serializePage($page);
        }));

        return $tool;
    }

    /**
     * @param string $content
     * @param array<string, string> $pendingHeadings
     * @return array{key:string,text:string}|null
     */
    private function findMatchingHeadingTextInContent(string $content, array $pendingHeadings): ?array
    {
        $plain = $this->normalizeHeadingLookupText($this->normalizeTextForComparison($content));
        foreach ($pendingHeadings as $key => $text) {
            if ($key === '') {
                continue;
            }
            if (strpos($plain, $key) !== false) {
                return ['key' => $key, 'text' => $text];
            }
        }

        return null;
    }

    private function extractBoldParagraphHeadingText(string $html): ?string
    {
        $pattern = '~<p\b[^>]*>\s*(?:<strong\b[^>]*>|<b\b[^>]*>)\s*(.*?)\s*(?:</strong>|</b>)\s*</p>~isu';
        if (!preg_match($pattern, $html, $matches)) {
            return null;
        }

        $candidate = isset($matches[1]) ? (string) $matches[1] : '';
        $candidate = html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidate = preg_replace('/\s+/u', ' ', $candidate) ?: '';
        $candidate = trim($candidate);

        return $candidate !== '' ? $candidate : null;
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
        }, $html, 1, $count);

        if (is_string($updated) && $count > 0) {
            return $updated;
        }

        // Bold paragraph heading: <p><strong>Title</strong></p> or <p><b>Title</b></p>
        $boldParagraphPattern = '~<p\b[^>]*>\s*(?:<strong\b[^>]*>|<b\b[^>]*>)\s*' . $escaped . '\s*(?:</strong>|</b>)\s*</p>~iu';
        $updated = preg_replace($boldParagraphPattern, '<' . $targetTag . '>' . $headingText . '</' . $targetTag . '>', $html, 1, $count);
        if (is_string($updated) && $count > 0) {
            return $updated;
        }

        return $html;
    }

    protected function makeUpdatePageTool(): Tool
    {
        $tool = new Tool(
            'update_page',
            'Update an existing page\'s properties'
        );

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
            'New page type handle',
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
            'Optional page attributes map keyed by attribute handle',
            false
        ));

        $tool->setCallable($this->guarded(function (
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
            
            // Check permissions
            $checker = new Checker($page);
            if (!$checker->canEditPageContents()) {
                throw new \RuntimeException("Permission denied: Cannot edit page {$pageID}");
            }

            $targetType = null;
            if ($pageType !== null) {
                $targetType = PageType::getByHandle($pageType);
                if (!$targetType) {
                    throw new \RuntimeException("Page type '{$pageType}' not found");
                }
            }

            $targetTemplate = null;
            if ($template !== null && trim($template) !== '') {
                $targetTemplate = Template::getByHandle($template);
                if (!$targetTemplate) {
                    throw new \RuntimeException("Page template '{$template}' not found");
                }
            }

            $pageToModify = $page->getVersionToModify();
            
            $data = [];
            if ($name !== null) {
                $data['cName'] = $name;
            }
            if ($description !== null) {
                $data['cDescription'] = $description;
            }
            if ($targetType) {
                $data['ptID'] = $targetType->getPageTypeID();
            }
            if ($targetTemplate) {
                $data['pTemplateID'] = $targetTemplate->getPageTemplateID();
            }
            
            if (!empty($data)) {
                $pageToModify->update($data);
            }

            if ($attributes !== null) {
                $category = Application::getFacadeApplication()->make(PageCategory::class);
                $attributeValueMapFactory = Application::getFacadeApplication()->make(AttributeValueMapFactory::class);
                $attributeMap = $attributeValueMapFactory->createFromRequestData($category, $attributes);
                foreach ($attributeMap->getEntries() as $entry) {
                    $pageToModify->setAttribute($entry->getAttributeKey(), $entry->getAttributeValue());
                }
            }
            
            return $this->serializePage($pageToModify);
        }));

        return $tool;
    }

    protected function makeMovePageTool(): Tool
    {
        $tool = new Tool(
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

        $tool->setCallable($this->guarded(function (
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
            
            $newParent = Page::getByID($newParentID);
            if (!$newParent || $newParent->isError()) {
                throw new \RuntimeException("New parent page with ID {$newParentID} not found");
            }
            
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
        $tool = new Tool(
            'delete_page',
            'Delete a page (moves to trash)'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'ID of the page to delete',
            true
        ));

        $tool->setCallable($this->guarded(function (int $pageID): array {
            $page = Page::getByID($pageID, 'ACTIVE');
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

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
        $tool = new Tool(
            'get_page_children',
            'Get all child pages of a specific page. Provide either pageID or parentPath.'
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
            'Maximum number of children to return',
            false
        ));

        $tool->setCallable($this->guarded(function (?int $pageID = null, ?string $parentPath = null, ?int $limit = 100): array {
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
            $list->setPermissionsChecker(function ($childPage) {
                $permissions = new Checker($childPage);
                return $permissions->canViewPage();
            });
            $list->setItemsPerPage(min($limit, 100));
            
            $children = $list->getResults();
            
            return [
                'parent' => $this->serializePage($page),
                'children' => array_map(fn($child) => $this->serializePage($child), $children),
                'total' => count($children)
            ];
        }));

        return $tool;
    }

    protected function makeAddBlockToPageAreaTool(): Tool
    {
        $tool = new Tool(
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

        $tool->setCallable($this->guarded(function (
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

            $bt = BlockType::getByHandle($blockType);
            if (!$bt) {
                throw new \RuntimeException("Invalid block type handle '{$blockType}'");
            }

            $normalizedValue = $this->normalizeCustomBlockPayload((string) $blockType, $value ?? []);

            /** @var mixed $pageForArea */
            $pageForArea = $page;
            $area = Area::getOrCreate($pageForArea, $areaHandle);
            $checker = new Checker($area);
            if (!$checker->canAddBlock($bt)) {
                throw new \RuntimeException("Permission denied: Cannot add '{$blockType}' blocks to area '{$areaHandle}' on page {$pageID}");
            }

            $command = new AddBlockToPageCommand();
            $command->setPage($page);
            $command->setArea($area);
            $command->setBlockType($bt);
            $command->setData($normalizedValue);

            $block = Application::getFacadeApplication()->executeCommand($command);

            return $this->serializeBlock($block, true);
        }));

        return $tool;
    }

    protected function makeUpdateBlockInPageAreaTool(): Tool
    {
        $tool = new Tool(
            'update_block_in_page_area',
            'Update an existing block in a specific page area'
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

        $tool->setCallable($this->guarded(function (
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?int $blockID = null,
            ?array $value = []
        ): array {
            if (!$pageID || !$areaHandle || !$blockID) {
                throw new \RuntimeException('pageID, areaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

            $pageToModify = $page->getVersionToModify();

            try {
                [$area, $block] = $this->getBlockToWorkWith($page, $areaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                $fallbackArea = $pageToModify->getArea($areaHandle);
                if (!$fallbackArea) {
                    $fallbackArea = new Area($areaHandle);
                    $fallbackArea->c = $pageToModify;
                }
                $fallbackBlock = Block::getByID($blockID, $pageToModify, $fallbackArea);
                if (!$fallbackBlock) {
                    if ($e instanceof AreaNotFoundException) {
                        throw new \RuntimeException("Area '{$areaHandle}' not found on page {$pageID}");
                    }
                    throw new \RuntimeException("Block {$blockID} not found in area '{$areaHandle}' on page {$pageID}");
                }
                $area = $fallbackArea;
                $block = $fallbackBlock;
            }

            $checker = new Checker($block);
            if (!$checker->canEditBlock()) {
                throw new \RuntimeException("Permission denied: Cannot edit block {$blockID} on page {$pageID}");
            }

            $body = $this->normalizeCustomBlockPayload($block->getBlockTypeHandle(), (array) ($value ?? []));
            if ($block->getBlockTypeHandle() === 'epra_resources_list' && !array_key_exists('slide', $body)) {
                // Preserve existing repeatable rows for layout-only updates (e.g., gridStructure changes).
                $body['slide'] = $this->loadEpraResourceEntries((int) $blockID);
            }
            $validation = $this->validateBlock($block, $body);
            if ($validation instanceof JsonResponse) {
                $validationBody = (string) $validation->getContent();
                throw new \RuntimeException("Block validation failed: {$validationBody}");
            }

            $blockToEdit = $this->getBlockToEdit($page, $area, $areaHandle, $blockID);

            $command = new UpdatePageBlockCommand();
            $command->setPage($pageToModify);
            $command->setData($body);
            $command->setBlock($blockToEdit);

            $updatedBlock = Application::getFacadeApplication()->executeCommand($command);
            $blockToEdit->update($body);

            return $this->serializeBlock($updatedBlock, true);
        }));

        return $tool;
    }

    private function normalizeCustomBlockPayload(string $blockTypeHandle, array $payload): array
    {
        if ($payload === []) {
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
        $tool = new Tool(
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

        $tool->setCallable($this->guarded(function (?int $pageID = null, ?string $areaHandle = null, ?int $blockID = null): array {
            if (!$pageID || !$areaHandle || !$blockID) {
                throw new \RuntimeException('pageID, areaHandle, and blockID are required');
            }

            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

            try {
                [$area, $block] = $this->getBlockToWorkWith($page, $areaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                throw new \RuntimeException("Block {$blockID} not found in area '{$areaHandle}' on page {$pageID}");
            }

            $checker = new Checker($block);
            if (!$checker->canDeleteBlock()) {
                throw new \RuntimeException("Permission denied: Cannot delete block {$blockID} on page {$pageID}");
            }

            $command = new DeleteBlockCommand(
                $block->getBlockID(),
                $page->getCollectionID(),
                $page->getVersionID(),
                $area->getAreaHandle()
            );

            Application::getFacadeApplication()->executeCommand($command);

            return [
                'pageID' => $pageID,
                'areaHandle' => $areaHandle,
                'blockID' => $blockID,
                'deleted' => true,
            ];
        }));

        return $tool;
    }

    protected function makeMoveBlockBetweenAreasTool(): Tool
    {
        $tool = new Tool(
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

        $tool->setCallable($this->guarded(function (
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

            try {
                [, $block] = $this->getBlockToWorkWith($page, $fromAreaHandle, $blockID);
            } catch (AreaNotFoundException|BlockNotFoundException $e) {
                throw new \RuntimeException("Block {$blockID} not found in area '{$fromAreaHandle}' on page {$pageID}");
            }

            $sourceChecker = new Checker($block);
            if (!$sourceChecker->canEditBlock()) {
                throw new \RuntimeException("Permission denied: Cannot move block {$blockID} on page {$pageID}");
            }

            $pageToModify = $page->getVersionToModify();
            $destinationArea = Area::getOrCreate($pageToModify, $toAreaHandle);
            $destinationBlockType = $block->getBlockTypeObject();
            $destinationChecker = new Checker($destinationArea);
            if ($destinationBlockType && !$destinationChecker->canAddBlock($destinationBlockType)) {
                throw new \RuntimeException("Permission denied: Cannot move '{$block->getBlockTypeHandle()}' blocks to area '{$toAreaHandle}' on page {$pageID}");
            }

            $editableBlock = $this->getBlockToEdit($page, Area::getOrCreate($pageToModify, $fromAreaHandle), $fromAreaHandle, $blockID);
            if (!$editableBlock) {
                throw new \RuntimeException("Block {$blockID} could not be prepared for moving");
            }

            $editableBlock->move($pageToModify, $destinationArea);
            $pageToModify->rescanDisplayOrder($fromAreaHandle);
            $pageToModify->rescanDisplayOrder($toAreaHandle);

            $movedBlock = Block::getByID($editableBlock->getBlockID(), $pageToModify, $destinationArea);

            return $this->serializeBlock($movedBlock ?: $editableBlock, true);
        }));

        return $tool;
    }

    protected function guarded(callable $callable): callable
    {
        return function (...$args) use ($callable) {
            $user = new User();
            if (!$user->isRegistered()) {
                throw new \RuntimeException('Authentication required: Please log in to use this tool.');
            }

            return $callable(...$args);
        };
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
            $page = Page::getByPath(trim((string) $runtimeContext['path']), 'ACTIVE');
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

    protected function extractContentFromBlocks(Page $page): string
    {
        $chunks = [];

        foreach (Area::getHandleList() as $handle) {
            try {
                $area = Area::get($page, $handle);
                if (!$area) {
                    continue;
                }

                $blocks = $area->getAreaBlocksArray($page);
                if (!is_array($blocks)) {
                    continue;
                }

                foreach ($blocks as $block) {
                    $preview = $this->extractBlockPreview($block);
                    if ($preview !== null && $preview !== '') {
                        $chunks[] = sprintf('<section data-area="%s"><h2>%s</h2><div>%s</div></section>', htmlspecialchars($handle, ENT_QUOTES), htmlspecialchars($handle, ENT_QUOTES), $preview);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return implode("\n", $chunks);
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
            $data['contentPreview'] = HtmlToText::preview($preview, 200);
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

    private function extractBlockPreview(Block $block): ?string
    {
        try {
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
        } catch (\Throwable $e) {
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
