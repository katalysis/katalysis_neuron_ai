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
    public function testEachKindHasItsFixedText(): void
    {
        $this->assertSame(InjectionNotice::TEXT, InjectionNotice::text(InjectionNotice::SUSPECTED));
        $this->assertSame(InjectionNotice::TEXT_AFTER_WRITES, InjectionNotice::text(InjectionNotice::SUSPECTED_THEN_WRITES));
        $this->assertSame('This page contains text addressed to the AI assistant. It was not acted on.', InjectionNotice::TEXT);
    }

    public function testNoKindOrAnUnknownKindHasNoText(): void
    {
        $this->assertNull(InjectionNotice::text(null));
        $this->assertNull(InjectionNotice::text(''));
        $this->assertNull(InjectionNotice::text('anything else'));
        // The text itself is not a kind: a reply that contains the sentence never selects a notice.
        $this->assertNull(InjectionNotice::text(InjectionNotice::TEXT));
    }

    public function testHistoryJsonGetsANoticeOnFlaggedAssistantMessagesOnly(): void
    {
        $json = json_encode([
            ['role' => 'user', 'content' => 'Summarise this page.'],
            ['role' => 'assistant', 'content' => 'First reply.'],
            ['role' => 'user', 'content' => 'And now?'],
            ['role' => 'assistant', 'content' => 'Second reply.', InjectionNotice::META_KEY => InjectionNotice::SUSPECTED],
        ]);

        $messages = json_decode(InjectionNotice::annotateHistoryJson($json), true);

        $this->assertArrayNotHasKey('notice', $messages[0]);
        $this->assertArrayNotHasKey('notice', $messages[1]);
        $this->assertArrayNotHasKey('notice', $messages[2]);
        $this->assertSame(InjectionNotice::TEXT, $messages[3]['notice']);
    }

    public function testTheSecondKindGetsTheSecondText(): void
    {
        $json = json_encode([['role' => 'assistant', 'content' => 'x', InjectionNotice::META_KEY => InjectionNotice::SUSPECTED_THEN_WRITES]]);

        $this->assertSame(InjectionNotice::TEXT_AFTER_WRITES, json_decode(InjectionNotice::annotateHistoryJson($json), true)[0]['notice']);
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
