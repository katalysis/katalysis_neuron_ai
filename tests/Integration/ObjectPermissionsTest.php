<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\File\File;
use Concrete\Core\File\Filesystem;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Tests\Support\AreaGrantFixture;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PermissionCleanup;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;

/**
 * P3.5-1: every ObjectPermissions check, allowed and refused, with Concrete's own permissions.
 *
 * - neuron_editor holds the seed's /neuron-test rights (view, view versions, edit contents, edit properties, add
 *   subpage, approve) and nothing else, so delete, delete versions, move and template are refused
 * - a guest is refused the block, area and file checks; the super user passes everything, as in Concrete
 * - Dashboard access (attributes, topics, the add_topic_tree task) is the super user's only on the dev site
 *
 * A throwaway page under /neuron-test, deleted again. No AI provider call.
 */
final class ObjectPermissionsTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;
    private AreaGrantFixture $areas;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $this->areas = new AreaGrantFixture($this->app());
    }

    protected function tearDown(): void
    {
        $this->areas->restore();
        $this->pages->restore();
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function asGuest(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        $this->app()->make('cache/request')->flush();
    }

    private function assertRefused(?string $refusal, string $permission, bool $write = true): void
    {
        $this->assertNotNull($refusal, "expected a refusal naming \"{$permission}\"");
        $this->assertStringStartsWith('Permission denied: ', $refusal);
        $this->assertStringContainsString("\"{$permission}\"", $refusal);
        $this->assertStringContainsString('site administrator', $refusal);
        $write
            ? $this->assertStringContainsString('Nothing was changed', $refusal)
            : $this->assertStringNotContainsString('Nothing was changed', $refusal);
    }

    private function block(Page $page): Block
    {
        $bID = $this->pages->blockIDs((int) $page->getCollectionID())[0];

        return Block::getByID($bID, Page::getByID((int) $page->getCollectionID(), 'RECENT'), 'Main');
    }

    private function seedFile(): FileEntity
    {
        $fID = (int) $this->db()->fetchOne("SELECT MIN(fID) FROM FileVersions WHERE fvTitle = 'Neuron Test File'");
        if ($fID === 0) {
            $this->markTestSkipped('The seed\'s "Neuron Test File" is not on this site: run the seed.');
        }

        return File::getByID($fID);
    }

    public function testPageChecksFollowTheEditorsGrants(): void
    {
        $page = $this->pages->create('Object permissions, page');
        ThrowawayPages::actAs('neuron_editor');
        $page = Page::getByID((int) $page->getCollectionID(), 'RECENT');

        foreach (['viewPage', 'viewPageVersions', 'editPageContents', 'editPageProperties', 'approvePageVersions', 'addSubpage'] as $check) {
            $this->assertNull(ObjectPermissions::$check($page), "{$check} should be allowed for neuron_editor");
        }

        $this->assertRefused(ObjectPermissions::editPageTemplate($page), 'Change Page Template');
        $this->assertRefused(ObjectPermissions::deletePageVersions($page), 'Delete Versions');
        $this->assertRefused(ObjectPermissions::deletePage($page), 'Delete');
        $this->assertRefused(ObjectPermissions::moveOrCopyPage($page), 'Move or Copy Page');
        $this->assertStringContainsString('page ' . $page->getCollectionID() . ' (' . $page->getCollectionPath() . ')', (string) ObjectPermissions::deletePage($page));
    }

    public function testASubpageOfATypeUsesThePageTypeCheckAndTheSuperUserPassesEverything(): void
    {
        $page = $this->pages->create('Object permissions, super');
        $type = PageType::getByHandle('page');

        $this->assertNull(ObjectPermissions::addSubpage($page, $type));
        foreach (['editPageTemplate', 'deletePageVersions', 'deletePage', 'moveOrCopyPage'] as $check) {
            $this->assertNull(ObjectPermissions::$check($page), "{$check} should be allowed for the super user");
        }
    }

    public function testReadRefusalsSayNothingAboutChanges(): void
    {
        $page = $this->pages->create('Object permissions, read');
        $this->asGuest();
        $page = Page::getByID((int) $page->getCollectionID(), 'RECENT');

        $refusal = ObjectPermissions::viewPageVersions($page);
        $this->assertRefused($refusal, 'View Versions', false);
    }

    public function testBlockChecksAllowTheEditorAndRefuseAGuest(): void
    {
        $page = $this->pages->create('Object permissions, block');
        ThrowawayPages::actAs('neuron_editor');
        $block = $this->block($page);

        $this->assertNull(ObjectPermissions::viewBlock($block));
        $this->assertNull(ObjectPermissions::editBlock($block));
        $this->assertNull(ObjectPermissions::deleteBlock($block));

        $this->asGuest();
        $block = $this->block($page);
        $this->assertRefused(ObjectPermissions::editBlock($block), 'Edit Block');
        $this->assertRefused(ObjectPermissions::deleteBlock($block), 'Delete Block');
        $this->assertStringContainsString("a 'content' block", (string) ObjectPermissions::editBlock($block));
    }

    public function testAddBlockToAreaFollowsTheAreasGrant(): void
    {
        $page = $this->pages->create('Object permissions, area');
        $cID = (int) $page->getCollectionID();
        $content = BlockType::getByHandle('content');

        ThrowawayPages::actAs('neuron_editor');
        $refusal = ObjectPermissions::addBlockToArea(Area::get(Page::getByID($cID, 'RECENT'), 'Main'), $content);
        $this->assertRefused($refusal, 'Add Block to Area');
        $this->assertStringContainsString("add a 'content' block to the area 'Main' of page {$cID}", (string) $refusal);

        $this->areas->allowUserToAddBlocks($cID, 'neuron_editor');
        ThrowawayPages::actAs('neuron_editor');
        $this->assertNull(ObjectPermissions::addBlockToArea(Area::get(Page::getByID($cID, 'RECENT'), 'Main'), $content));
    }

    public function testFileAndFolderChecksAllowTheSuperUserAndRefuseAGuest(): void
    {
        ThrowawayPages::actAs('superuser');
        $file = $this->seedFile();
        $root = $this->app()->make(Filesystem::class)->getRootFolder();
        foreach (['viewFileInFileManager', 'editFileProperties', 'deleteFile'] as $check) {
            $this->assertNull(ObjectPermissions::$check($file), "{$check} should be allowed for the super user");
        }
        $this->assertNull(ObjectPermissions::addFile($root));
        $this->assertNull(ObjectPermissions::editFileFolder($root));

        $this->asGuest();
        $file = $this->seedFile();
        $this->assertRefused(ObjectPermissions::viewFileInFileManager($file), 'View File in File Manager', false);
        $this->assertRefused(ObjectPermissions::editFileProperties($file), 'Edit File Properties');
        $this->assertRefused(ObjectPermissions::deleteFile($file), 'Delete File');
        $this->assertStringContainsString("file {$file->getFileID()} ('Neuron Test File')", (string) ObjectPermissions::deleteFile($file));
        $this->assertRefused(ObjectPermissions::addFile($root), 'Add File');
    }

    public function testDashboardAndTopicChecksAreTheSuperUsersOnTheDevSite(): void
    {
        ThrowawayPages::actAs('superuser');
        $this->assertNull(ObjectPermissions::viewDashboardPage('/dashboard/pages/attributes', 'create a page attribute'));
        $this->assertNull(ObjectPermissions::addTopicTree());

        ThrowawayPages::actAs('neuron_editor');
        $refusal = ObjectPermissions::viewDashboardPage('/dashboard/pages/attributes', 'create a page attribute');
        $this->assertRefused($refusal, 'View');
        $this->assertStringContainsString('on Dashboard page /dashboard/pages/attributes', (string) $refusal);
        $this->assertRefused(ObjectPermissions::addTopicTree(), 'Add Topic Tree');

        ThrowawayPages::actAs('superuser');
        $missing = ObjectPermissions::viewDashboardPage('/dashboard/no-such-page', 'do x');
        $this->assertNotNull($missing, 'a Dashboard page that is not there refuses even the super user');
        $this->assertStringContainsString("isn't on this site", $missing);
    }

    public function testTopicNodeChecksAreAccessToTheTopicsDashboardPage(): void
    {
        ThrowawayPages::actAs('superuser');
        // A new tree copies its root's permissions into a new access, which deleting the tree leaves behind.
        $lastAccess = (int) $this->db()->fetchOne('SELECT COALESCE(MAX(paID), 0) FROM PermissionAccess');
        $tree = TopicTree::add('Neuron Test Topics ' . bin2hex(random_bytes(3)));
        try {
            $root = $tree->getRootTreeNodeObject();
            $this->assertNull(ObjectPermissions::addTopicTreeNode($root));
            $this->assertNull(ObjectPermissions::editTopicTreeNode($root));
            $this->assertNull(ObjectPermissions::deleteTopicTreeNode($root));

            ThrowawayPages::actAs('neuron_editor');
            $this->assertRefused(ObjectPermissions::addTopicTreeNode($root), 'Dashboard > Topics');
            $this->assertRefused(ObjectPermissions::editTopicTreeNode($root), 'Dashboard > Topics');
            $this->assertRefused(ObjectPermissions::deleteTopicTreeNode($root), 'Dashboard > Topics');
        } finally {
            ThrowawayPages::actAs('superuser');
            $tree->delete();
            foreach ($this->db()->fetchFirstColumn('SELECT paID FROM PermissionAccess WHERE paID > ?', [$lastAccess]) as $paID) {
                PermissionCleanup::deleteAccessIfUnused($this->db(), (int) $paID);
            }
        }
    }
}
