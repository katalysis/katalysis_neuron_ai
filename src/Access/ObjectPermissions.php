<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

use Concrete\Core\Area\Area;
use Concrete\Core\Entity\Attribute\Key\UserKey;
use Concrete\Core\Block\Block;
use Concrete\Core\Entity\Block\BlockType\BlockType;
use Concrete\Core\Entity\File\File;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Permission\Key\Key;
use Concrete\Core\Tree\Node\Node;

/**
 * The per-object permission checks the tools share (P3.5-1, B-ACC-4). One function per check, named after the
 * Concrete permission key (or, where Concrete has no single key, the check its own UI makes). Each runs Concrete's
 * own Checker as the logged-in user, so the super user passes as everywhere in Concrete and nobody else is
 * elevated (B-ACC-5). Each returns null when the user may, or a refusal the model can act on (B-ACC-6): what was
 * refused, which permission is missing, who can grant it, and, for a write, that nothing was changed and that it
 * must not try another route. deny() throws it.
 *
 *     ObjectPermissions::deny(ObjectPermissions::editPageContents($page));
 */
final class ObjectPermissions
{
    private const GRANT_PAGE = "A site administrator can grant it in the page's Permissions (Sitemap, then the page, then Permissions).";
    private const GRANT_FILE = 'A site administrator can grant it in the File Manager, in the permissions of the file or its folder.';
    private const GRANT_STACK = "A site administrator can grant it under Dashboard > Stacks & Global Areas, in the stack's Permissions.";
    private const GRANT_TASK ='A site administrator can grant it in Dashboard > System & Settings > Permissions & Access > Tasks.';

    /**
     * The display name for a check that isn't one Concrete key, or a fallback when the key can't be read.
     */
    private const NAMES = [
        'view_page' => 'View',
        'view_page_versions' => 'View Versions',
        'edit_page_contents' => 'Edit Contents',
        'edit_page_properties' => 'Edit Properties',
        'edit_page_template' => 'Change Page Template',
        'approve_page_versions' => 'Approve Changes',
        'delete_page_versions' => 'Delete Versions',
        'delete_page' => 'Delete',
        'move_or_copy_page' => 'Move or Copy Page',
        'add_subpage' => 'Add Sub-Page',
        'add_block_to_area' => 'Add Block to Area',
        'view_block' => 'View Block',
        'edit_block' => 'Edit Block',
        'delete_block' => 'Delete Block',
        'view_file_in_file_manager' => 'View File in File Manager',
        'edit_file_properties' => 'Edit File Properties',
        'delete_file' => 'Delete File',
        'add_file' => 'Add File',
        'edit_file_folder' => 'Edit File Folder',
        'add_topic_tree' => 'Add Topic Tree',
        'view_user_attributes' => 'View User Attributes',
    ];

    // ---- Pages ----------------------------------------------------------------------------------------------------

    public static function viewPage(Page $page): ?string
    {
        return self::check(new Checker($page), 'canViewPage', [], 'view_page', 'read ' . self::page($page), self::GRANT_PAGE, false);
    }

    public static function viewPageVersions(Page $page): ?string
    {
        return self::check(new Checker($page), 'canViewPageVersions', [], 'view_page_versions', 'read unpublished versions of ' . self::page($page), self::GRANT_PAGE, false);
    }

    public static function editPageContents(Page $page): ?string
    {
        return self::check(new Checker($page), 'canEditPageContents', [], 'edit_page_contents', 'edit the content of ' . self::page($page), self::GRANT_PAGE);
    }

    public static function editPageProperties(Page $page): ?string
    {
        return self::check(new Checker($page), 'canEditPageProperties', [], 'edit_page_properties', 'change the properties (name, description, attributes) of ' . self::page($page), self::GRANT_PAGE);
    }

    public static function editPageTemplate(Page $page): ?string
    {
        return self::check(new Checker($page), 'canEditPageTemplate', [], 'edit_page_template', 'change the template of ' . self::page($page), self::GRANT_PAGE);
    }

    public static function approvePageVersions(Page $page): ?string
    {
        return self::check(new Checker($page), 'canApprovePageVersions', [], 'approve_page_versions', 'publish (approve) a version of ' . self::page($page), self::GRANT_PAGE);
    }

    public static function deletePageVersions(Page $page): ?string
    {
        return self::check(new Checker($page), 'canDeletePageVersions', [], 'delete_page_versions', 'remove versions of ' . self::page($page), self::GRANT_PAGE);
    }

    public static function deletePage(Page $page): ?string
    {
        return self::check(new Checker($page), 'canDeletePage', [], 'delete_page', 'delete ' . self::page($page), self::GRANT_PAGE);
    }

    public static function moveOrCopyPage(Page $page): ?string
    {
        return self::check(new Checker($page), 'canMoveOrCopyPage', [], 'move_or_copy_page', 'move ' . self::page($page), self::GRANT_PAGE);
    }

    /**
     * add_subpage, for a page of this type when one is given (Concrete's canAddSubCollection()).
     */
    public static function addSubpage(Page $parent, ?PageType $type = null): ?string
    {
        $what = $type !== null ? "add a page of type '" . $type->getPageTypeHandle() . "' under " : 'add a page under ';
        if ($type !== null) {
            return self::check(new Checker($parent), 'canAddSubCollection', [$type], 'add_subpage', $what . self::page($parent), self::GRANT_PAGE);
        }

        return self::check(new Checker($parent), 'canAddSubpage', [], 'add_subpage', $what . self::page($parent), self::GRANT_PAGE);
    }

    // ---- Areas and blocks -----------------------------------------------------------------------------------------

    public static function addBlockToArea(Area $area, BlockType $type): ?string
    {
        $what = "add a '" . $type->getBlockTypeHandle() . "' block to " . self::area($area);

        return self::check(new Checker($area), 'canAddBlock', [$type], 'add_block_to_area', $what, self::GRANT_PAGE . ' Areas can also have their own permissions, in edit mode.');
    }

    public static function viewBlock(Block $block): ?string
    {
        return self::check(new Checker($block), 'canViewBlock', [], 'view_block', 'read ' . self::block($block), self::GRANT_PAGE, false);
    }

    public static function editBlock(Block $block): ?string
    {
        return self::check(new Checker($block), 'canEditBlock', [], 'edit_block', 'edit ' . self::block($block), self::GRANT_PAGE . ' Blocks can also have their own permissions, in edit mode.');
    }

    public static function deleteBlock(Block $block): ?string
    {
        return self::check(new Checker($block), 'canDeleteBlock', [], 'delete_block', 'delete ' . self::block($block), self::GRANT_PAGE . ' Blocks can also have their own permissions, in edit mode.');
    }

    // ---- Stacks and global areas (P3.6a) --------------------------------------------------------------------------
    // The same keys, checked on the stack itself, and worded with the stack's name and where its permissions are set.
    // $shared is how the stack is named, as SharedArea::label() gives it: 'the global area "Header Navigation"'.

    public static function editStackContents(Page $stack, string $shared): ?string
    {
        return self::check(new Checker($stack), 'canEditPageContents', [], 'edit_page_contents', 'edit ' . $shared . ', which is shared by every page that shows it,', self::GRANT_STACK);
    }

    public static function addBlockToStack(Area $stackArea, BlockType $type, string $shared): ?string
    {
        return self::check(new Checker($stackArea), 'canAddBlock', [$type], 'add_block_to_area', "add a '" . $type->getBlockTypeHandle() . "' block to " . $shared, self::GRANT_STACK);
    }

    public static function editStackBlock(Block $block, string $shared): ?string
    {
        return self::check(new Checker($block), 'canEditBlock', [], 'edit_block', 'edit ' . self::block($block) . ' in ' . $shared, self::GRANT_STACK);
    }

    public static function deleteStackBlock(Block $block, string $shared): ?string
    {
        return self::check(new Checker($block), 'canDeleteBlock', [], 'delete_block', 'remove ' . self::block($block) . ' from ' . $shared, self::GRANT_STACK);
    }

    // ---- Files and folders ----------------------------------------------------------------------------------------

    /**
     * view_file: the user may see the file on the site (P3.3-7, Glyn: what a block that shows or links a file needs).
     */
    public static function viewFile(File $file): ?string
    {
        return self::check(new Checker($file), 'canViewFile', [], 'view_file', 'see ' . self::file($file), self::GRANT_FILE, false);
    }

    public static function viewFileInFileManager(File $file): ?string
    {
        return self::check(new Checker($file), 'canViewFileInFileManager', [], 'view_file_in_file_manager', 'see ' . self::file($file), self::GRANT_FILE, false);
    }

    public static function editFileProperties(File $file): ?string
    {
        return self::check(new Checker($file), 'canEditFileProperties', [], 'edit_file_properties', 'change the properties (title, attributes) of ' . self::file($file), self::GRANT_FILE);
    }

    public static function deleteFile(File $file): ?string
    {
        return self::check(new Checker($file), 'canDeleteFile', [], 'delete_file', 'delete ' . self::file($file), self::GRANT_FILE);
    }

    /**
     * add_file on a folder: adding files or folders to it (the file manager's own check, canAddTreeSubNode()).
     */
    public static function addFile(Node $folder): ?string
    {
        return self::check(new Checker($folder), 'canAddTreeSubNode', [], 'add_file', 'add to the folder ' . self::node($folder), self::GRANT_FILE);
    }

    /**
     * edit_file_folder on a folder; on a file's node, Concrete checks the file's edit_file_properties.
     */
    public static function editFileFolder(Node $node): ?string
    {
        return self::check(new Checker($node), 'canEditTreeNode', [], 'edit_file_folder', 'move or rename ' . self::node($node), self::GRANT_FILE);
    }

    // ---- Topics ---------------------------------------------------------------------------------------------------

    /**
     * Concrete's topic nodes have no add, edit or delete keys of their own: its checks (TopicTreeNodeResponse) are
     * access to Dashboard > System & Settings > Attributes > Topics.
     */
    public static function addTopicTreeNode(Node $parent): ?string
    {
        return self::check(new Checker($parent), 'canAddTopicTreeNode', [], null, 'add a topic under ' . self::node($parent), self::topicsGrant());
    }

    public static function editTopicTreeNode(Node $node): ?string
    {
        return self::check(new Checker($node), 'canEditTreeNode', [], null, 'rename ' . self::node($node), self::topicsGrant());
    }

    public static function deleteTopicTreeNode(Node $node): ?string
    {
        return self::check(new Checker($node), 'canDeleteTreeNode', [], null, 'delete ' . self::node($node), self::topicsGrant());
    }

    /**
     * add_topic_tree, a task permission (the check Dashboard > Topics makes before adding a tree).
     */
    public static function addTopicTree(): ?string
    {
        $key = Key::getByHandle('add_topic_tree');
        $allowed = $key !== null && (bool) $key->validate();

        return $allowed ? null : self::message('add a topic tree', self::name('add_topic_tree'), self::GRANT_TASK, true);
    }

    // ---- Users ----------------------------------------------------------------------------------------------------

    /**
     * view_user_attributes for one user attribute key, as Concrete's user search checks it per column (P3.5-2).
     */
    public static function viewUserAttribute(UserKey $key): ?string
    {
        $permission = Key::getByHandle('view_user_attributes');
        $allowed = $permission !== null && (bool) $permission->validate($key);

        return $allowed ? null : self::message(
            "read the user attribute '" . $key->getAttributeKeyHandle() . "'",
            self::name('view_user_attributes'),
            'A site administrator can grant it in Dashboard > System & Settings > Permissions & Access > User Permissions.',
            false
        );
    }

    // ---- Dashboard pages ------------------------------------------------------------------------------------------

    /**
     * view_page on a Dashboard page: Concrete's own gate for the management its page does (attributes, sets).
     */
    public static function viewDashboardPage(string $path, string $doing): ?string
    {
        $page = Page::getByPath($path);
        $label = 'Dashboard page ' . $path;
        if (!$page || $page->isError()) {
            return self::message($doing, 'View', "The page {$path} isn't on this site, so only a site administrator can do this.", true, $label);
        }
        $allowed = (bool) (new Checker($page))->canViewPage();

        return $allowed ? null : self::message(
            $doing,
            self::name('view_page'),
            "A site administrator can grant access to {$path} in that Dashboard page's Permissions.",
            true,
            $label
        );
    }

    // ---- Refusal --------------------------------------------------------------------------------------------------

    /**
     * Throws the refusal when there is one.
     *
     * @throws PermissionRefused
     */
    public static function deny(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new PermissionRefused($refusal);
        }
    }

    /**
     * The refusal's text. Public so its wording is unit-tested without Concrete.
     *
     * @param string $doing what was refused, as "edit the content of page 12 (/x)"
     * @param string $permission the permission's display name
     * @param string $grant who can grant it, and where
     * @param string|null $on the object the permission is on, when it isn't the one in $doing
     */
    public static function message(string $doing, string $permission, string $grant, bool $write = true, ?string $on = null): string
    {
        return "Permission denied: you can't {$doing}. It needs Concrete's \"{$permission}\" permission"
            . ($on !== null ? " on {$on}" : '') . ", which you don't have. {$grant} "
            . ($write
                ? "Nothing was changed. Tell the user which permission is missing; don't try another tool to make the same change."
                : "Tell the user which permission is missing; don't look for another way to read it.");
    }

    /**
     * @param list<mixed> $args
     */
    private static function check(Checker $checker, string $method, array $args, ?string $handle, string $doing, string $grant, bool $write = true): ?string
    {
        // BF-19: without the aliases loaded, Concrete refuses View and Edit Block in page-inherited areas.
        CoreAliases::load();
        if ((bool) $checker->{$method}(...$args)) {
            return null;
        }
        $permission = $handle !== null ? self::name($handle) : 'Dashboard > Topics';

        return self::message($doing, $permission, $grant, $write);
    }

    private static function name(string $handle): string
    {
        $key = Key::getByHandle($handle);
        $name = $key !== null ? (string) $key->getPermissionKeyDisplayName('text') : '';

        return $name !== '' ? $name : (self::NAMES[$handle] ?? $handle);
    }

    private static function topicsGrant(): string
    {
        return 'A site administrator can grant access to /dashboard/system/attributes/topics in that Dashboard page\'s Permissions.';
    }

    private static function page(Page $page): string
    {
        $path = (string) $page->getCollectionPath();

        return 'page ' . (int) $page->getCollectionID() . ($path !== '' ? " ({$path})" : ' (the home page)');
    }

    private static function area(Area $area): string
    {
        $page = $area->getAreaCollectionObject();

        return "the area '" . $area->getAreaHandle() . "'" . ($page instanceof Page ? ' of ' . self::page($page) : '');
    }

    private static function block(Block $block): string
    {
        return 'block ' . (int) $block->getBlockID() . " (a '" . $block->getBlockTypeHandle() . "' block)";
    }

    private static function file(File $file): string
    {
        $version = $file->getApprovedVersion();
        $title = $version !== null ? (string) $version->getTitle() : '';

        return 'file ' . (int) $file->getFileID() . ($title !== '' ? " ('{$title}')" : '');
    }

    private static function node(Node $node): string
    {
        return "'" . (string) $node->getTreeNodeDisplayName('text') . "' (node " . (int) $node->getTreeNodeID() . ')';
    }
}
