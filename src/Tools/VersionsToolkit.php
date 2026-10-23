<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Page\Page;
use Concrete\Core\Page\Collection\Version\Version;
use Concrete\Core\Page\Collection\Version\VersionList;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Area\Area;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;
use Katalysis\NeuronAi\Tools\Utilities\DraftState;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Concrete CMS Version Management toolkit for Neuron AI
 * 
 * Provides version control for safe content editing:
 * - List page versions and their approval status
 * - Create draft versions for safe editing
 * - Approve versions to publish changes
 * - Discard draft versions to revert changes
 * - Preview version content before publishing
 * 
 * SAFETY FIRST: Always work in draft versions for destructive operations.
 */
class VersionsToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return <<<GUIDELINES
Version Management Guidelines:

CRITICAL SAFETY PROTOCOL:
Before ANY destructive edit (update content, delete blocks, cleanup HTML):
1. Create a draft version first (create_page_draft)
2. Make changes in that draft version
3. User previews the draft
4. User explicitly approves OR discards

WORKFLOW:
- list_page_versions: Show version history
- create_page_draft: Create safe sandbox for edits
- [User previews at /page?cvID=X]
- Publishing a version is done by a person in the CMS; this assistant cannot publish
- discard_draft_version: Revert if rejected

NEVER edit content directly in approved/active versions!
ALWAYS offer a preview before destructive operations!

When user says "revert to version X" or "go back" or "undo" → list versions, then tell them a person must publish the earlier version in the CMS; this assistant cannot publish
GUIDELINES;
    }

    public function provide(): array
    {
        return [
            $this->makeListPageVersionsTool(),
            $this->makeApprovePageVersionTool(),
            $this->makeCreatePageDraftTool(),
            $this->makeDiscardDraftVersionTool(),
            $this->makeGetVersionContentTool(),
        ];
    }

    /**
     * Tool: list_page_versions
     * List all versions of a page with their approval status
     */
    protected function makeListPageVersionsTool(): Tool
    {
        $tool = new CallableTool(
            'list_page_versions',
            'List the versions of a page, newest first, showing which are approved/draft and who created them. '
            . 'The result gives the total and whether there are more; use offset for older versions.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'The page ID to list versions for',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of versions to return (default: 10)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->setHandler(function (int $pageID, ?int $limit = 10, ?int $offset = null) {
            try {
                $page = Page::getByID($pageID);
                
                if (!$page || $page->isError()) {
                    return "❌ **Error:** Page ID {$pageID} not found.";
                }

                // Check permissions
                $permissions = new Checker($page);
                if (!$permissions->canViewPageVersions()) {
                    return "❌ **Error:** You don't have permission to view versions for this page.";
                }

                // Get version list - use the page's approved version as the base
                $approvedPage = Page::getByID($pageID, 'ACTIVE');
                $versionList = new VersionList($approvedPage);
                // BF-6: no maximum, as before; the total and the note say when older versions weren't shown.
                $window = ListWindow::of($limit, $offset, 10, PHP_INT_MAX);
                $total = (int) $versionList->getTotal();
                $versions = $versionList->get($window->limit, $window->offset);

                if ($total === 0) {
                    return "ℹ️ **No versions found** for page: " . $page->getCollectionName();
                }

                $output = "## 📚 Version History: " . $page->getCollectionName() . "\n";
                $output .= "**Path:** " . $page->getCollectionPath() . "\n";
                $output .= "**Page ID:** {$pageID}\n";
                $output .= "**Versions:** {$total}\n\n";
                $note = $window->note($total, count($versions), 'list_page_versions');
                if ($note !== null) {
                    $output .= "ℹ️ {$note}\n\n";
                }
                if (empty($versions)) {
                    return $output;
                }

                $output .= "| Version | Status | Created | Author | Comments |\n";
                $output .= "|---------|--------|---------|--------|----------|\n";

                foreach ($versions as $version) {
                    $versionID = $version->getVersionID();
                    $isApproved = $version->isApproved();
                    $isNew = $version->isNew();
                    
                    // Status indicator
                    if ($isApproved) {
                        $status = "✅ **ACTIVE**";
                    } elseif ($isNew) {
                        $status = "📝 Draft";
                    } else {
                        $status = "⏸️ Pending";
                    }

                    // Format date
                    $dateCreated = $version->getVersionDateCreated();
                    $app = Application::getFacadeApplication();
                    $dateHelper = $app->make('helper/date');
                    $formattedDate = $dateHelper->formatDateTime($dateCreated, true);

                    // Get author
                    $authorUID = $version->getVersionAuthorUserID();
                    if ($authorUID) {
                        $authorUser = \Concrete\Core\User\UserInfo::getByID($authorUID);
                        $author = $authorUser ? $authorUser->getUserName() : "User #{$authorUID}";
                    } else {
                        $author = "System";
                    }

                    // Get comments
                    $comments = $version->getVersionComments();
                    // In full: no other tool returns version comments (BF-5).
                    $comments = $comments ? trim($comments) : "—";

                    $output .= "| {$versionID} | {$status} | {$formattedDate} | {$author} | {$comments} |\n";
                }

                $output .= "\n**Preview URL pattern:** `/page-path?cvID=X` (replace X with version ID)\n";
                $output .= "\n💡 Publishing a version is done by a person in the CMS; this assistant cannot publish.\n";
                $output .= "💡 Use `create_page_draft` to create a new draft for safe editing\n";

                return $output;

            } catch (\Exception $e) {
                return "❌ **Error listing versions:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: approve_page_version
  * Make a specific version the active/published version (use for reverting)
     */
    protected function makeApprovePageVersionTool(): Tool
    {
        $tool = new CallableTool(
            'approve_page_version',
            'Approve a version to make it the active/published page. Use this to REVERT to a previous version or publish a draft.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'The page ID',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'versionID',
            PropertyType::INTEGER,
            'The version ID to approve/publish',
            true
        ));

        $tool->setHandler(function (int $pageID, int $versionID) {
            try {
                // Get the page with the specific version (convert to string)
                $page = Page::getByID($pageID, (string)$versionID);
                if (!$page || $page->isError()) {
                    return "❌ **Error:** Page ID {$pageID} or version {$versionID} not found.";
                }
                SystemPageGuard::refuse($page);
                EditModeGuard::refuse($page);

                // Check permissions
                $permissions = new Checker($page);
                if (!$permissions->canApprovePageVersions()) {
                    return "❌ **Error:** You don't have permission to approve page versions.";
                }

                // Get the version object
                $version = $page->getVersionObject();
                
                if (!$version) {
                    return "❌ **Error:** Could not load version {$versionID} for page {$pageID}.";
                }

                // Check if already approved
                if ($version->isApproved()) {
                    return "ℹ️ **Already Active:** Version {$versionID} is already the active version for this page.";
                }

                // Approve the version
                $version->approve();

                $pageName = $page->getCollectionName();
                $pagePath = $page->getCollectionPath();

                return "✅ **Version Approved Successfully**\n\n" .
                       "**Page:** {$pageName}\n" .
                       "**Path:** {$pagePath}\n" .
                       "**Version ID:** {$versionID}\n" .
                       "**Status:** Now active/published\n\n" .
                       "This version is now live. Previous drafts remain in history.";

            } catch (\Exception $e) {
                return "❌ **Error approving version:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: create_page_draft
     * Create a new draft version for safe editing
     */
    protected function makeCreatePageDraftTool(): Tool
    {
        $tool = new CallableTool(
            'create_page_draft',
            'Create a draft version of a page for safe editing, or reuse the draft that already exists (the result says which). Changes in draft do not affect live page until approved.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'The page ID to create a draft for',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'comments',
            PropertyType::STRING,
            'Comments describing this draft version (e.g., "HTML cleanup", "Content update")',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'basedOnVersionID',
            PropertyType::INTEGER,
            'Version ID to base this draft on (defaults to current approved version)',
            false
        ));

        $tool->setHandler(function (int $pageID, ?string $comments = null, ?int $basedOnVersionID = null) {
            try {
                // Get the base version
                if ($basedOnVersionID) {
                    $page = Page::getByID($pageID, (string)$basedOnVersionID);
                } else {
                    $page = Page::getByID($pageID, 'ACTIVE');
                }
                
                if (!$page || $page->isError()) {
                    return "❌ **Error:** Page ID {$pageID} not found.";
                }
                SystemPageGuard::refuse($page);
                EditModeGuard::refuse($page);

                // Check permissions
                $permissions = new Checker($page);
                if (!$permissions->canEditPageContents()) {
                    return "❌ **Error:** You don't have permission to edit this page.";
                }

                // Set default comments if not provided
                if (!$comments) {
                    $user = new User();
                    $userName = $user->isRegistered() ? $user->getUserName() : 'AI';
                    $comments = "Draft created by {$userName} for editing";
                }

                // Create new version (clone current version), or reuse the draft that already exists.
                // A draft belongs to the page, not to a user: it is reused whoever made it.
                $created = false;
                if ($basedOnVersionID) {
                    // An explicit base version: clone that version, unless it is itself the newest draft.
                    $newVersion = $page->getVersionToModify();
                    $created = $newVersion !== $page;
                    if ($created && $newVersion && !$newVersion->isError()) {
                        PageDraft::cacheAsLatest($newVersion);
                    }
                } else {
                    $newVersion = PageDraft::of($page, $created);
                }
                
                if (!$newVersion || $newVersion->isError()) {
                    // If getVersionToModify fails, try cloneVersion
                    $newVersion = $page->cloneVersion($comments);
                    $created = true;
                }

                if (!$newVersion) {
                    return "❌ **Error:** Could not create draft version.";
                }

                $newVersionID = $newVersion->getVersionID();
                $pageName = $page->getCollectionName();
                $pagePath = $page->getCollectionPath();
                $outcome = ($created ? 'created' : 'reused existing') . " draft v{$newVersionID}";

                // Get preview URL
                $previewURL = $pagePath . "?cvID=" . $newVersionID;

                return "✅ **" . ($created ? 'Draft Version Created' : 'Existing Draft Reused') . "**\n\n" .
                       "**Result:** {$outcome}\n" .
                       "**Page:** {$pageName}\n" .
                       "**Path:** {$pagePath}\n" .
                       "**New Version ID:** {$newVersionID}\n" .
                       "**Status:** Draft (not published)\n" .
                       ($created ? "**Comments:** {$comments}\n" : '') . "\n" .
                       "**Preview URL:** `{$previewURL}`\n\n" .
                       "⚠️ **IMPORTANT:**\n" .
                       "- This draft is NOT live yet\n" .
                       "- Make your edits using this version ID\n" .
                       "- User should preview before approving\n" .
                       "- Publishing is done by a person in the CMS; this assistant cannot publish\n" .
                       "- " . DraftState::summary((int) $newVersionID) . "\n" .
                       "- Use `discard_draft_version` to cancel";

            } catch (\Exception $e) {
                return "❌ **Error creating draft:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: discard_draft_version
     * Discard an unapproved draft version (deletes it)
     */
    protected function makeDiscardDraftVersionTool(): Tool
    {
        $tool = new CallableTool(
            'discard_draft_version',
            'Discard (delete) an unapproved draft version. Use this to cancel unwanted changes. Cannot discard active versions.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'The page ID',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'versionID',
            PropertyType::INTEGER,
            'The draft version ID to discard',
            true
        ));

        $tool->setHandler(function (int $pageID, int $versionID) {
            try {
                // Get the page with the specific version (convert to string)
                $page = Page::getByID($pageID, (string)$versionID);
                
                if (!$page || $page->isError()) {
                    return "❌ **Error:** Page ID {$pageID} or version {$versionID} not found.";
                }
                SystemPageGuard::refuse($page);
                EditModeGuard::refuse($page);

                // Check permissions
                $permissions = new Checker($page);
                if (!$permissions->canEditPageContents() && !$permissions->canApprovePageVersions()) {
                    return "❌ **Error:** You don't have permission to discard page versions.";
                }

                // Get the version object
                $version = $page->getVersionObject();
                
                if (!$version) {
                    return "❌ **Error:** Could not load version {$versionID} for page {$pageID}.";
                }

                // Safety check: cannot discard approved version
                if ($version->isApproved()) {
                    return "❌ **Error:** Cannot discard an active/approved version (version {$versionID} is currently live).\n\n" .
                           "To revert to a previous version, a person must publish it in the CMS; this assistant cannot publish.";
                }

                // Check if version can be discarded
                if (!$version->canDiscard()) {
                    return "❌ **Error:** This version cannot be discarded (it may be the only version).";
                }

                $pageName = $page->getCollectionName();
                $pagePath = $page->getCollectionPath();

                // Discard the version
                $version->discard();

                return "✅ **Draft Version Discarded**\n\n" .
                       "**Page:** {$pageName}\n" .
                       "**Path:** {$pagePath}\n" .
                       "**Discarded Version ID:** {$versionID}\n\n" .
                       "The draft has been deleted. The active version remains unchanged.";

            } catch (\Exception $e) {
                return "❌ **Error discarding version:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: get_version_content
     * Get content/blocks from a specific version for preview
     */
    protected function makeGetVersionContentTool(): Tool
    {
        $tool = new CallableTool(
            'get_version_content',
            'Get blocks and content from a specific version. Use this to preview what a version contains before approving.'
        );

        $tool->addProperty(new ToolProperty(
            'pageID',
            PropertyType::INTEGER,
            'The page ID',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'versionID',
            PropertyType::INTEGER,
            'The version ID to get content from',
            true
        ));

        $tool->setHandler(function (int $pageID, int $versionID) {
            try {
                // Get the page with the specific version (convert to string)
                $page = Page::getByID($pageID, (string)$versionID);
                
                if (!$page || $page->isError()) {
                    return "❌ **Error:** Page ID {$pageID} or version {$versionID} not found.";
                }

                // Check permissions
                $permissions = new Checker($page);
                if (!$permissions->canViewPageVersions()) {
                    return "❌ **Error:** You don't have permission to view this version.";
                }

                $version = $page->getVersionObject();
                if (!$version) {
                    return "❌ **Error:** Could not load version {$versionID}.";
                }

                $pageName = $page->getCollectionName();
                $pagePath = $page->getCollectionPath();
                $isApproved = $version->isApproved();
                $status = $isApproved ? "✅ Active" : "📝 Draft";

                $output = "## 📄 Version Content Preview\n\n";
                $output .= "**Page:** {$pageName}\n";
                $output .= "**Path:** {$pagePath}\n";
                $output .= "**Version ID:** {$versionID}\n";
                $output .= "**Status:** {$status}\n";
                $output .= "**Created:** " . $version->getVersionDateCreated() . "\n\n";

                $output .= "### Content Areas:\n\n";

                // Use Area::getHandleList() to get all area handles
                $areaHandles = Area::getHandleList();
                
                foreach ($areaHandles as $areaHandle) {
                    $area = Area::get($page, $areaHandle);
                    if (!$area) {
                        continue;
                    }
                    
                    $areaName = $area->getAreaDisplayName();
                    $blocks = $area->getAreaBlocksArray($page);
                    
                    if (empty($blocks)) {
                        continue;
                    }

                    $output .= "#### {$areaName} (`{$areaHandle}`)\n";
                    $output .= "**Blocks:** " . count($blocks) . "\n\n";

                    foreach ($blocks as $block) {
                        $blockType = $block->getBlockTypeName();
                        $blockID = $block->getBlockID();
                        
                        $output .= "- **{$blockType}** (Block ID: {$blockID})\n";
                        
                        // A preview for Content blocks, marked when shortened (BF-5). By handle: the block type
                        // name can be empty here, so the old check on the name 'Content' never matched.
                        $controller = $block->getBlockTypeHandle() === 'content' ? $block->getController() : null;
                        if ($controller && method_exists($controller, 'getContent')) {
                            $info = HtmlToText::previewInfo((string) $controller->getContent(), 100);
                            $output .= "  Preview: " . str_replace("\n", ' ', $info['preview']) . "\n";
                            if ($info['shortened']) {
                                $output .= "  (shortened: 100 of {$info['fullLength']} characters; get_block_data returns the full text)\n";
                            }
                        } else {
                            $output .= "  (content not shown; get_block_data returns it)\n";
                        }
                    }
                    
                    $output .= "\n";
                }

                return $output;

            } catch (\Exception $e) {
                return "❌ **Error getting version content:** " . $e->getMessage();
            }
        });

        return $tool;
    }
}
