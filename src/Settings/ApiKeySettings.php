<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Settings;

use Concrete\Core\Config\Repository\Repository;

/**
 * Reads and saves the Neuron AI settings, with API keys that never leave the server
 * (BEHAVIOUR_SPEC B-SEC-1, CLAUDE.md rule 9).
 *
 * The settings page only ever learns whether a key is set. A saved key is replaced
 * only by a new non-empty value, and cleared only by the explicit remove flag, so
 * saving the form with the key field left blank keeps the key.
 */
final class ApiKeySettings
{
    /** Form field names of the secret values. Each is also the config key under CONFIG_PREFIX. */
    public const KEY_FIELDS = ['open_ai_key', 'anthropic_key', 'ollama_key'];

    /** Form field names of the non-secret values. `provider` (P3.8-1): openai, anthropic or ollama (ModelCatalog). */
    public const PLAIN_FIELDS = ['provider', 'open_ai_model', 'anthropic_model', 'ollama_url', 'ollama_model'];

    /** The form field `remove_<key field>` clears that key when it is set to a non-empty value. */
    public const REMOVE_PREFIX = 'remove_';

    /** The refusals (refusals()), in English; the settings page translates them. */
    public const OLLAMA_URL_CREDENTIALS = 'The Ollama URL can\'t contain a user name or password: it is shown in plain text on this page. Put the key in the Ollama Key field and save the URL without it. Nothing was saved.';

    private const CONFIG_PREFIX = 'katalysis.ai.';

    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * Whether each key is set. Never returns any part of a key.
     *
     * @return array<string, bool> key field => is set
     */
    public function keyStatus(): array
    {
        $status = [];
        foreach (self::KEY_FIELDS as $field) {
            $status[$field] = trim((string) $this->config->get(self::CONFIG_PREFIX . $field, '')) !== '';
        }

        return $status;
    }

    /**
     * The non-secret values, for display.
     *
     * @return array<string, string> plain field => value
     */
    public function plainValues(): array
    {
        $values = [];
        foreach (self::PLAIN_FIELDS as $field) {
            $values[$field] = (string) $this->config->get(self::CONFIG_PREFIX . $field, '');
        }

        return $values;
    }

    /**
     * Why a posted form can't be saved, as messages for the settings page; [] when it can. Checked before save(), so a
     * refused form changes nothing. A message never repeats a secret.
     *
     * - The Ollama URL (P3.9-4, found in P1.7a): stored and shown in plain text, so a user name or password in it would
     *   be shown on this page. Credentials go in the Ollama Key field.
     * - The provider and models (P3.8-1): a provider ModelCatalog doesn't know, or a model it doesn't know for its
     *   provider (P2.8's "gpt-40"), is refused, naming the known ones. An empty model means the provider's default.
     *   Ollama needs a URL when it is chosen.
     *
     * @param array<string, mixed> $post the posted fields
     * @return list<string>
     */
    public static function refusals(array $post): array
    {
        $refusals = [];
        $url = self::string($post['ollama_url'] ?? '');
        if ($url !== '' && (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]*@~i', $url) === 1)) {
            $refusals[] = self::OLLAMA_URL_CREDENTIALS;
        }

        $provider = self::string($post['provider'] ?? '');
        if ($provider !== '' && !ModelCatalog::isProvider($provider)) {
            $refusals[] = sprintf('There is no AI provider "%s". Choose %s. Nothing was saved.', $provider, implode(', ', ModelCatalog::LABELS));
        }
        foreach (ModelCatalog::MODEL_FIELDS as $modelProvider => $field) {
            $model = self::string($post[$field] ?? '');
            if ($model !== '' && !ModelCatalog::isModel($modelProvider, $model)) {
                $known = ModelCatalog::MODELS[$modelProvider];
                $refusals[] = $known === null
                    ? sprintf('"%s" isn\'t a model name %s accepts: use the name the Ollama server lists (letters, digits, and . _ - : /). Nothing was saved.', $model, ModelCatalog::LABELS[$modelProvider])
                    : sprintf('"%s" isn\'t one of the %s models this assistant knows. Choose one of: %s. Nothing was saved.', $model, ModelCatalog::LABELS[$modelProvider], implode(', ', $known));
            }
        }
        if ($provider === 'ollama' && $url === '') {
            $refusals[] = 'Ollama needs its URL (for example http://localhost:11434/api). Nothing was saved.';
        }

        return $refusals;
    }

    /**
     * P3.8-1: a provider chosen without its key would fail every turn, so it is refused unless the key is saved or
     * posted in the same form (and not removed). Ollama's key is optional. Never names the key's value.
     *
     * @param array<string, mixed> $post the posted fields
     */
    public function keyRefusal(array $post): ?string
    {
        $provider = self::string($post['provider'] ?? '');
        if ($provider === '' || $provider === 'ollama' || !ModelCatalog::isProvider($provider)) {
            return null;
        }
        $field = ModelCatalog::KEY_FIELDS[$provider];
        $removed = self::string($post[self::REMOVE_PREFIX . $field] ?? '') !== '';
        $hasKey = self::string($post[$field] ?? '') !== '' || ($this->keyStatus()[$field] ?? false);
        if ($hasKey && !$removed) {
            return null;
        }

        return sprintf('%s needs its key: enter it before choosing %s. Nothing was saved.', ModelCatalog::LABELS[$provider], ModelCatalog::LABELS[$provider]);
    }

    /**
     * Saves a posted form.
     *
     * A key that is both given a new value and marked for removal is removed, and the
     * new value is not saved. The caller tells the user, so it is returned.
     *
     * @param array<string, mixed> $post the posted fields
     *
     * @return array<int, string> the key fields that were removed although a new value was posted too
     */
    public function save(array $post): array
    {
        foreach (self::PLAIN_FIELDS as $field) {
            $this->config->save(self::CONFIG_PREFIX . $field, self::string($post[$field] ?? ''));
        }

        $removedWithNewValue = [];
        foreach (self::KEY_FIELDS as $field) {
            $value = self::string($post[$field] ?? '');

            if (self::string($post[self::REMOVE_PREFIX . $field] ?? '') !== '') {
                $this->config->save(self::CONFIG_PREFIX . $field, '');
                if ($value !== '') {
                    $removedWithNewValue[] = $field;
                }

                continue;
            }

            if ($value !== '') {
                $this->config->save(self::CONFIG_PREFIX . $field, $value);
            }
        }

        return $removedWithNewValue;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
