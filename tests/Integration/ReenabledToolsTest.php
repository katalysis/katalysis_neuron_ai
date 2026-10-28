<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\File;
use Concrete\Core\File\FileList;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Tree\Node\Node as TreeNode;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Katalysis\NeuronAi\Approval\ChangePreview;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\DatabaseSnapshot;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.6-3 (plans/P3.6-3.md §1): move_page, delete_page and mark_file_for_deletion return behind approval. Their cards
 * name every page or file affected, their own checks refuse what can't or mustn't happen (a move under itself, a path
 * already taken, the trash can off, another folder), and a move keeps the old URLs as redirects. Throwaway pages and
 * the seed's test file, all put back. No AI provider call.
 */
final class ReenabledToolsTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    /** @var array{fID: int, folderID: int}|null the seed file's place before a test moved it */
    private ?array $movedFile = null;

    private bool $reviewFolderExisted = true;

    /** @var array<string, int> the highest ID in the tables Concrete's move and delete requests write to */
    private array $marks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new ThrowawayPages($this->db());
        // A move or delete goes through Concrete's workflow request, which records itself even when it runs at once.
        foreach (['WorkflowProgressHistory' => 'wphID', 'WorkflowRequestObjects' => 'wrID'] as $table => $column) {
            $this->marks[$table] = (int) $this->db()->fetchOne("SELECT COALESCE(MAX(`{$column}`), 0) FROM `{$table}`");
        }
        $this->reviewFolderExisted = $this->reviewFolder() !== null;
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        Config::set('concrete.misc.enable_trash_can', true);
        ThrowawayPages::actAs('superuser');
        if ($this->movedFile !== null) {
            File::getByID($this->movedFile['fID'])->getFileNodeObject()->move(FileFolder::getByID($this->movedFile['folderID']));
            $this->movedFile = null;
        }
        $folder = $this->reviewFolder();
        if (!$this->reviewFolderExisted && $folder !== null) {
            $folder->delete();
        }
        $this->pages->restore();
        $this->db()->executeStatement('DELETE FROM WorkflowProgressHistory WHERE wphID > ?', [$this->marks['WorkflowProgressHistory']]);
        $this->db()->executeStatement('DELETE FROM WorkflowRequestObjects WHERE wrID > ?', [$this->marks['WorkflowRequestObjects']]);
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function reviewFolder(): ?FileFolder
    {
        $id = $this->db()->fetchOne(
            "SELECT tn.treeNodeID FROM TreeNodes tn JOIN TreeNodeTypes tt ON tt.treeNodeTypeID = tn.treeNodeTypeID WHERE tt.treeNodeTypeHandle = 'file_folder' AND tn.treeNodeName = ?",
            [FilesToolkit::REVIEW_FOLDER]
        );
        $folder = $id === false ? null : TreeNode::getByID((int) $id);

        return $folder instanceof FileFolder ? $folder : null;
    }

    /** A page with a child, under /neuron-test. @return array{Page, Page} */
    private function pageWithChild(string $label): array
    {
        $parent = $this->pages->create($label, []);
        $child = $parent->add(\Concrete\Core\Page\Type\Type::getByHandle('page'), ['cName' => $label . ' child', 'cHandle' => 'child-' . bin2hex(random_bytes(3))], \Concrete\Core\Page\Template::getByHandle('full'));
        $this->pages->adopt((int) $child->getCollectionID());
        CacheLocal::flush();

        return [Page::getByID($parent->getCollectionID()), Page::getByID($child->getCollectionID())];
    }

    // ---- move_page --------------------------------------------------------------------------------------------

    public function testAMoveTakesTheChildrenKeepsTheOldUrlsAndTheCardNamesEveryPage(): void
    {
        [$page, $child] = $this->pageWithChild('Reenabled move');
        $target = $this->pages->create('Reenabled target', []);
        $oldPath = (string) $page->getCollectionPath();
        $inputs = ['pageID' => (int) $page->getCollectionID(), 'newParentID' => (int) $target->getCollectionID()];

        $preview = ChangePreview::of('move_page', $inputs);
        $this->assertNull($preview['refusal']);
        $this->assertStringContainsString('and the 1 page below it', $preview['entry']['action']);
        $details = implode("\n", $preview['entry']['details']);
        $this->assertStringContainsString($oldPath . ' → ' . $target->getCollectionPath() . '/' . $page->getCollectionHandle(), $details);
        $this->assertStringContainsString((string) $child->getCollectionName(), $details);
        $this->assertStringContainsString('old addresses keep working', $preview['entry']['note']);

        $output = ToolByName::run('move_page', $inputs);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        CacheLocal::flush();
        $moved = Page::getByID($page->getCollectionID());
        $this->assertSame((string) $target->getCollectionPath() . '/' . $page->getCollectionHandle(), (string) $moved->getCollectionPath());
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM PagePaths WHERE cID = ? AND cPath = ? AND ppIsCanonical = 0', [$page->getCollectionID(), $oldPath]), 'the old URL redirects');
    }

    public function testAMoveUnderItselfOrOntoATakenPathIsRefusedAndTheCardIsNotAsked(): void
    {
        [$page, $child] = $this->pageWithChild('Reenabled loop');
        $before = DatabaseSnapshot::take($this->db());

        $loop = ['pageID' => (int) $page->getCollectionID(), 'newParentID' => (int) $child->getCollectionID()];
        $refusal = ChangePreview::of('move_page', $loop)['refusal'];
        $this->assertStringContainsString("can't be moved under itself or under a page below it", (string) $refusal);
        $this->assertSame(ToolByName::run('move_page', $loop) . '. Please try a different approach or ask for clarification.', $refusal);

        $taken = $this->pages->create('Reenabled taken', []);
        $twin = $taken->add(\Concrete\Core\Page\Type\Type::getByHandle('page'), ['cName' => 'Twin', 'cHandle' => (string) $page->getCollectionHandle()], \Concrete\Core\Page\Template::getByHandle('full'));
        $this->pages->adopt((int) $twin->getCollectionID());
        $clash = ['pageID' => (int) $page->getCollectionID(), 'newParentID' => (int) $taken->getCollectionID()];
        $this->assertStringContainsString('already exists at', (string) ChangePreview::of('move_page', $clash)['refusal']);
        $this->assertFalse(ToolKind::succeeded(ToolByName::run('move_page', $clash)));

        CacheLocal::flush();
        $this->assertSame((string) $page->getCollectionPath(), (string) Page::getByID($page->getCollectionID())->getCollectionPath(), 'not moved');
        unset($before);
    }

    public function testTheMoveFingerprintChangesWhenAPageIsAddedBelow(): void
    {
        [$page] = $this->pageWithChild('Reenabled fingerprint');
        $target = $this->pages->create('Reenabled fingerprint target', []);
        $inputs = ['pageID' => (int) $page->getCollectionID(), 'newParentID' => (int) $target->getCollectionID()];
        $first = ChangePreview::of('move_page', $inputs)['fingerprint'];
        $delete = ChangePreview::of('delete_page', ['pageID' => (int) $page->getCollectionID()])['fingerprint'];
        $this->assertSame($first, ChangePreview::of('move_page', $inputs)['fingerprint']);

        $extra = $page->add(\Concrete\Core\Page\Type\Type::getByHandle('page'), ['cName' => 'Late child'], \Concrete\Core\Page\Template::getByHandle('full'));
        $this->pages->adopt((int) $extra->getCollectionID());
        CacheLocal::flush();

        $this->assertNotSame($first, ChangePreview::of('move_page', $inputs)['fingerprint']);
        $this->assertNotSame($delete, ChangePreview::of('delete_page', ['pageID' => (int) $page->getCollectionID()])['fingerprint']);
    }

    // ---- delete_page ------------------------------------------------------------------------------------------

    public function testADeleteGoesToTheTrashAndTheCardNamesEveryPage(): void
    {
        [$page, $child] = $this->pageWithChild('Reenabled delete');
        $inputs = ['pageID' => (int) $page->getCollectionID()];

        $preview = ChangePreview::of('delete_page', $inputs);
        $this->assertNull($preview['refusal']);
        $this->assertStringContainsString('and the 1 page below it to the trash', $preview['entry']['action']);
        $details = implode("\n", $preview['entry']['details']);
        $this->assertStringContainsString((string) $page->getCollectionPath(), $details);
        $this->assertStringContainsString((string) $child->getCollectionName() . ' (' . $child->getCollectionPath() . ')', $details);
        $this->assertStringContainsString('Trash', $preview['entry']['note']);

        $output = ToolByName::run('delete_page', $inputs);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        CacheLocal::flush();
        $this->assertTrue(Page::getByID($page->getCollectionID())->isInTrash());
        $this->assertStringContainsString((string) $child->getCollectionPath(), $output, 'the result names the page below too');
    }

    public function testWithTheTrashCanOffNothingIsDeleted(): void
    {
        [$page] = $this->pageWithChild('Reenabled no trash');
        Config::set('concrete.misc.enable_trash_can', false);
        $inputs = ['pageID' => (int) $page->getCollectionID()];

        $refusal = ChangePreview::of('delete_page', $inputs)['refusal'];
        $this->assertStringContainsString('the trash can is off', (string) $refusal);
        $output = ToolByName::run('delete_page', $inputs);
        $this->assertFalse(ToolKind::succeeded($output));
        CacheLocal::flush();
        $page = Page::getByID($page->getCollectionID());
        $this->assertFalse($page->isError());
        $this->assertFalse($page->isInTrash());
    }

    public function testADeleteNeedsTheDeletePermissionOnEveryPageBelow(): void
    {
        [$page, $child] = $this->pageWithChild('Reenabled delete rights');
        $grants = new \Katalysis\NeuronAi\Tests\Support\PageGrantFixture($this->app());
        try {
            $this->grant($page, 'delete_page', 'neuron_content_editor');
            $grants->takeAway((int) $child->getCollectionID(), 'delete_page', ['neuron_content_editor']);
            ThrowawayPages::actAs('neuron_content_editor');
            $this->app()->make('cache/request')->flush();
            $inputs = ['pageID' => (int) $page->getCollectionID()];

            $refusal = ChangePreview::of('delete_page', $inputs)['refusal'];
            $this->assertStringContainsString((string) $child->getCollectionPath(), (string) $refusal, 'it names the page below');
            $this->assertStringContainsString('nothing was deleted', strtolower((string) $refusal));
            $this->assertFalse(ToolKind::succeeded(ToolByName::run('delete_page', $inputs)));
            CacheLocal::flush();
            $this->assertFalse(Page::getByID($page->getCollectionID())->isInTrash());
        } finally {
            ThrowawayPages::actAs('superuser');
            $this->pages->restore();
            $grants->restore();
            foreach ($this->accesses as $paID) {
                \Katalysis\NeuronAi\Tests\Support\PermissionCleanup::deleteAccessIfUnused($this->db(), $paID);
            }
            $this->accesses = [];
        }
    }

    /** @var list<int> accesses made by grant() */
    private array $accesses = [];

    /** Gives $userName the page key on $page (its own permissions, a copy of its access with the user added). */
    private function grant(Page $page, string $handle, string $userName): void
    {
        ThrowawayPages::actAs('superuser');
        $page->setPermissionsToOverride();
        $key = \Concrete\Core\Permission\Key\Key::getByHandle($handle);
        $key->setPermissionObject($page);
        $access = $key->getPermissionAccessObject()->duplicate();
        $this->accesses[] = (int) $access->getPermissionAccessID();
        $access->addListItem(\Concrete\Core\Permission\Access\Entity\UserEntity::getOrCreate($this->app()->make(\Concrete\Core\User\UserInfoRepository::class)->getByUserName($userName)));
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $page->refreshCache();
        $this->app()->make('cache/request')->flush();
    }

    // ---- mark_file_for_deletion -------------------------------------------------------------------------------

    private function seedFile(): \Concrete\Core\Entity\File\File
    {
        $list = new FileList();
        $list->filterByKeywords('Neuron Test File');
        foreach ($list->getResults() as $file) {
            if ($file->getApprovedVersion()->getTitle() === 'Neuron Test File') {
                return $file;
            }
        }
        $this->markTestSkipped('The seed has no "Neuron Test File". Run the seed script first.');
    }

    public function testAFileIsMovedToTheReviewFolderWhichIsCreatedIfMissingAndNothingIsDeleted(): void
    {
        $file = $this->seedFile();
        $fID = (int) $file->getFileID();
        $this->movedFile = ['fID' => $fID, 'folderID' => (int) $file->getFileFolderObject()->getTreeNodeID()];

        $preview = ChangePreview::of('mark_file_for_deletion', ['fileID' => (string) $fID]);
        $this->assertNull($preview['refusal']);
        $this->assertStringContainsString(FilesToolkit::REVIEW_FOLDER, $preview['entry']['action'] . ' ' . $preview['entry']['target']);
        $this->assertStringContainsString('Nothing is deleted', $preview['entry']['note']);

        $output = ToolByName::run('mark_file_for_deletion', ['fileID' => (string) $fID]);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertNotNull($this->reviewFolder(), 'the folder exists now');
        $this->assertSame((int) $this->reviewFolder()->getTreeNodeID(), (int) File::getByID($fID)->getFileFolderObject()->getTreeNodeID());
        $this->assertNotNull(File::getByID($fID), 'not deleted');

        $again = ChangePreview::of('mark_file_for_deletion', ['fileID' => (string) $fID])['refusal'];
        $this->assertStringContainsString('already in', (string) $again);
    }

    public function testAnyOtherFolderIsRefused(): void
    {
        $file = $this->seedFile();
        $root = (new \Concrete\Core\File\Filesystem())->getRootFolder();
        $inputs = ['fileID' => (string) $file->getFileID(), 'folderID' => (int) $root->getTreeNodeID()];

        $refusal = ChangePreview::of('mark_file_for_deletion', $inputs)['refusal'];
        $this->assertStringContainsString('only moves files to', (string) $refusal);
        // FilesToolkit answers a refusal with its {ok: false} envelope, which is what the model gets.
        $this->assertSame(ToolByName::run('mark_file_for_deletion', $inputs), $refusal);
        $this->assertSame((int) $file->getFileFolderObject()->getTreeNodeID(), (int) File::getByID($file->getFileID())->getFileFolderObject()->getTreeNodeID());
    }
}
