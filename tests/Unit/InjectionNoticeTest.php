<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Security\InjectionNotice;
use PHPUnit\Framework\TestCase;

/**
 * P1.11: the fixed notice wording and how it is attached to stored chat history. No Concrete.
 */
final class InjectionNoticeTest extends TestCase
{
    public function testEachKindAndSourceHasItsFixedText(): void
    {
        $this->assertSame(
            "Some content the assistant read (a person's profile) contains text addressed to the AI assistant. It was not acted on.",
            InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_PERSON)
        );
        $this->assertSame(
            'Some content the assistant read (this page) contains text addressed to the AI assistant. It was not acted on.',
            InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_PAGE)
        );
        $this->assertSame(
            'Some content the assistant read (a block) contains text addressed to the AI assistant. It was not acted on.',
            InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_BLOCK)
        );
        $this->assertSame(
            'Some content the assistant read (a file) contains text addressed to the AI assistant. It was not acted on.',
            InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_FILE)
        );
    }

    public function testTheChangesMadeAfterwardsNoticeNamesTheSourceToo(): void
    {
        $this->assertSame(
            "Some content the assistant read (a person's profile) contained text addressed to the AI assistant, and changes were made afterwards in this reply. Please review them.",
            InjectionNotice::text(InjectionNotice::SUSPECTED_THEN_WRITES, InjectionNotice::SOURCE_PERSON)
        );
        $this->assertSame(
            'Some content the assistant read contained text addressed to the AI assistant, and changes were made afterwards in this reply. Please review them.',
            InjectionNotice::text(InjectionNotice::SUSPECTED_THEN_WRITES)
        );
    }

    public function testWithoutAKnownSourceTheWordingIsGeneric(): void
    {
        $generic = 'Some content the assistant read contains text addressed to the AI assistant. It was not acted on.';

        $this->assertSame($generic, InjectionNotice::text(InjectionNotice::SUSPECTED));
        $this->assertSame($generic, InjectionNotice::text(InjectionNotice::SUSPECTED, null));
        $this->assertSame($generic, InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_OTHER));
        $this->assertSame($generic, InjectionNotice::text(InjectionNotice::SUSPECTED, 'something stored by a later version'));
    }

    public function testTheSourceIsMappedFromTheToolName(): void
    {
        $expected = [
            'get_page_content' => InjectionNotice::SOURCE_PAGE,
            'get_page' => InjectionNotice::SOURCE_PAGE,
            'list_pages' => InjectionNotice::SOURCE_PAGE,
            'get_current_page_context' => InjectionNotice::SOURCE_PAGE,
            'list_people' => InjectionNotice::SOURCE_PERSON,
            'get_person' => InjectionNotice::SOURCE_PERSON,
            'get_block_data' => InjectionNotice::SOURCE_BLOCK,
            'get_block_controller_data' => InjectionNotice::SOURCE_BLOCK,
            'list_page_blocks_by_type' => InjectionNotice::SOURCE_BLOCK,
            'search_blocks_by_type' => InjectionNotice::SOURCE_BLOCK,
            'list_files' => InjectionNotice::SOURCE_FILE,
            'get_file_details' => InjectionNotice::SOURCE_FILE,
            'list_reviews' => InjectionNotice::SOURCE_OTHER,
            'get_user' => InjectionNotice::SOURCE_OTHER,
            '' => InjectionNotice::SOURCE_OTHER,
        ];

        foreach ($expected as $tool => $source) {
            $this->assertSame($source, InjectionNotice::sourceForTool($tool), (string) $tool);
        }
    }

    public function testNoKindOrAnUnknownKindHasNoText(): void
    {
        $this->assertNull(InjectionNotice::text(null));
        $this->assertNull(InjectionNotice::text('', InjectionNotice::SOURCE_PAGE));
        $this->assertNull(InjectionNotice::text('anything else'));
        // The text itself is not a kind: a reply that contains the sentence never selects a notice.
        $this->assertNull(InjectionNotice::text((string) InjectionNotice::text(InjectionNotice::SUSPECTED)));
    }

    public function testHistoryJsonGetsANoticeOnFlaggedAssistantMessagesOnly(): void
    {
        $json = json_encode([
            ['role' => 'user', 'content' => 'Summarise this page.'],
            ['role' => 'assistant', 'content' => 'First reply.'],
            ['role' => 'user', 'content' => 'And now?'],
            ['role' => 'assistant', 'content' => 'Second reply.', InjectionNotice::META_KEY => InjectionNotice::SUSPECTED, InjectionNotice::META_SOURCE_KEY => InjectionNotice::SOURCE_PERSON],
        ]);

        $messages = json_decode(InjectionNotice::annotateHistoryJson($json), true);

        $this->assertArrayNotHasKey('notice', $messages[0]);
        $this->assertArrayNotHasKey('notice', $messages[1]);
        $this->assertArrayNotHasKey('notice', $messages[2]);
        $this->assertSame(InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_PERSON), $messages[3]['notice']);
    }

    public function testAMessageStoredBeforeTheSourceExistedGetsTheGenericWording(): void
    {
        $json = json_encode([['role' => 'assistant', 'content' => 'x', InjectionNotice::META_KEY => InjectionNotice::SUSPECTED]]);

        $this->assertSame(InjectionNotice::text(InjectionNotice::SUSPECTED), json_decode(InjectionNotice::annotateHistoryJson($json), true)[0]['notice']);
    }

    public function testTheSecondKindGetsTheSecondText(): void
    {
        $json = json_encode([['role' => 'assistant', 'content' => 'x', InjectionNotice::META_KEY => InjectionNotice::SUSPECTED_THEN_WRITES, InjectionNotice::META_SOURCE_KEY => InjectionNotice::SOURCE_FILE]]);

        $this->assertSame(
            InjectionNotice::text(InjectionNotice::SUSPECTED_THEN_WRITES, InjectionNotice::SOURCE_FILE),
            json_decode(InjectionNotice::annotateHistoryJson($json), true)[0]['notice']
        );
    }

    public function testAUserMessageCannotCarryANotice(): void
    {
        $json = json_encode([['role' => 'user', 'content' => 'x', InjectionNotice::META_KEY => InjectionNotice::SUSPECTED]]);

        $this->assertSame($json, InjectionNotice::annotateHistoryJson($json));
    }

    public function testOldHistoryWithoutTheKeyAndOddInputAreReturnedAsTheyWere(): void
    {
        $old = json_encode([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello', 'usage' => null]]);

        $this->assertSame($old, InjectionNotice::annotateHistoryJson($old));
        $this->assertSame('', InjectionNotice::annotateHistoryJson(''));
        $this->assertSame('not json', InjectionNotice::annotateHistoryJson('not json'));
        $this->assertSame('[]', InjectionNotice::annotateHistoryJson('[]'));
    }
}
