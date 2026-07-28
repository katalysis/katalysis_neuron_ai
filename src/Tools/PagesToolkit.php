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
            $this->makeListPagesTool(),
            $this->makeGetCurrentPageContextTool(),
            $this->makeListPageTypesTool(),
            $this->makeGetPageTool(),
            $this->makeCreatePageTool(),
            $this->makeUpdatePageTool(),
            $this->makeMovePageTool(),
            $this->makeDeletePageTool(),
            $this->makeGetPageChildrenTool(),
            $this->makeAddBlockToPageAreaTool(),
            $this->makeUpdateBlockInPageAreaTool(),
            $this->makeDeleteBlockFromPageAreaTool(),
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
            'List pages with optional filtering and sorting. Returns most recently modified pages by default. Includes page type handle in each result.'
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
            'Filter by parent page ID',
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
            ?string $pageType = null
        ): array {
            $list = new PageList();
            // Match API behavior: exclude external links from page collections.
            $list->getQueryObject()->andWhere('cPointerExternalLink is null');
            $list->setPermissionsChecker(function ($page) {
                $permissions = new Checker($page);
                return $permissions->canViewPage();
            });
            $list->sortByDateModifiedDescending();
            
            if ($parentID) {
                $parent = Page::getByID($parentID);
                if ($parent && !$parent->isError()) {
                    $list->filterByParentID($parentID);
                }
            }
            
            if ($pageType) {
                $list->filterByPageTypeHandle($pageType);
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

    protected function makeCreatePageTool(): Tool
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
            'Get all child pages of a specific page'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'Parent page ID',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of children to return',
            false
        ));

        $tool->setCallable($this->guarded(function (?int $pageID = null, ?int $limit = 100): array {
            if (!$pageID) {
                throw new \RuntimeException("pageID is required");
            }
            $page = Page::getByID($pageID);
            
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found");
            }

            $parentPermissions = new Checker($page);
            if (!$parentPermissions->canViewPage()) {
                throw new \RuntimeException('Permission denied: Cannot view the specified parent page.');
            }
            
            $list = new PageList();
            $list->filterByParentID($pageID);
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
            'Add a new block to a specific page area'
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
            'Block type handle (for example: "content" or "image")',
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
            $command->setData($value ?? []);

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

            $body = (array) ($value ?? []);
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

        $tool->setCallable($this->guarded(function (
            ?int $pageID = null,
            ?string $areaHandle = null,
            ?int $blockID = null
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
            if (!$checker->canDeleteBlock()) {
                throw new \RuntimeException("Permission denied: Cannot delete block {$blockID} on page {$pageID}");
            }

            $blockToEdit = $this->getBlockToEdit($pageToModify, $area, $areaHandle, $blockID);
            $blockCollection = $blockToEdit->getBlockCollectionObject();

            $command = new DeleteBlockCommand(
                $blockToEdit->getBlockID(),
                $blockCollection->getCollectionID(),
                $blockCollection->getVersionID(),
                $areaHandle
            );

            Application::getFacadeApplication()->executeCommand($command);

            return [
                'id' => $blockID,
                'object' => 'blocks',
                'deleted' => true,
                'pageID' => $pageID,
                'areaHandle' => $areaHandle,
            ];
        }));

        return $tool;
    }

    private function guarded(callable $callback): callable
    {
        return function (...$args) use ($callback): array {
            try {
                $result = $callback(...$args);

                return $this->normalizeToolResult($result);
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        };
    }

    private function normalizeToolResult($result): array
    {
        if (!is_array($result)) {
            return [
                'ok' => true,
                'result' => $result,
                'error' => null,
            ];
        }

        if ($this->isListArray($result)) {
            return [
                'ok' => true,
                'items' => $result,
                'error' => null,
            ];
        }

        if (!array_key_exists('ok', $result)) {
            $result['ok'] = true;
        }
        if (!array_key_exists('error', $result)) {
            $result['error'] = null;
        }

        return $result;
    }

    private function isListArray(array $array): bool
    {
        return array_values($array) === $array;
    }

    private function resolveCurrentContextPage(): array
    {
        $runtimeContext = [];

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (!empty($_SESSION['katalysis_neuron_ai_page_context']) && is_array($_SESSION['katalysis_neuron_ai_page_context'])) {
            $runtimeContext = $_SESSION['katalysis_neuron_ai_page_context'];

            $runtimeId = $runtimeContext['id'] ?? null;
            if (is_numeric($runtimeId)) {
                $page = Page::getByID((int) $runtimeId, 'ACTIVE');
                if ($page && !$page->isError()) {
                    return [$page, 'session.page_id', $runtimeContext];
                }
            }

            $runtimePath = $runtimeContext['path'] ?? null;
            if (is_string($runtimePath) && $runtimePath !== '') {
                $page = Page::getByPath($runtimePath, 'ACTIVE');
                if ($page && !$page->isError()) {
                    return [$page, 'session.page_path', $runtimeContext];
                }
            }
        }

        return [Page::getCurrentPage(), 'request.current_page', $runtimeContext];
    }

    private function getPageAttributeMap(Page $page): array
    {
        $attributes = [];

        try {
            $keys = CollectionKey::getList();
            foreach ($keys as $key) {
                $handle = (string) $key->getAttributeKeyHandle();
                if ($handle === '') {
                    continue;
                }

                $value = $this->normalizeContextValue($page->getAttribute($handle));
                if ($value === null || $value === '') {
                    continue;
                }

                $attributes[$handle] = $value;
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $attributes;
    }

    private function getPageAreaSnapshot(Page $page): array
    {
        $areas = [];

        try {
            $handles = Area::getHandleList();
            foreach ($handles as $areaHandle) {
                $blocks = $page->getBlocks($areaHandle);
                if (!is_array($blocks) || count($blocks) === 0) {
                    continue;
                }

                $areaData = [
                    'handle' => $areaHandle,
                    'blockCount' => count($blocks),
                    'contentPreviews' => [],
                ];

                foreach ($blocks as $block) {
                    if (!method_exists($block, 'getBlockTypeHandle')) {
                        continue;
                    }

                    if ($block->getBlockTypeHandle() !== 'content') {
                        continue;
                    }

                    $preview = $this->extractContentBlockPreview($block);
                    if ($preview !== null && $preview !== '') {
                        $areaData['contentPreviews'][] = $preview;
                    }

                    if (count($areaData['contentPreviews']) >= 2) {
                        break;
                    }
                }

                $areas[] = $areaData;
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $areas;
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

    protected function serializePage(Page $page, bool $includeDetails = false): array
    {
        $data = [
            'id' => $page->getCollectionID(),
            'name' => $page->getCollectionName(),
            'path' => $page->getCollectionPath(),
            'description' => $page->getCollectionDescription(),
            'pageType' => $page->getPageTypeHandle(),
            'dateCreated' => $page->getCollectionDateAdded(),
            'dateModified' => $page->getCollectionDateLastModified(),
        ];

        if ($includeDetails) {
            $data['template'] = $page->getPageTemplateHandle();
            $data['numChildren'] = $page->getNumChildren();
            $data['isActive'] = $page->isActive();
            $data['isSystemPage'] = $page->isSystemPage();
        }

        return $data;
    }

    protected function serializeBlock($block, bool $includePage = false): array
    {
        $blockValue = [];

        try {
            $controller = $block->getController();
            // Use the same export fallback strategy as Concrete's API transformer.
            $exportNode = new \SimpleXMLElement('<temporary-element></temporary-element>');
            $controller->export($exportNode);
            if (isset($exportNode->data->record)) {
                foreach ($exportNode->data->record->children() as $child) {
                    $blockValue[$child->getName()] = (string) $child;
                }
            }
        } catch (\Throwable $e) {
            $blockValue = [];
        }

        $data = [
            'id' => $block->getBlockID(),
            'type' => $block->getBlockTypeHandle(),
            'areaHandle' => $block->getAreaHandle(),
            'name' => $block->getBlockName(),
            'displayOrder' => $block->getBlockDisplayOrder(),
            'value' => $blockValue,
        ];

        if ($includePage) {
            $page = $block->getBlockCollectionObject();
            if ($page instanceof Page) {
                $data['page'] = $this->serializePage($page);
            }
        }

        return $data;
    }
}
