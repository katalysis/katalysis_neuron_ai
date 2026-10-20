<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Security\SecretRedactor;
use PHPUnit\Framework\TestCase;

/**
 * P1.7a: API keys are removed from text before it is logged, returned to the model or
 * sent to the browser (B-SEC-1). No Concrete, no provider calls; the dummy keys below
 * are not real.
 */
final class SecretRedactorTest extends TestCase
{
    private const DUMMY_KEY = 'dummy-secret-ABCDEFGH-ZQ9X';

    public function testAConfiguredKeyIsRemovedEverywhereItAppears(): void
    {
        $text = 'failed with ' . self::DUMMY_KEY . ' and again ' . self::DUMMY_KEY;

        $redacted = SecretRedactor::redact($text, [self::DUMMY_KEY]);

        $this->assertStringNotContainsString(self::DUMMY_KEY, $redacted);
        $this->assertSame(2, substr_count($redacted, SecretRedactor::REPLACEMENT));
    }

    public function testAProviderErrorBodyThatQuotesAMaskedKeyIsRedacted(): void
    {
        $message = 'HTTP 401 error during POST https://api.openai.com/v1/chat/completions: '
            . '{"error":{"message":"Incorrect API key provided: sk-proj-********************ZQ9X. You can find your API key at ..."}}';

        $redacted = SecretRedactor::redact($message, []);

        $this->assertStringNotContainsString('sk-proj', $redacted);
        $this->assertStringNotContainsString('ZQ9X', $redacted);
        $this->assertStringContainsString('HTTP 401 error during POST', $redacted, 'The rest of the message must stay readable.');
    }

    public function testAnAnthropicStyleKeyAndAKeyCutShortInAStackTraceAreRedacted(): void
    {
        $trace = "#0 OpenAI->__construct('sk-ant-api03-abc...', 'gpt-4o')\n#1 sk-proj-abcdefg... in x";

        $redacted = SecretRedactor::redact($trace, []);

        $this->assertStringNotContainsString('sk-ant', $redacted);
        $this->assertStringNotContainsString('sk-proj', $redacted);
    }

    public function testABearerTokenInAQuotedHeaderIsRedacted(): void
    {
        $redacted = SecretRedactor::redact('headers: Authorization: Bearer abcDEF123456xyz789', []);

        $this->assertStringNotContainsString('abcDEF123456xyz789', $redacted);
        $this->assertStringContainsString('Bearer ' . SecretRedactor::REPLACEMENT, $redacted);
    }

    public function testAShortSecretIsIgnoredSoOrdinaryWordsAreNotRedacted(): void
    {
        $this->assertSame('a secret word', SecretRedactor::redact('a secret word', ['secret']));
    }

    public function testTextWithNoKeyIsReturnedUnchanged(): void
    {
        $text = 'Call to a member function getCollectionPath() on false in PageView.php:266 (a task-list, a disk-image)';

        $this->assertSame($text, SecretRedactor::redact($text, []));
    }
}
