<?php

namespace Katalysis\NeuronAi\Toolkits;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Permission\Checker;

/**
 * Native Concrete CMS Pages Toolkit
 * 
 * Provides 6 tools for managing pages:
 * - list_pages: List pages in the site with optional filters
 * - get_page: Get detailed page information
 * - create_page: Create new pages
 * - update_page: Update page properties
 * - delete_page: Delete pages
 * - get_page_children: Get child pages of a parent
 */
class PagesToolkit extends AbstractToolkit
{
    public function provide(): array
    {
        return [
            $this->createListPagesTool(),
            $this->createGetPageTool(),
            $this->createCreatePageTool(),
            $this->createUpdatePageTool(),
            $this->createDeletePageTool(),
            $this->createGetPageChildrenTool(),
        ];
    }

    /**
     * Tool: list_pages
     * List pages in the site with optional filtering
     */
    protected function createListPagesTool(): Tool
    {
        $tool = new Tool(
            'list_pages',
            'List pages in the site. Returns basic information about multiple pages.',
            [
                ToolProperty::make('limit', PropertyType::INTEGER, 'Maximum number of pages to return (default: 20, max: 100)', false),
                ToolProperty::make('parentPath', PropertyType::STRING, 'Filter by parent path (e.g., "/blog")', false),
                ToolProperty::make('pageType', PropertyType::STRING, 'Filter by page type handle (e.g., "blog_entry")', false),
            ]
        );

        $tool->setCallable(function(?int $limit = null, ?string $parentPath = null, ?string $pageType = null): string {
            try {
                $pl = new PageList();
                $pl->ignorePermissions();
                $pl->ignoreAliases();
                $pl->filterByIsActive(true);
                
                // Apply filters
                if ($parentPath) {
                    $parent = Page::getByPath($parentPath);
                    if ($parent && !$parent->isError()) {
                        $pl->filterByParentID($parent->getCollectionID());
                    } else {
                        return "❌ Parent page '$parentPath' not found.";
                    }
                }
                
                if ($pageType) {
                    $pt = PageType::getByHandle($pageType);
                    if ($pt) {
                        $pl->filterByPageTypeHandle($pageType);
                    } else {
                        return "❌ Page type '$pageType' not found.";
                    }
                }
                
                $pl->setItemsPerPage(min($limit ?? 20, 100));
                $pl->sortByPublicDateDescending();
                
                $pages = $pl->getResults();
                
                if (empty($pages)) {
                    return "No pages found.";
                }
                
                $count = count($pages);
                $output = "📄 **Found {$count} page(s):**\n\n";
                
                foreach ($pages as $page) {
                    $name = $page->getCollectionName();
                    $path = $page->getCollectionPath();
                    $id = $page->getCollectionID();
                    $type = $page->getPageTypeHandle() ?? 'unknown';
                    $modified = $page->getCollectionDateLastModified();
                    
                    $output .= "**{$name}**\n";
                    $output .= "- Path: `{$path}`\n";
                    $output .= "- ID: {$id}\n";
                    $output .= "- Type: {$type}\n";
                    $output .= "- Modified: {$modified}\n\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error listing pages:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: get_page
     * Get detailed information about a specific page
     */
    protected function createGetPageTool(): Tool
    {
        $tool = new Tool(
            'get_page',
            'Get detailed information about a specific page by ID or path',
            [
                ToolProperty::make('identifier', PropertyType::STRING, 'Page ID (e.g., "123") or path (e.g., "/about")', true),
            ]
        );

        $tool->setCallable(function(?string $identifier): string {
            try {
                // Get page by ID or path
                if (is_numeric($identifier)) {
                    $page = Page::getByID($identifier);
                } else {
                    $page = Page::getByPath($identifier);
                }
                
                if (!$page || $page->isError()) {
                    return "❌ Page '$identifier' not found.";
                }
                
                $name = $page->getCollectionName();
                $path = $page->getCollectionPath();
                $id = $page->getCollectionID();
                $type = $page->getPageTypeHandle() ?? 'unknown';
                $template = $page->getPageTemplateHandle() ?? 'unknown';
                $modified = $page->getCollectionDateLastModified();
                $published = $page->getCollectionDatePublic();
                $description = $page->getCollectionDescription();
                $childCount = $page->getNumChildren();
                
                // Get parent info
                $parentID = $page->getCollectionParentID();
                $parentInfo = '';
                if ($parentID > 1) {
                    $parent = Page::getByID($parentID);
                    if ($parent && !$parent->isError()) {
                        $parentInfo = $parent->getCollectionName() . " (" . $parent->getCollectionPath() . ")";
                    }
                }
                
                $output = "📄 **Page Details**\n\n";
                $output .= "**{$name}**\n\n";
                $output .= "| Property | Value |\n";
                $output .= "|----------|-------|\n";
                $output .= "| ID | {$id} |\n";
                $output .= "| Path | `{$path}` |\n";
                $output .= "| Type | {$type} |\n";
                $output .= "| Template | {$template} |\n";
                $output .= "| Modified | {$modified} |\n";
                $output .= "| Published | {$published} |\n";
                $output .= "| Child Pages | {$childCount} |\n";
                if ($parentInfo) {
                    $output .= "| Parent | {$parentInfo} |\n";
                }
                
                if ($description) {
                    $output .= "\n**Description:** {$description}\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error getting page:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: create_page
     * Create a new page
     */
    protected function createCreatePageTool(): Tool
    {
        $tool = new Tool(
            'create_page',
            'Create a new page in the Concrete CMS sitemap',
            [
                ToolProperty::make('name', PropertyType::STRING, 'The name/title of the page (required)', true),
                ToolProperty::make('parentPath', PropertyType::STRING, 'Path to parent page (e.g., "/about"). Defaults to "/" (root)', false),
                ToolProperty::make('pageType', PropertyType::STRING, 'Page type handle (e.g., "page", "blog_entry"). Defaults to "page"', false),
                ToolProperty::make('description', PropertyType::STRING, 'Optional page description', false),
            ]
        );

        $tool->setCallable(function(?string $name, ?string $parentPath = '/', ?string $pageType = 'page', ?string $description = null): string {
            try {
                if (!$name) {
                    return "❌ Error: Page name is required.";
                }

                // Get parent page
                if ($parentPath !== '/') {
                    $parent = Page::getByPath($parentPath);
                    if (!$parent || $parent->isError()) {
                        return "❌ Error: Parent page '$parentPath' not found.";
                    }
                } else {
                    $parent = Page::getByID(1); // Home page
                }

                // Check permissions
                $cp = new Checker($parent);
                if (!$cp->canAddSubpage()) {
                    return "❌ Error: You don't have permission to create pages under '$parentPath'";
                }

                // Get page type
                $pt = PageType::getByHandle($pageType);
                if (!$pt) {
                    // List available page types
                    $availableTypes = [];
                    foreach (PageType::getList() as $availPt) {
                        $availableTypes[] = $availPt->getPageTypeHandle();
                    }
                    return "❌ Error: Page type '$pageType' not found. Available: " . implode(', ', $availableTypes);
                }

                // Create the page
                $data = [
                    'cName' => $name,
                ];
                
                if ($description) {
                    $data['cDescription'] = $description;
                }
                
                $newPage = $parent->add($pt, $data);

                if ($newPage && !$newPage->isError()) {
                    $pagePath = $newPage->getCollectionPath();
                    $cID = $newPage->getCollectionID();
                    
                    return "✅ **Page created successfully!**\n\n" .
                           "- **Name:** {$name}\n" .
                           "- **Path:** `{$pagePath}`\n" .
                           "- **ID:** {$cID}\n" .
                           "- **Type:** {$pageType}";
                } else {
                    return "❌ Error: Failed to create page.";
                }
                
            } catch (\Exception $e) {
                return "❌ **Error creating page:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: update_page
     * Update page properties
     */
    protected function createUpdatePageTool(): Tool
    {
        $tool = new Tool(
            'update_page',
            'Update properties of an existing page',
            [
                ToolProperty::make('identifier', PropertyType::STRING, 'Page ID or path', true),
                ToolProperty::make('name', PropertyType::STRING, 'New page name', false),
                ToolProperty::make('description', PropertyType::STRING, 'New description', false),
            ]
        );

        $tool->setCallable(function(?string $identifier, ?string $name = null, ?string $description = null): string {
            try {
                // Get page
                if (is_numeric($identifier)) {
                    $page = Page::getByID($identifier);
                } else {
                    $page = Page::getByPath($identifier);
                }
                
                if (!$page || $page->isError()) {
                    return "❌ Page '$identifier' not found.";
                }

                // Check permissions
                $cp = new Checker($page);
                if (!$cp->canWrite()) {
                    return "❌ Error: You don't have permission to update this page.";
                }

                // Update properties
                $data = [];
                if ($name) {
                    $data['cName'] = $name;
                }
                if ($description !== null) {
                    $data['cDescription'] = $description;
                }

                if (empty($data)) {
                    return "❌ Error: No update properties provided.";
                }

                $page->update($data);
                
                return "✅ **Page updated successfully!**\n\n" .
                       "- **Path:** `" . $page->getCollectionPath() . "`\n" .
                       "- **ID:** " . $page->getCollectionID();
                
            } catch (\Exception $e) {
                return "❌ **Error updating page:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: delete_page
     * Delete a page
     */
    protected function createDeletePageTool(): Tool
    {
        $tool = new Tool(
            'delete_page',
            'Delete a page from the site',
            [
                ToolProperty::make('identifier', PropertyType::STRING, 'Page ID or path to delete', true),
            ]
        );

        $tool->setCallable(function(?string $identifier): string {
            try {
                // Get page
                if (is_numeric($identifier)) {
                    $page = Page::getByID($identifier);
                } else {
                    $page = Page::getByPath($identifier);
                }
                
                if (!$page || $page->isError()) {
                    return "❌ Page '$identifier' not found.";
                }

                // Check permissions
                $cp = new Checker($page);
                if (!$cp->canDeletePage()) {
                    return "❌ Error: You don't have permission to delete this page.";
                }

                $pageName = $page->getCollectionName();
                $pagePath = $page->getCollectionPath();
                $pageID = $page->getCollectionID();

                // Delete the page
                $page->delete();
                
                return "✅ **Page deleted successfully!**\n\n" .
                       "- **Name:** {$pageName}\n" .
                       "- **Path:** `{$pagePath}`\n" .
                       "- **ID:** {$pageID}";
                
            } catch (\Exception $e) {
                return "❌ **Error deleting page:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: get_page_children
     * Get child pages of a parent page
     */
    protected function createGetPageChildrenTool(): Tool
    {
        $tool = new Tool(
            'get_page_children',
            'Get child pages of a specific parent page. Useful for navigating the sitemap.',
            [
                ToolProperty::make('parentPath', PropertyType::STRING, 'Parent page path (e.g., "/about"). Defaults to "/" for top-level pages', false),
                ToolProperty::make('limit', PropertyType::INTEGER, 'Maximum children to return (default: 20)', false),
            ]
        );

        $tool->setCallable(function(?string $parentPath = '/', ?int $limit = null): string {
            try {
                // Get parent page
                if ($parentPath === '/') {
                    $parent = Page::getByID(1);
                } else {
                    $parent = Page::getByPath($parentPath);
                }
                
                if (!$parent || $parent->isError()) {
                    return "❌ Parent page '$parentPath' not found.";
                }

                $pl = new PageList();
                $pl->ignorePermissions();
                $pl->ignoreAliases();
                $pl->filterByParentID($parent->getCollectionID());
                $pl->setItemsPerPage($limit ?? 20);
                $pl->sortByDisplayOrder();
                
                $children = $pl->getResults();
                
                if (empty($children)) {
                    return "No child pages found under `$parentPath`.";
                }
                
                $count = count($children);
                $parentName = $parent->getCollectionName();
                $output = "📄 **{$count} child page(s) under '{$parentName}' ({$parentPath}):**\n\n";
                
                foreach ($children as $child) {
                    $name = $child->getCollectionName();
                    $path = $child->getCollectionPath();
                    $id = $child->getCollectionID();
                    $type = $child->getPageTypeHandle() ?? 'unknown';
                    $numChildren = $child->getNumChildren();
                    
                    $output .= "**{$name}**\n";
                    $output .= "- Path: `{$path}`\n";
                    $output .= "- ID: {$id}\n";
                    $output .= "- Type: {$type}\n";
                    if ($numChildren > 0) {
                        $output .= "- Children: {$numChildren}\n";
                    }
                    $output .= "\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error getting child pages:** " . $e->getMessage();
            }
        });

        return $tool;
    }
}
