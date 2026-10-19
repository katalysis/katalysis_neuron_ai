<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi;

use Concrete\Core\Page\Page;

/**
 * The line that names the page the user is on, put at the start of every user message (P1.9, B-CTX-1).
 *
 * The system prompt already names the page, but the chat history also holds the earlier turns' tool results
 * (get_current_page_context with the old page's ID and path), and the model sometimes answers from those. The tag
 * puts the page into the user's own turn, so the newest message always says where the user is now.
 *
 * Built by fixed code from the resolved page object, never from text the browser sent. It is stored in the chat
 * history, because the model needs it on later turns, and removed again wherever history is shown to people.
 */
final class PageContextTag
{
    private const PREFIX = '[Current page for this message: ';
    private const SUFFIX = '. Earlier messages may refer to other pages.]';
    private const SEPARATOR = "\n\n";
    private const MAX_FIELD = 120;

    /**
     * The message with the tag in front, or unchanged when there is no page to name.
     * Anything the user typed stays as it is, including text that looks like a tag.
     *
     * @param array<string, mixed>|null $context the page context the endpoint resolved (id and path)
     */
    public static function apply(string $message, ?array $context): string
    {
        $page = self::resolvePage($context);
        if ($page === null) {
            return $message;
        }

        return self::forPage($page) . self::SEPARATOR . $message;
    }

    /**
     * The page the context points to: by ID first, then by path, as the agent's instructions do. Null when the
     * context is empty or names no page, so that no tag is added. (Unlike the instructions, this never falls
     * back to "the current request's page", which for a chat endpoint is not the page the user is on.)
     *
     * @param array<string, mixed>|null $context
     */
    public static function resolvePage(?array $context): ?Page
    {
        if (!is_array($context)) {
            return null;
        }

        $id = $context['id'] ?? null;
        if (is_numeric($id)) {
            $page = Page::getByID((int) $id, 'ACTIVE');
            if ($page && !$page->isError()) {
                return $page;
            }
        }

        $path = $context['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $page = Page::getByPath($path, 'ACTIVE');
            if ($page && !$page->isError()) {
                return $page;
            }
        }

        return null;
    }

    public static function forPage(Page $page): string
    {
        return self::build(
            (int) $page->getCollectionID(),
            (string) $page->getCollectionName(),
            (string) $page->getCollectionPath()
        );
    }

    /**
     * The tag text for a page. The name and path can contain anything an editor typed, so they are made single-line
     * and lose their square brackets: that way they can't close the tag early or add a second one.
     */
    public static function build(int $id, string $name, string $path): string
    {
        return self::PREFIX . self::clean($name) . ', ' . self::clean($path) . ', ID ' . $id . self::SUFFIX;
    }

    /**
     * Removes the tag the endpoint added from the start of a user message. Only the first tag, and only at the very
     * start, is removed, so a tag-like text the user typed after it is shown.
     */
    public static function strip(string $text): string
    {
        $pattern = '/\A' . preg_quote(self::PREFIX, '/') . '[^\[\]\r\n]*' . preg_quote(self::SUFFIX, '/') . preg_quote(self::SEPARATOR, '/') . '/u';

        return preg_replace($pattern, '', $text, 1) ?? $text;
    }

    /**
     * The stored chat history (JSON) as people should see it: the tag taken out of every user message.
     * Anything that isn't a JSON list of messages is returned as it was.
     */
    public static function stripFromHistoryJson(string $json): string
    {
        $messages = json_decode($json, true);
        if (!is_array($messages) || $messages === []) {
            return $json;
        }

        foreach ($messages as $i => $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $messages[$i]['content'] = self::strip($content);
            } elseif (is_array($content) && isset($content[0]) && is_array($content[0]) && is_string($content[0]['content'] ?? null)) {
                $messages[$i]['content'][0]['content'] = self::strip($content[0]['content']);
            }
        }

        $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? $json : $encoded;
    }

    private static function clean(string $value): string
    {
        // Control characters, including line breaks, become spaces; runs of spaces collapse.
        $value = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s{2,}/u', ' ', $value) ?? '');
        $value = strtr($value, ['[' => '(', ']' => ')']);

        return mb_substr($value, 0, self::MAX_FIELD);
    }
}
