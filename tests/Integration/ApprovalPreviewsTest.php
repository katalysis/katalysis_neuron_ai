<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\FileList;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Approval\ApprovalSettings;
use Katalysis\NeuronAi\Approval\ChangePreview;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.6-2 (plans/P3.6-2.md §1, §2): every write tool's approval card says what would change, and its fingerprint stays
 * the same on a plain re-read and changes when what the change depends on changes. Previews read only: each test also
 * checks that nothing was written. Throwaway pages; no AI provider call.
 */
final class ApprovalPreviewsTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        Config::set(ApprovalSettings::BLOCK_LIMIT_KEY, null);
        $this->pages->restore();
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /** @return array{entry: array<string, mixed>, fingerprint: string} */
    private static function preview(string $tool, array $inputs): array
    {
        CacheLocal::flush();

        return ChangePreview::of($tool, $inputs);
    }

    private static function text(array $entry): string
    {
        return implode("\n", [$entry['action'] . ' ' . $entry['target'] . ' ' . $entry['path'], ...$entry['warnings'], ...$entry['details'], $entry['note']]);
    }

    private function versions(int $cID): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]);
    }

    /** A new version of the page, made outside the assistant (a person's edit). */
    private function editByHand(int $cID): void
    {
        CacheLocal::flush();
        Page::getByID($cID, 'RECENT')->getVersionToModify()->update(['cDescription' => 'By hand ' . bin2hex(random_bytes(2))]);
    }

    public function testTheBlockCardsSayWhatChangesAndTheFingerprintFollowsThePage(): void
    {
        $page = $this->pages->create('Previews blocks');
        $cID = (int) $page->getCollectionID();
        [$b1, $b2] = $this->pages->blockIDs($cID);
        $versions = $this->versions($cID);

        $cases = [
            'add_block_to_page_area' => [['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>New words</p>']], 'Add a Content block to Main on', 'content: "New words"'],
            'update_block_in_page_area' => [['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Block one new text.</p>']], 'Change the Content block in Main on', 'content: "Block one original text." → "Block one new text."'],
            'delete_block_from_page_area' => [['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b2], 'Remove the Content block in Main on', 'It shows: "Block two original text."'],
            'move_block_between_areas' => [['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $b2], 'Move the Content block from Main to Sidebar on', ''],
            'batch_update_page_content' => [['pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>x</p>']], ['areaHandle' => 'Main', 'blockID' => $b2, 'value' => ['content' => '<p>Block two original text.</p>']]]], 'Change 2 blocks on', "Main: Content (content)\nMain: Content (formatting)"],
        ];
        $before = [];
        foreach ($cases as $tool => [$inputs, $action, $detail]) {
            $first = self::preview($tool, $inputs);
            $text = self::text($first['entry']);
            $this->assertStringContainsString($action . ' Previews blocks', $text, $tool);
            $this->assertStringContainsString($detail, $text, $tool);
            $this->assertSame([], $first['entry']['warnings'], "{$tool}: no draft, no warning");
            $this->assertSame($first['fingerprint'], self::preview($tool, $inputs)['fingerprint'], "{$tool}: a re-read gives the same fingerprint");
            $before[$tool] = $first['fingerprint'];
        }
        $this->assertSame($versions, $this->versions($cID), 'previews write nothing');

        $this->editByHand($cID);
        foreach ($cases as $tool => [$inputs]) {
            $this->assertNotSame($before[$tool], self::preview($tool, $inputs)['fingerprint'], "{$tool}: a new version changes the fingerprint");
        }
    }

    public function testTheHeadingsCardListsWhatTheCallItselfWouldChange(): void
    {
        $page = $this->pages->create('Previews headings', ['<h3>First heading</h3><p>Body one.</p>', '<h3>Second heading</h3><p>Body two.</p>']);
        $cID = (int) $page->getCollectionID();

        $preview = self::preview('batch_update_content_headings', ['pageID' => $cID, 'headingTexts' => ['First heading', 'Second heading', 'Missing heading'], 'targetTag' => 'h2']);

        $entry = $preview['entry'];
        $this->assertSame('Change 2 headings in 2 blocks on', $entry['action']);
        $this->assertContains('Main (Content): "First heading" → <h2>', $entry['details']);
        $this->assertContains('Main (Content): "Second heading" → <h2>', $entry['details']);
        $this->assertContains('Not found: "Missing heading"', $entry['details']);
        $this->assertSame(1, $this->versions($cID));
    }

    /** BF-22: in scenario 17 the model set this field when asked for the meta description. */
    public function testThePageDescriptionIsLabelledSoItIsNotTakenForTheMetaDescription(): void
    {
        $page = $this->pages->create('Previews description');
        $cID = (int) $page->getCollectionID();

        $entry = self::preview('update_page', ['pageID' => $cID, 'description' => 'Neuron test page'])['entry'];

        $this->assertContains('Page description (not the meta description): (empty) → "Neuron test page"', $entry['details']);
        $this->assertSame(1, $this->versions($cID));
    }

    public function testMoreBlocksThanTheLimitAreCountedInAWarningAndStillListed(): void
    {
        $page = $this->pages->create('Previews limit', ['<p>One.</p>', '<p>Two.</p>', '<p>Three.</p>']);
        $cID = (int) $page->getCollectionID();
        $blocks = array_map(static fn (int $bID): array => ['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => '<p>Changed.</p>']], $this->pages->blockIDs($cID));

        $this->assertSame(10, ApprovalSettings::blockLimit(), 'the default');
        $this->assertSame([], self::preview('batch_update_page_content', ['pageID' => $cID, 'blocks' => $blocks])['entry']['warnings']);

        Config::set(ApprovalSettings::BLOCK_LIMIT_KEY, 2);
        $entry = self::preview('batch_update_page_content', ['pageID' => $cID, 'blocks' => $blocks])['entry'];
        $this->assertSame(['This changes 3 blocks, more than 2.'], $entry['warnings']);
        $this->assertCount(3, $entry['details'], 'every block is still listed');
    }

    public function testSomeoneElsesDraftIsNamedWithWhatItAlreadyChanges(): void
    {
        $page = $this->pages->create('Previews draft owner');
        $cID = (int) $page->getCollectionID();
        // A draft by neuron_chat_editor: the description, and one block replaced.
        ThrowawayPages::actAs('neuron_chat_editor');
        $output = ToolByName::run('update_page', ['pageID' => $cID, 'description' => 'Their description']);
        $this->assertTrue(ToolKind::succeeded($output), $output);

        ThrowawayPages::actAs('superuser');
        $entry = self::preview('update_page', ['pageID' => $cID, 'name' => 'My name'])['entry'];
        $this->assertCount(2, $entry['warnings']);
        $this->assertStringStartsWith('This page already has unpublished changes by neuron_chat_editor (version 2,', $entry['warnings'][0]);
        $this->assertStringContainsString('Your change is added to that version.', $entry['warnings'][0]);
        $this->assertSame('That version already changes: description.', $entry['warnings'][1]);

        // The author's own draft: no warning.
        ThrowawayPages::actAs('neuron_chat_editor');
        $this->assertSame([], self::preview('update_page', ['pageID' => $cID, 'name' => 'Their name'])['entry']['warnings']);
    }

    public function testTheVersionCards(): void
    {
        $page = $this->pages->create('Previews versions');
        $cID = (int) $page->getCollectionID();

        $start = self::preview('create_page_draft', ['pageID' => $cID])['entry'];
        $this->assertSame('Start an unpublished draft of', $start['action']);
        $this->assertContains('A copy of the live version, unpublished.', $start['details']);

        ThrowawayPages::actAs('neuron_chat_editor');
        ToolByName::run('update_page', ['pageID' => $cID, 'description' => 'To be discarded']);
        ThrowawayPages::actAs('superuser');

        $again = self::preview('create_page_draft', ['pageID' => $cID])['entry'];
        $this->assertStringStartsWith('The page already has a draft (version 2, by neuron_chat_editor)', $again['details'][0]);

        $discard = self::preview('discard_draft_version', ['pageID' => $cID, 'versionID' => 2]);
        $this->assertSame('Discard unpublished version 2 of', $discard['entry']['action']);
        $this->assertStringStartsWith('Made by neuron_chat_editor on ', $discard['entry']['details'][0]);
        $this->assertSame('It changes: description. Those changes are lost.', $discard['entry']['details'][1]);
        $this->assertSame($discard['fingerprint'], self::preview('discard_draft_version', ['pageID' => $cID, 'versionID' => 2])['fingerprint']);
        $this->assertSame(2, $this->versions($cID));
    }

    public function testTheFileAttributeAndTopicCards(): void
    {
        ThrowawayPages::actAs('superuser');
        $files = (new FileList())->getResults();
        $this->assertNotEmpty($files, 'the seed has a file');
        $file = $files[0];

        $set = self::preview('set_file_attribute_value', ['fileID' => (int) $file->getFileID(), 'handle' => 'width', 'value' => '1234'])['entry'];
        $this->assertStringStartsWith('Set ', $set['action']);
        $this->assertSame('Files have no draft: this is live at once.', $set['note']);

        $folder = self::preview('create_file_folder', ['name' => 'Preview folder'])['entry'];
        $this->assertSame('Create the folder', $folder['action']);
        $this->assertStringStartsWith('"Preview folder" under ', $folder['target']);

        $attribute = self::preview('create_page_attribute', ['handle' => 'preview_attr', 'name' => 'Preview Attr', 'type' => 'text'])['entry'];
        $this->assertSame('Create the page attribute', $attribute['action']);
        $this->assertSame('Preview Attr (preview_attr)', $attribute['target']);
        $this->assertContains('Type: text', $attribute['details']);

        $setCard = self::preview('create_attribute_set', ['handle' => 'preview_set', 'name' => 'Preview Set', 'category' => 'user'])['entry'];
        $this->assertSame('Create the user attribute set', $setCard['action']);

        $add = self::preview('add_attribute_to_set', ['attribute_handle' => 'meta_title', 'set_handle' => 'seo'])['entry'];
        $this->assertSame('Add the attribute meta_title to the set', $add['action']);

        $tree = self::preview('create_topic_tree', ['tree_name' => 'Preview Tree'])['entry'];
        $this->assertSame('Create the topic tree', $tree['action']);
        $topic = self::preview('create_topic', ['tree_name' => 'No Such Tree', 'topic_name' => 'Preview Topic'])['entry'];
        $this->assertSame('"Preview Topic" under a place that was not found', $topic['target']);
        $rename = self::preview('rename_topic', ['topic_id' => 999999999, 'new_name' => 'X'])['entry'];
        $this->assertSame('topic 999999999', $rename['target']);
    }

    public function testAnExternalOrNewWriteGetsTheGenericCard(): void
    {
        $entry = self::preview('add_person', ['name' => 'Jo', 'email' => 'jo@example.invalid'])['entry'];

        $this->assertSame('Run add_person', $entry['action']);
        $this->assertSame(['name: Jo', 'email: jo@example.invalid'], $entry['details']);
        $this->assertFalse(ChangePreview::hasPreview('add_person'));
    }
}
