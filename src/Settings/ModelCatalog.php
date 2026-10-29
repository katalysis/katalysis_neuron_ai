<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Settings;

/**
 * The providers and models the settings page offers (P3.8-1). A model is checked against this list on save, so a typo
 * (P2.8: "gpt-40") is refused instead of making every turn fail with the provider's model_not_found; no save calls a
 * provider. Ollama's models are whatever its server has pulled, so only their shape is checked.
 *
 * The scenarios are compared on gpt-4o (DELIVERY_PLAN §7 decision 2); another model needs its own comparison run.
 * Update the lists when a provider adds or retires a model.
 */
final class ModelCatalog
{
    public const DEFAULT_PROVIDER = 'openai';

    /** Provider => its known models; null where any well-formed name is allowed. */
    public const MODELS = [
        'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-5', 'gpt-5-mini', 'o4-mini'],
        'anthropic' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'],
        'ollama' => null,
    ];

    public const DEFAULT_MODELS = [
        'openai' => 'gpt-4o',
        'anthropic' => 'claude-sonnet-5-5',
        'ollama' => 'llama3.1:8b',
    ];

    /** The settings field holding each provider's model. */
    public const MODEL_FIELDS = ['openai' => 'open_ai_model', 'anthropic' => 'anthropic_model', 'ollama' => 'ollama_model'];

    /** The settings field holding each provider's key; Ollama's is optional. */
    public const KEY_FIELDS = ['openai' => 'open_ai_key', 'anthropic' => 'anthropic_key', 'ollama' => 'ollama_key'];

    public const LABELS = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'ollama' => 'Ollama'];

    /** An Ollama model name: letters, digits and . _ - : / only. */
    private const OLLAMA_MODEL = '~^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$~';

    /** The provider a setting names; nothing set is OpenAI. */
    public static function provider(?string $setting): string
    {
        $setting = trim((string) $setting);

        return $setting === '' ? self::DEFAULT_PROVIDER : $setting;
    }

    /** The model to use: the setting, or the provider's default when it is empty. */
    public static function modelFor(?string $provider, ?string $setting): string
    {
        $setting = trim((string) $setting);

        return $setting !== '' ? $setting : self::DEFAULT_MODELS[self::provider($provider)] ?? self::DEFAULT_MODELS[self::DEFAULT_PROVIDER];
    }

    public static function isProvider(string $provider): bool
    {
        return array_key_exists($provider, self::MODELS);
    }

    public static function isModel(string $provider, string $model): bool
    {
        if (!self::isProvider($provider)) {
            return false;
        }
        $known = self::MODELS[$provider];

        return $known === null ? preg_match(self::OLLAMA_MODEL, $model) === 1 : in_array($model, $known, true);
    }
}
