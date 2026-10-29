<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Settings\ModelCatalog;
use PHPUnit\Framework\TestCase;

/**
 * P3.8-1: the provider and model come from the settings, and the model is checked on save (decided at P2.8's review:
 * "gpt-40" for "gpt-4o" saved with the usual message and made every turn fail with model_not_found). Checked against
 * a list of known models, so no test or save calls a provider.
 */
final class ModelSettingsTest extends TestCase
{
    public function testP28sTypoIsRefusedAndNamesTheKnownModels(): void
    {
        $refusals = ApiKeySettings::refusals(['open_ai_model' => 'gpt-40']);

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('gpt-40', $refusals[0]);
        $this->assertStringContainsString('gpt-4o', $refusals[0], 'it says which models are known');
    }

    public function testAKnownModelOfEachProviderIsAccepted(): void
    {
        $this->assertSame([], ApiKeySettings::refusals(['provider' => 'openai', 'open_ai_model' => 'gpt-4o']));
        $this->assertSame([], ApiKeySettings::refusals(['provider' => 'anthropic', 'anthropic_model' => ModelCatalog::DEFAULT_MODELS['anthropic']]));
        $this->assertSame([], ApiKeySettings::refusals(['provider' => 'ollama', 'ollama_url' => 'http://localhost:11434/api', 'ollama_model' => 'llama3.1:8b']));
    }

    public function testAnUnknownAnthropicModelOrProviderIsRefused(): void
    {
        $this->assertCount(1, ApiKeySettings::refusals(['anthropic_model' => 'claude-sonet']));
        $this->assertCount(1, ApiKeySettings::refusals(['provider' => 'mistral']));
    }

    public function testAnOllamaModelNameIsFreeButMustLookLikeOne(): void
    {
        // Local models are whatever the Ollama server has pulled, so only the shape is checked.
        $this->assertSame([], ApiKeySettings::refusals(['ollama_model' => 'qwen2.5-coder:14b']));
        $this->assertCount(1, ApiKeySettings::refusals(['ollama_model' => 'llama 3 <script>']));
    }

    public function testOllamaNeedsAUrl(): void
    {
        $this->assertCount(1, ApiKeySettings::refusals(['provider' => 'ollama', 'ollama_url' => '']));
    }

    public function testAnEmptyModelUsesTheProvidersDefault(): void
    {
        $this->assertSame([], ApiKeySettings::refusals(['open_ai_model' => '']));
        $this->assertSame('gpt-4o', ModelCatalog::modelFor('openai', ''));
        $this->assertSame('gpt-4o', ModelCatalog::modelFor('', ''), 'no provider set is OpenAI');
        $this->assertSame('gpt-4o-mini', ModelCatalog::modelFor('openai', 'gpt-4o-mini'));
    }
}
