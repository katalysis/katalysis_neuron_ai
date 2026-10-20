<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Security;

/**
 * Removes API keys from text before it is logged, returned to the model or sent to the
 * browser (BEHAVIOUR_SPEC B-SEC-1).
 *
 * The AI providers can quote part of a key back in an error body (for example a 401
 * that says "Incorrect API key provided: sk-proj-****abcd"), and Neuron puts that body
 * into the exception message. Stack traces can show the first characters of a key
 * passed as an argument.
 */
final class SecretRedactor
{
    public const REPLACEMENT = '[redacted]';

    /** Config keys that hold secrets. */
    private const SECRET_CONFIG_KEYS = [
        'katalysis.ai.open_ai_key',
        'katalysis.ai.anthropic_key',
        'katalysis.ai.ollama_key',
    ];

    /** Shorter values would redact ordinary words. */
    private const MIN_SECRET_LENGTH = 8;

    /**
     * @param array<int, string>|null $secrets values to remove; null reads the configured API keys
     */
    public static function redact(string $text, ?array $secrets = null): string
    {
        $secrets ??= self::configuredSecrets();

        foreach ($secrets as $secret) {
            if (strlen($secret) >= self::MIN_SECRET_LENGTH) {
                $text = str_replace($secret, self::REPLACEMENT, $text);
            }
        }

        // Key-shaped strings, whole or masked or cut short (OpenAI "sk-...", Anthropic "sk-ant-...").
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9_\-*.]{3,}/', self::REPLACEMENT, $text);

        // Bearer tokens in a quoted header.
        return (string) preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/=\-*]{6,}/i', '$1' . self::REPLACEMENT, $text);
    }

    /**
     * @return array<int, string>
     */
    private static function configuredSecrets(): array
    {
        $secrets = [];
        try {
            foreach (self::SECRET_CONFIG_KEYS as $configKey) {
                $value = trim((string) \Concrete\Core\Support\Facade\Config::get($configKey, ''));
                if ($value !== '') {
                    $secrets[] = $value;
                }
            }
        } catch (\Throwable) {
            // Concrete isn't available (a unit test, or very early boot). The patterns below still apply.
        }

        return $secrets;
    }
}
