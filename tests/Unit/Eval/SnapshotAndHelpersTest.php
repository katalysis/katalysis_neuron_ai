<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\Html;
use Katalysis\NeuronAi\Tests\Eval\Snapshot;
use Katalysis\NeuronAi\Tests\Eval\ToolCall;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

final class SnapshotAndHelpersTest extends TestCase
{
    public function testFindsPagesByPathAndRecordedChildren(): void
    {
        $snap = S::make([
            S::page(10, '/neuron-test', ['children' => [11, 12]]),
            S::page(11, '/neuron-test/a'),
            S::page(12, '/neuron-test/b'),
        ]);

        $this->assertSame(11, $snap->pageByPath('/neuron-test/a')['id']);
        $this->assertSame(11, $snap->pageByPath('neuron-test/a/')['id'], 'paths are normalised');
        $this->assertNull($snap->pageByPath('/missing'));
        $this->assertSame([11, 12], $snap->childrenOf('/neuron-test'));
        $this->assertNull($snap->childrenOf('/missing'));
    }

    public function testIdenticalSnapshotsHaveNoDifferences(): void
    {
        $a = S::make([S::page(10, '/neuron-test')]);
        $b = S::make([S::page(10, '/neuron-test')]);

        $this->assertTrue($b->identicalTo($a));
        $this->assertSame([], $b->changedPageIDs($a));
    }

    public function testEditedAddedAndRemovedPagesAreChanged(): void
    {
        $before = S::make([S::page(10, '/p'), S::page(11, '/q'), S::page(12, '/r')]);
        $after = S::make([
            S::page(10, '/p', ['versions' => 2, 'latestVersion' => 2]), // edited
            S::page(12, '/r'),                                           // untouched
            S::page(13, '/s'),                                           // added
        ]);                                                              // 11 removed

        $this->assertSame([10, 11, 13], $after->changedPageIDs($before));
        $lines = implode('; ', $after->describeDifferences($before));
        $this->assertStringContainsString('/s (added)', $lines);
        $this->assertStringContainsString('/q (removed)', $lines);
    }

    public function testAChangeInsideABlockIsDetected(): void
    {
        $before = S::make([S::page(10, '/p', ['latestBlocks' => [S::block('content', '<h3>A</h3>')]])]);
        $after = S::make([S::page(10, '/p', ['latestBlocks' => [S::block('content', '<h2>A</h2>')]])]);

        $this->assertSame([10], $after->changedPageIDs($before));
    }

    public function testAttributeAndMoveChangesAreDetected(): void
    {
        $before = S::make([S::page(10, '/p')]);

        $this->assertSame([10], S::make([S::page(10, '/p', ['attributes' => ['meta_title' => 'X']])])->changedPageIDs($before));
        $this->assertSame([10], S::make([S::page(10, '/q/p', ['parentID' => 5])])->changedPageIDs($before));
        $this->assertSame([10], S::make([S::page(10, '/!trash/p', ['isTrash' => true])])->changedPageIDs($before));
    }

    public function testAddingAChildDoesNotChangeTheParent(): void
    {
        $before = S::make([S::page(10, '/parent', ['children' => []])]);
        $after = S::make([S::page(10, '/parent', ['children' => [11]]), S::page(11, '/parent/new')]);

        $this->assertSame([11], $after->changedPageIDs($before));
    }

    public function testFileAndPeopleDifferences(): void
    {
        $file = ['id' => 1, 'title' => 'T', 'folder' => 'Docs'];
        $before = S::make([], [$file], ['a@x' => ['name' => 'A']]);

        $this->assertFalse(S::make([], [$file], ['a@x' => ['name' => 'A']])->filesDiffer($before));
        $this->assertTrue(S::make([], [['folder' => 'Other'] + $file], ['a@x' => ['name' => 'A']])->filesDiffer($before));
        $this->assertTrue(S::make([], [$file], ['a@x' => ['name' => 'B']])->peopleDiffer($before));
        $this->assertSame([$file], $before->filesByTitle('T'));
    }

    public function testHtmlTextIgnoresTagsButKeepsWords(): void
    {
        $this->assertSame(Html::text('<h3>Title</h3><p>Body <b>text</b></p>'), Html::text('<h2>Title</h2><p>Body <b>text</b></p>'));
        $this->assertSame('Title Body text', Html::text("<h2>Title</h2>\n<p>Body <b>text</b></p>"));
        $this->assertNotSame(Html::text('<p>One</p>'), Html::text('<p>Two</p>'));
    }

    public function testHtmlHeadings(): void
    {
        $this->assertSame(
            [['text' => 'First one', 'level' => 2], ['text' => 'Second', 'level' => 3]],
            Html::headings('<p>x</p><h2>First <em>one</em></h2><h3> Second </h3>')
        );
        $this->assertSame([], Html::headings(''));
        $this->assertSame([], Html::headings('<p><strong>Bold, not a heading</strong></p>'));
    }

    public function testWriteToolsAreEverythingThatIsNotAReadName(): void
    {
        foreach (['get_current_page_context', 'list_pages', 'search_blocks_by_type', 'count_blocks_by_type', 'describe_block_type'] as $name) {
            $this->assertFalse(ToolCall::isWrite($name), $name);
        }
        foreach (['create_page', 'update_page', 'delete_page', 'move_page', 'add_block_to_page_area', 'batch_update_page_content', 'set_user_attribute_value', 'getaway_tool', 'anything_new'] as $name) {
            $this->assertTrue(ToolCall::isWrite($name), $name);
        }
    }

    public function testToolSuccessFollowsTheEnvelope(): void
    {
        $this->assertTrue(ToolCall::succeeded('{"ok":true,"data":{"id":1}}'));
        $this->assertFalse(ToolCall::succeeded('{"ok":false,"error":"nope"}'));
        $this->assertFalse(ToolCall::succeeded('Error executing tool: Authentication required'));
        $this->assertFalse(ToolCall::succeeded(''));
        $this->assertFalse(ToolCall::succeeded('{"error":"something"}'));
        $this->assertTrue(ToolCall::succeeded('plain text result'));
    }
}
