<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Api\Attribute\AttributeValueMapFactory;
use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
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
use Katalysis\NeuronAi\Access\CoreAliases;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\Utilities\AbsoluteUrl;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\DraftState;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
use Katalysis\NeuronAi\Tools\Utilities\PermittedWindow;
use Katalysis\NeuronAi\Tools\Utilities\PageVersionRead;
use Katalysis\NeuronAi\Tools\Utilities\StrictInputTool;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use Katalysis\NeuronAi\Tools\Utilities\WriteRefusal;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Concrete CMS Pages toolkit for Neuron AI
 * 
 * Provides native tools for page management without HTTP overhead.
 * Maps all OpenAPI page operations to direct Concrete CMS calls.
 */
class PagesToolkit extends AbstractToolkit
{
    use PageToolGuards;
    use BlockSummaries;

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
            $this->makeCreatePageTool(),
            $this->makeUpdatePageTool(),
            $this->makeMovePageTool(),
            $this->makeDeletePageTool(),
            $this->makeGetPageChildrenTool(),
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

        $tool->addProperty(self::onlyVisibleProperty());

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
                $parent = self::pageAtParentPath($parentPath);
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
            
            // BF-6: with a permission checker Concrete has no count query, so the permitted matches are counted here.
            // P3.3-5: only until the window is full and the check limit is reached (PermittedWindow).
            $found = PermittedWindow::of($list, $window);
            $pages = array_map(fn($page) => $this->serializePage($page), $found['items']);

            // BF-20 (scenario 15 in P3.S2): the parent the list is under, so the model can't take its first child for it
            // (parentPath "/" and limit 1 returned /neuron-test, and a page was created there "under the home page").
            $parentInfo = [];
            if ($resolvedParentID) {
                $parentPage = Page::getByID((int) $resolvedParentID);
                if ($parentPage && !$parentPage->isError()) {
                    $parentInfo = ['parent' => [
                        'id' => (int) $parentPage->getCollectionID(),
                        'name' => (string) $parentPage->getCollectionName(),
                        'path' => (string) $parentPage->getCollectionPath() !== '' ? (string) $parentPage->getCollectionPath() : '/',
                    ]];
                }
            }

            return $parentInfo + ['pages' => $pages] + $window->summary($found['total'], count($pages), 'list_pages', 'parentPath, pageType or includeDescendants=false', $found['exact']);
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
            // P3.3-5: only pages the user may view are counted (the count ignored permissions; found in P3.5-1), in one
            // pass over them, which stops at PermittedWindow's check limit on a large site.
            $pageList = new PageList();
            $pageList->setPermissionsChecker(static fn ($page): bool => (bool) (new Checker($page))->canViewPage());
            $counts = [];
            $checked = 0;
            $rows = $pageList->executeGetResults();
            foreach ($rows as $row) {
                if (!PermittedWindow::mayCheck($checked)) {
                    break;
                }
                $checked++;
                $page = $pageList->getResult($row);
                if ($page !== null) {
                    $handle = (string) $page->getPageTypeHandle();
                    $counts[$handle] = ($counts[$handle] ?? 0) + 1;
                }
            }
            $exact = $checked >= count($rows);

            $results = [];
            foreach (PageType::getList(false) as $type) {
                $handle = $type->getPageTypeHandle();
                $results[] = [
                    'handle' => $handle,
                    'name' => $type->getPageTypeDisplayName(),
                    'isInternal' => (bool) $type->isPageTypeInternal(),
                    'usageCount' => $counts[$handle] ?? 0,
                ];
            }

            usort($results, function (array $a, array $b): int {
                return $b['usageCount'] <=> $a['usageCount'];
            });

            return [
                'totalDefinedPageTypes' => count($results),
                'pageTypes' => $results,
            ] + ($exact ? [] : [
                'countsAreAtLeast' => true,
                'note' => "Counting stopped after {$checked} of " . count($rows) . ' pages, so each usageCount is at least that many.',
            ]);
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

    protected function makeCreatePageTool(): Tool
    {
        return $this->makeCreatePageToolInternal();
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
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $parentID, 'Parent page'));
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

            ObjectPermissions::deny(ObjectPermissions::addSubpage($parent, $pt));
            
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
     * P3.3-5: list_pages and get_page_children declare onlyVisible, which the prompt named (BF-6). The description
     * carries the rule the prompt had: all pages by default.
     */
    private static function onlyVisibleProperty(): ToolProperty
    {
        return new ToolProperty(
            'onlyVisible',
            PropertyType::BOOLEAN,
            'If true, only pages shown in navigation (not hidden with "Exclude from nav"). Default false: all pages. '
            . 'Use true only when the user asks for the pages visitors see in the navigation.',
            false
        );
    }

    /**
     * BF-18: the page a `parentPath` names. "/" is the home page, as PageContextResolver resolves it (BF-1): Concrete
     * stores the home page with an empty path, so Page::getByPath('/') finds nothing.
     */
    private static function pageAtParentPath(string $parentPath): ?Page
    {
        if (PageContextResolver::normalizePath($parentPath) === '/') {
            return PageContextResolver::pageForPath('/');
        }
        $page = Page::getByPath($parentPath);

        return $page instanceof Page && !$page->isError() ? $page : null;
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
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            
            // P3.5-1 decision 2: name, description and attributes are page properties (Edit Properties, as Concrete's
            // Page Properties dialog); a template change is its own permission (Change Page Template).
            if ($name !== null || $description !== null || !empty($attributes)) {
                ObjectPermissions::deny(ObjectPermissions::editPageProperties($page));
            }
            if ($template !== null && trim($template) !== '') {
                ObjectPermissions::deny(ObjectPermissions::editPageTemplate($page));
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
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);
            
            $newParent = Page::getByID($newParentID);
            if (!$newParent || $newParent->isError()) {
                throw new \RuntimeException("New parent page with ID {$newParentID} not found");
            }
            SystemPageGuard::refuse($newParent, 'new parent page');
            
            // Check permissions on both pages
            ObjectPermissions::deny(ObjectPermissions::moveOrCopyPage($page));
            ObjectPermissions::deny(ObjectPermissions::addSubpage($newParent));
            
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
                throw new \RuntimeException(WriteRefusal::pageNotFound((int) $pageID));
            }
            SystemPageGuard::refuse($page);
            EditModeGuard::refuse($page);

            if ($page->isInTrash()) {
                throw new \RuntimeException('This page is pending deletion.');
            }
            
            ObjectPermissions::deny(ObjectPermissions::deletePage($page));

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
            'Get the child pages of a specific page. Provide either pageID (or parentID) or parentPath. '
            . 'The result gives the total and whether there are more; use offset for the next children.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'Parent page ID (use this OR parentPath)',
            false
        ));

        // P3.3-5: the parent's ID under list_pages' name for it too (scenario 17 sent parentID in 2 of 3 runs).
        $tool->addProperty(new ToolProperty(
            'parentID',
            PropertyType::INTEGER,
            'The same as pageID: the parent page ID',
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

        $tool->addProperty(self::onlyVisibleProperty());

        $tool->setHandler($this->guarded(function (
            ?int $pageID = null,
            ?string $parentPath = null,
            ?int $limit = 100,
            ?int $offset = null,
            ?int $parentID = null,
            ?bool $onlyVisible = false
        ): array {
            $window = ListWindow::of($limit, $offset, 100, 100);
            if ($pageID && $parentID && $pageID !== $parentID) {
                throw new \RuntimeException("pageID {$pageID} and parentID {$parentID} name different pages; send one of them.");
            }
            $pageID = $pageID ?: $parentID;
            if (!$pageID && !$parentPath) {
                throw new \RuntimeException("Either pageID (or parentID) or parentPath is required");
            }
            
            // Resolve the parent page
            if ($parentPath) {
                $page = self::pageAtParentPath($parentPath);
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
            if ($onlyVisible) {
                $list->filterByAttribute('exclude_nav', false);
            }

            // BF-6: the permitted children are counted here (as in list_pages); P3.3-5: up to the check limit.
            $found = PermittedWindow::of($list, $window);
            $shown = array_map(fn($child) => $this->serializePage($child), $found['items']);

            return [
                'parent' => $this->serializePage($page),
                'children' => $shown,
            ] + $window->summary($found['total'], count($shown), 'get_page_children', '', $found['exact']);
        }));

        return $tool;
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
        // autoload an alias for a type check, so load it first (BF-19: with the others).
        CoreAliases::load();

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
