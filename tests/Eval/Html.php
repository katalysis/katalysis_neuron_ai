<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * Small HTML helpers for the scenario checks.
 */
final class Html
{
    /**
     * The text of some HTML with tags removed. Tags become spaces, so
     * "<h3>A</h3><p>b</p>" and "<h2>A</h2><p>b</p>" give the same text.
     */
    public static function text(string $html): string
    {
        $text = preg_replace('/<[^>]*>/', ' ', $html) ?? $html;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::collapse($text);
    }

    public static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @return list<array{text: string, level: int}>
     */
    public static function headings(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $headings = [];
        foreach ((new \DOMXPath($doc))->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $node) {
            $headings[] = [
                'text' => self::collapse($node->textContent),
                'level' => (int) substr($node->nodeName, 1),
            ];
        }

        return $headings;
    }
}
