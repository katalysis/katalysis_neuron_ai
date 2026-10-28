<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Approval\CallCheck;
use Katalysis\NeuronAi\Approval\ChangePreview;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\DatabaseSnapshot;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3.6-2 (Glyn, after scenarios 36 and 29): a call its own checks would refuse is not put to the user. Each
 * draft-writing tool runs its checks in a dry run that stops where the draft would be made: with good inputs it passes
 * and writes nothing (the whole database is compared, as the leak check does); with bad inputs it gives exactly the
 * refusal the real call gives. Tools without a draft use their preview's reads. Throwaway pages; no AI provider call.
 */
final class CallCheckTest extends ConcreteTestCase
{
    private const SUFFIX = '. Please try a different approach or ask for clarification.';

    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    public static function toolProvider(): array
    {
        return array_combine(array_keys(CallCheck::DRY_RUN), array_map(static fn (string $t): array => [$t], array_keys(CallCheck::DRY_RUN)));
    }

    /**
     * Good and bad inputs for each dry-run tool, on a page with two Content blocks (one an h3 heading).
     *
     * @return array{good: array<string, mixed>, bad: array<string, mixed>}
     */
    private function cases(string $tool, int $cID, int $b1, int $b2): array
    {
        return match ($tool) {
            'update_page' => ['good' => ['pageID' => $cID, 'description' => 'Checked'], 'bad' => ['pageID' => 999999999, 'description' => 'Checked']],
            'set_page_attribute_value' => ['good' => ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Checked'], 'bad' => ['pageID' => $cID, 'handle' => 'no_such_attribute', 'value' => 'x']],
            'add_block_to_page_area' => ['good' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>New.</p>']], 'bad' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'no_such_type', 'value' => ['content' => 'x']]],
            'update_block_in_page_area' => ['good' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Block one original text. More.</p>']], 'bad' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Other words.</p>']]],
            'delete_block_from_page_area' => ['good' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1], 'bad' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => 999999999]],
            'move_block_between_areas' => ['good' => ['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $b1], 'bad' => ['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => 999999999]],
            'batch_update_page_content' => ['good' => ['pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Block one original text. More.</p>']]]], 'bad' => ['pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => 0, 'value' => ['content' => 'x']]]]],
            'batch_update_content_headings' => ['good' => ['pageID' => $cID, 'headingTexts' => ['Checked heading'], 'targetTag' => 'h2'], 'bad' => ['pageID' => $cID, 'headingTexts' => []]],
        };
    }

    #[DataProvider('toolProvider')]
    public function testTheDryRunPassesGoodInputsWritingNothingAndGivesTheRealRefusalForBadOnes(string $tool): void
    {
        $page = $this->pages->create('Call check ' . $tool, ['<p>Block one original text.</p>', '<h3>Checked heading</h3><p>Body.</p>']);
        $cID = (int) $page->getCollectionID();
        [$b1, $b2] = $this->pages->blockIDs($cID);
        $cases = $this->cases($tool, $cID, $b1, $b2);

        $before = DatabaseSnapshot::take($this->db());
        CacheLocal::flush();
        $this->assertNull(CallCheck::dryRun($tool, $cases['good']), "{$tool}: good inputs pass");
        $this->assertSame([], DatabaseSnapshot::differences($before, DatabaseSnapshot::take($this->db())), "{$tool}: the dry run wrote nothing");

        CacheLocal::flush();
        $refusal = CallCheck::dryRun($tool, $cases['bad']);
        $this->assertNotNull($refusal, "{$tool}: bad inputs are refused");
        $real = ToolByName::run($tool, $cases['bad']);
        $this->assertFalse(ToolKind::succeeded($real), "{$tool}: the real call fails too: {$real}");
        $this->assertSame(str_starts_with($real, 'Error executing tool: ') ? $real . self::SUFFIX : $real, $refusal, "{$tool}: the same words as the real call");
    }

    public function testToolsWithoutADraftSayFromTheirReadsWhenTheyWouldBeRefused(): void
    {
        ThrowawayPages::actAs('superuser');
        $parent = Page::getByPath('/neuron-test');

        $cases = [
            'create_page' => ['parentID' => (int) $parent->getCollectionID(), 'name' => 'Move Target'],
            'create_topic' => ['tree_name' => 'No Such Tree', 'topic_name' => 'X'],
            'rename_topic' => ['topic_id' => 999999999, 'new_name' => 'X'],
            'set_file_attribute_value' => ['fileID' => 999999999, 'handle' => 'title', 'value' => 'x'],
            'create_page_attribute' => ['handle' => 'meta_title', 'name' => 'Meta Title', 'type' => 'text'],
            'add_attribute_to_set' => ['attribute_handle' => 'meta_title', 'set_handle' => 'no_such_set', 'category' => 'collection'],
        ];
        foreach ($cases as $tool => $inputs) {
            CacheLocal::flush();
            $refusal = ChangePreview::of($tool, $inputs)['refusal'];
            $real = ToolByName::run($tool, $inputs);
            $this->assertFalse(ToolKind::succeeded($real), "{$tool}: {$real}");
            $this->assertSame($real . self::SUFFIX, $refusal, "{$tool}: the same words as the real call");
        }

        $this->assertNull(ChangePreview::of('create_page', ['parentID' => (int) $parent->getCollectionID(), 'name' => 'Call check new page'])['refusal']);
    }
}
