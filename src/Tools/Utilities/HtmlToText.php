<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * HTML to plain text converter for LLM consumption
 * 
 * Based on Macareux MCP server implementation
 * @see https://github.com/MacareuxDigital/concretecms-mcp-server/blob/main/src/tools/htmlToText.ts
 */
class HtmlToText
{
    /**
     * Block-level elements that should cause line breaks
     */
    private const BLOCK_ELEMENTS = [
        'p', 'div', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'blockquote',
        'pre', 'section', 'article', 'header', 'footer', 'nav', 'aside'
    ];

    /**
     * Common HTML entities
     */
    private const ENTITIES = [
        '&amp;' => '&',
        '&lt;' => '<',
        '&gt;' => '>',
        '&quot;' => '"',
        '&apos;' => "'",
        '&nbsp;' => ' ',
        '&mdash;' => '—',
        '&ndash;' => '–',
        '&hellip;' => '…',
        '&copy;' => '©',
        '&reg;' => '®',
        '&trade;' => '™',
    ];

    /**
     * Convert HTML to plain text
     * 
     * @param string $html Raw HTML content
     * @return string Plain text with preserved structure
     */
    public static function convert(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Insert newlines before block elements
        $blockPattern = '<(' . implode('|', self::BLOCK_ELEMENTS) . ')[\s>]';
        $text = preg_replace('/' . $blockPattern . '/i', "\n$0", $html);

        // Strip all HTML tags
        $text = strip_tags($text ?? '');

        // Decode HTML entities
        $text = self::decodeEntities($text);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text ?? ''); // Multiple spaces/tabs to single space
        $text = preg_replace('/\n\s+/', "\n", $text ?? ''); // Remove leading spaces on lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text ?? ''); // Max 2 consecutive newlines

        return trim($text);
    }

    /**
     * Decode HTML entities
     * 
     * @param string $text Text with HTML entities
     * @return string Decoded text
     */
    private static function decodeEntities(string $text): string
    {
        // First, handle common entities explicitly
        $text = str_replace(array_keys(self::ENTITIES), array_values(self::ENTITIES), $text);

        // Then use html_entity_decode for numeric and other entities
        // ENT_QUOTES handles both single and double quotes
        // ENT_HTML5 handles HTML5 entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $text;
    }

    /**
     * Extract text preview from HTML (first N characters)
     * 
     * @param string $html Raw HTML content
     * @param int $maxLength Maximum length of preview
     * @return string Text preview
     */
    public static function preview(string $html, int $maxLength = 180): string
    {
        $text = self::convert($html);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '...';
    }
}
