<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Settings\ApiKeySettings;
use PHPUnit\Framework\TestCase;

/**
 * P3.9-4 (found in P1.7a): the Ollama URL is stored and shown in plain text, so a URL with a user name and password in
 * it would show them on the settings page. Such a URL is refused on save, pointing to the key field.
 */
final class SettingsRefusalsTest extends TestCase
{
    public function testAUrlWithCredentialsIsRefusedWithoutRepeatingThem(): void
    {
        $refusals = ApiKeySettings::refusals(['ollama_url' => 'http://admin:s3cret-pass@ollama.example:11434']);

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('Ollama Key', $refusals[0]);
        $this->assertStringNotContainsString('s3cret-pass', $refusals[0]);
        $this->assertStringNotContainsString('admin', $refusals[0]);
    }

    public function testAUserNameAloneIsRefusedToo(): void
    {
        $this->assertCount(1, ApiKeySettings::refusals(['ollama_url' => 'https://token@ollama.example']));
    }

    public function testAPlainUrlOrNoUrlIsFine(): void
    {
        $this->assertSame([], ApiKeySettings::refusals(['ollama_url' => 'http://localhost:11434']));
        $this->assertSame([], ApiKeySettings::refusals(['ollama_url' => '']));
        $this->assertSame([], ApiKeySettings::refusals([]));
    }
}
