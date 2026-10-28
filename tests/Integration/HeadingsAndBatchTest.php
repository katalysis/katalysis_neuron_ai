<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.3-1: the batch tools say honestly what they did.
 *
 * - a batch in which every item failed is not a success (`ok: false`), so no_write_tool_failed and the injection
 *   notice see it (BF-3 plan §9; B-TRU-2)
 * - batch_update_content_headings changes every matching heading in a block, not only the first (scenarios 05, 06
 *   and 23; BF-7, BF-10), and bold_paragraph mode without headingTexts converts every bold paragraph with no PHP
 *   warning (P1.12)
 *
 * Throwaway pages under /neuron-test, written as the superuser and deleted again. No AI provider call.
 */
final class HeadingsAndBatchTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
        $this->app()->make('cache/request')->flush();
    }

    public function testABatchWhereEveryItemFailedIsNotASuccess(): void
    {
        $page = $this->pages->create('Batch all fail');
        $cID = (int) $page->getCollectionID();
        [$first, $second] = $this->pages->blockIDs($cID);
        $changed = static fn (int $bID): array => ['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => '<p>Different words.</p>']];

        // formatting_only refuses a change of text: both items are refused.
        $allFailed = $this->tool('batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [$changed($first), $changed($second)],
            'changeMode' => 'formatting_only',
        ]);
        $this->assertFalse(ToolKind::succeeded($allFailed), $allFailed);
        $this->assertSame(2, json_decode($allFailed, true)['summary']['failureCount'], $allFailed);

        // One refused, one kept: a partial success, as before.
        $partial = $this->tool('batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [
                $changed($first),
                ['areaHandle' => 'Main', 'blockID' => $second, 'value' => ['content' => '<p><strong>Block two original text.</strong></p>']],
            ],
            'changeMode' => 'formatting_only',
        ]);
        $this->assertTrue(ToolKind::succeeded($partial), $partial);
    }

    public function testHeadingsBatchWithNothingChangedIsNotASuccess(): void
    {
        $page = $this->pages->create('Headings none found', ['<h3>Present Heading</h3><p>Body.</p>']);
        $cID = (int) $page->getCollectionID();

        $notFound = $this->tool('batch_update_content_headings', ['pageID' => $cID, 'headingTexts' => ['Absent Heading'], 'targetTag' => 'h2']);
        $this->assertFalse(ToolKind::succeeded($notFound), $notFound);

        $noBold = $this->tool('batch_update_content_headings', ['pageID' => $cID, 'matchMode' => 'bold_paragraph']);
        $this->assertFalse(ToolKind::succeeded($noBold), $noBold);
    }

    public function testEveryMatchingHeadingInABlockIsChanged(): void
    {
        $page = $this->pages->create('Two h3 in one block', [
            '<h3>Heading Alpha</h3><p>Alpha body.</p><h3>Heading Beta</h3><p>Beta body.</p>',
        ]);
        $cID = (int) $page->getCollectionID();

        $output = $this->tool('batch_update_content_headings', [
            'pageID' => $cID,
            'headingTexts' => ['Heading Alpha', 'Heading Beta'],
            'targetTag' => 'h2',
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame([], json_decode($output, true)['notFoundHeadings'], $output);
        $draft = $this->content($cID, 'RECENT');
        $this->assertStringContainsString('<h2>Heading Alpha</h2>', $draft);
        $this->assertStringContainsString('<h2>Heading Beta</h2>', $draft);
        $this->assertStringNotContainsString('<h3', $draft);
        $this->assertStringContainsString('<h3>Heading Beta</h3>', $this->content($cID, 'ACTIVE'), 'The live version changed');
        // Glyn, after P3.3-1's run: the result says what changed in words the model can repeat.
        $this->assertSame('Changed 2 headings to <h2>: "Heading Alpha", "Heading Beta".', json_decode($output, true)['changed'] ?? null, $output);
    }

    public function testExplicitModeWithoutTextsSaysWhereToFindThemAndWhatBoldParagraphIsFor(): void
    {
        $page = $this->pages->create('Explicit without texts', ['<h3>Some Heading</h3><p>Body.</p>']);

        // Scenario 05 (P3.3-1 run): after this refusal the model fell back to bold_paragraph, which changed the
        // page's bold paragraphs and left the h3 headings alone.
        $refused = $this->tool('batch_update_content_headings', ['pageID' => (int) $page->getCollectionID(), 'targetTag' => 'h2']);

        $this->assertFalse(ToolKind::succeeded($refused), $refused);
        $this->assertStringContainsString('exact heading texts', $refused);
        $this->assertStringContainsString('get_page_content', $refused);
        $this->assertStringContainsString('bold_paragraph is only for converting bold paragraphs into headings, never for changing the level of existing headings', $refused);
    }

    public function testEveryBoldParagraphInABlockIsChanged(): void
    {
        $page = $this->pages->create('Two bold paragraphs in one block', [
            '<p><strong>Bold One</strong></p><p>First body.</p><p><strong>Bold Two</strong></p><p>Second body.</p>',
        ]);
        $cID = (int) $page->getCollectionID();

        // P1.12: this call raised "foreach() argument must be of type array|object, null given". Any warning fails.
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });
        try {
            $output = $this->tool('batch_update_content_headings', ['pageID' => $cID, 'matchMode' => 'bold_paragraph']);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $draft = $this->content($cID, 'RECENT');
        $this->assertStringContainsString('<h3>Bold One</h3>', $draft);
        $this->assertStringContainsString('<h3>Bold Two</h3>', $draft);
        $this->assertStringNotContainsString('<strong>', $draft);
        $this->assertSame(
            'Converted 2 bold paragraphs to <h3>: "Bold One", "Bold Two". No existing headings were changed.',
            json_decode($output, true)['changed'] ?? null,
            $output
        );
    }

    private function tool(string $name, array $inputs): string
    {
        ThrowawayPages::actAs('superuser');

        return ToolByName::run($name, $inputs); // P3.3-4: whichever of the split toolkits has it
    }

    /** The Content blocks' stored text in Main of a version of the page. */
    private function content(int $cID, string $version): string
    {
        CacheLocal::flush();
        $page = Page::getByID($cID, $version);
        $html = '';
        foreach ($page->getBlocks('Main') as $block) {
            $html .= (string) $block->getController()->content;
        }

        return $html;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
