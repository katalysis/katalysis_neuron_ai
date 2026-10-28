<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * BF-7, Glyn's browser check 6 (chat 3995): a hand edit in edit mode stored a Content block with CR LF line breaks.
 * get_page_content returned it JSON-encoded ("<\/h2>\r\n\r\n<p>"), the model copied that text into
 * update_block_in_page_area, and the text check passed it: the draft showed literal "<\/h2>\r\n" and broken markup.
 * Replayed here with the recorded values, without an AI call, on a throwaway page.
 */
final class EscapedContentTest extends ConcreteTestCase
{
    private const STORED_CRLF = "<h2>Seed Heading Two Changed</h2>\r\n\r\n<p>This paragraph sits under the h2 heading.</p>\r\n\r\n"
        . "<h3>Seed Heading Three A</h3>\r\n\r\n<p>Text under the first h3 heading.</p>\r\n\r\n"
        . "<h3>Seed Heading Three B</h3>\r\n\r\n<p>Text under the second h3 heading.</p>\r\n";

    /** The model's write in chat 3995, exactly. */
    private const CORRUPT = '<h2>Seed Heading Two Changed Again!<\/h2>\r\n\r\n<p>This paragraph sits under the h2 heading.<\/p>\r\n\r\n'
        . '<h3>Seed Heading Three A<\/h3>\r\n\r\n<p>Text under the first h3 heading.<\/p>\r\n\r\n'
        . '<h3>Seed Heading Three B<\/h3>\r\n\r\n<p>Text under the second h3 heading.<\/p>';

    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->app()->make(Connection::class));
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
    }

    private function crlfPage(): int
    {
        $cID = (int) $this->pages->create('Escaped content', [self::STORED_CRLF, '<p>Block two original text.</p>'])->getCollectionID();
        ThrowawayPages::actAs('superuser');

        return $cID;
    }

    public function testTheReadGivesTheModelNoEscapedHtmlToCopy(): void
    {
        $cID = $this->crlfPage();

        $output = ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => 'recent']);

        $this->assertStringNotContainsString('<\/', $output);
        $html = (string) json_decode($output, true)['html'];
        $this->assertStringContainsString('<h2>Seed Heading Two Changed</h2><p>This paragraph sits under the h2 heading.</p>', $html);
        $this->assertStringNotContainsString("\n", $html);
    }

    public function testTheCorruptWriteIsRefusedAndNothingChanges(): void
    {
        $cID = $this->crlfPage();
        [$b1] = $this->pages->blockIDs($cID);
        $before = $this->pages->state($cID);

        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => self::CORRUPT],
            'changeMode' => 'scoped_text', 'textFields' => ['content'], 'replacedText' => ['Seed Heading Two Changed'],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString("Field 'content' contains JSON escape sequences", $output);
        $this->assertSame($before, $this->pages->state($cID), 'no draft, no change');
    }

    public function testTheSameEditAsHtmlWorks(): void
    {
        $cID = $this->crlfPage();
        [$b1] = $this->pages->blockIDs($cID);

        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1,
            'value' => ['content' => str_replace('Seed Heading Two Changed', 'Seed Heading Two Changed Again!', self::STORED_CRLF)],
            'changeMode' => 'scoped_text', 'textFields' => ['content'], 'replacedText' => ['Seed Heading Two Changed'],
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
    }

    public function testTheBatchToolRefusesItToo(): void
    {
        $cID = $this->crlfPage();
        [$b1] = $this->pages->blockIDs($cID);

        $output = ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_page_content', [
            'pageID' => $cID, 'changeMode' => 'scoped_text',
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => self::CORRUPT], 'textFields' => ['content'], 'replacedText' => ['Seed Heading Two Changed']]],
        ]);

        $result = json_decode($output, true);
        $this->assertIsArray($result, $output);
        $this->assertSame(0, $result['summary']['successCount'], $output);
        $this->assertStringContainsString('JSON escape sequences', (string) $result['blocks'][0]['error']);
        $this->assertArrayNotHasKey('versionState', $result);
    }

    public function testAddingABlockWithEscapedOrBrokenHtmlIsRefused(): void
    {
        $cID = $this->crlfPage();
        $before = $this->pages->state($cID);

        foreach (['<h1>A further New Page Test<\/h1>', '<h1>A further New Page Test<p>Body</p>'] as $content) {
            $output = ThrowawayPages::runTool(new ContentToolkit(), 'add_block_to_page_area', [
                'pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => $content],
            ]);

            $this->assertFalse(ToolKind::succeeded($output), $output);
            $this->assertStringContainsString('Nothing was changed', $output);
        }
        $this->assertSame($before, $this->pages->state($cID));
    }
}
