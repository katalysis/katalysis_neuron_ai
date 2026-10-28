<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Support\Facade\Application;

/**
 * The per-block knowledge the Level 0 adapter (P3.3-7, BlockFields) can't get from Concrete's export metadata, by hand
 * and kept small. What P3.3-6 and BF-17 had here for the other cases (Feature and Image link pickers, accordion entries
 * kept on a settings change, the list of save() inputs that are not columns) is now Level 0: import-mode saves take
 * the columns as stored, and declared child tables are written as rows.
 *
 * - Feature and Image: Concrete's link picker inputs ("link__which", "imageLink__which", ...), which a model may send
 *   as the block's edit form does, become the link columns import mode saves.
 * - Image: its thumbnail types (btContentImageBreakpoints) aren't exported, and its save() deletes them in every mode
 *   and re-adds only "selectedThumbnailTypes"; an update that leaves them out keeps the stored ones.
 * - Accordion: "accordionBlockData", the entries as JSON or an array (scenario 31), stays accepted and becomes
 *   btAccordionEntries rows.
 * - Express form: the form it shows (exFormID) is an Express form; changing the form's fields is Express work (Phase 6).
 * - Page List: its form-only inputs do nothing in import mode, so they are refused, naming the columns to set instead.
 */
final class BlockSaveInputs
{
    /**
     * Inputs a block takes besides its columns and declared child tables.
     *
     * @var array<string, list<string>>
     */
    private const OTHER_INPUTS = [
        'feature' => ['link__which', 'link_page', 'link_external_url'],
        'image' => ['imageLink__which', 'imageLink_page', 'imageLink_file', 'imageLink_external_url', 'selectedThumbnailTypes'],
        'accordion' => ['accordionBlockData'],
    ];

    /** Inputs that name a side table, so a change to its rows was asked for. */
    private const SIDE_TABLE_INPUTS = ['image' => ['selectedThumbnailTypes' => 'btContentImageBreakpoints']];

    /**
     * Page List's edit-form inputs, which its save() reads only from the form (not in import mode), and what to set
     * instead. RSS feed settings live in a feed entity, not in the block's columns: they wait for a descriptor (P5.2).
     */
    private const PAGE_LIST_FORM_INPUTS = [
        'topicFilter' => 'set filterByRelated (1 or 0) with relatedTopicAttributeKeyHandle, or filterByCustomTopic with customTopicAttributeKeyHandle (the topic itself, customTopicTreeNodeID, keeps its stored value)',
        'cParentIDValue' => 'set cParentID to the parent page\'s ID (0 for pages anywhere on the site)',
        'filterDatePast' => 'set filterDateOption to "past" and filterDateDays to the number of days',
        'filterDateFuture' => 'set filterDateOption to "future" and filterDateDays to the number of days',
        'rss' => null,
        'rssHandle' => null,
        'rssTitle' => null,
        'rssDescription' => null,
    ];

    /**
     * @return list<string> the inputs this block type takes besides its columns and child tables
     */
    public static function otherInputs(string $handle): array
    {
        return self::OTHER_INPUTS[$handle] ?? [];
    }

    /**
     * The side tables a value asks to change (by naming their input).
     *
     * @param array<string, mixed> $value
     *
     * @return list<string>
     */
    public static function sideTablesNamed(string $handle, array $value): array
    {
        $named = [];
        foreach (self::SIDE_TABLE_INPUTS[$handle] ?? [] as $input => $table) {
            if (array_key_exists($input, $value)) {
                $named[] = $table;
            }
        }

        return $named;
    }

    /**
     * The refusal for fields the block doesn't have, worded for the cases above, or null to use the general one.
     *
     * @param list<string> $unknown
     * @param list<string> $fields the block's columns
     */
    public static function unknownFieldRefusal(string $handle, array $unknown, array $fields): ?string
    {
        if ($handle === 'express_form') {
            return "The Express form block's settings are: " . implode(', ', $fields) . '. '
                . 'Creating or changing the form\'s fields (' . implode(', ', $unknown) . ') is not available: it is Express work, '
                . 'planned for a later phase. Tell the user it can be done in the Dashboard under System & Settings > Express. '
                . 'Nothing was changed.';
        }
        if ($handle !== 'page_list' || array_diff($unknown, array_keys(self::PAGE_LIST_FORM_INPUTS)) !== []) {
            return null;
        }
        $steps = [];
        $rss = false;
        foreach ($unknown as $input) {
            $instead = self::PAGE_LIST_FORM_INPUTS[$input];
            if ($instead === null) {
                $rss = true;
            } else {
                $steps[] = "instead of '{$input}', {$instead}";
            }
        }
        if ($rss) {
            $steps[] = 'RSS feed settings (rss, rssHandle, rssTitle, rssDescription) can\'t be changed by the assistant yet; they can be changed in edit mode';
        }

        return "The Page List block's edit-form inputs (" . implode(', ', $unknown) . ') are not its stored fields. Nothing was changed. '
            . ucfirst(implode('; ', $steps)) . '.';
    }

    /**
     * Concrete's link picker inputs, as the edit form sends them, turned into the link columns import mode saves.
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function pickerToColumns(string $handle, array $value): array
    {
        $key = match ($handle) {
            'feature' => 'link',
            'image' => 'imageLink',
            default => null,
        };
        if ($key === null || !array_key_exists("{$key}__which", $value)) {
            return $value;
        }
        $which = (string) $value["{$key}__which"];
        $value['internalLinkCID'] = $which === 'page' ? (int) ($value["{$key}_page"] ?? 0) : 0;
        $value['externalLink'] = $which === 'external_url' ? (string) ($value["{$key}_external_url"] ?? '') : '';
        if ($handle === 'image') {
            $value['fileLinkID'] = $which === 'file' ? (int) ($value["{$key}_file"] ?? 0) : 0;
        }
        foreach (["{$key}__which", "{$key}_page", "{$key}_file", "{$key}_external_url"] as $input) {
            unset($value[$input]);
        }

        return $value;
    }

    /**
     * Image's thumbnail types, kept when an update leaves them out (its save() deletes and re-adds them).
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function keepThumbnailTypes(string $handle, int $bID, array $value): array
    {
        if ($handle !== 'image' || array_key_exists('selectedThumbnailTypes', $value)) {
            return $value;
        }
        $types = [];
        foreach (self::db()->fetchAllAssociative('SELECT breakpointHandle, ftTypeID FROM btContentImageBreakpoints WHERE bID = ?', [$bID]) as $row) {
            $types[(string) $row['breakpointHandle']] = (int) $row['ftTypeID'];
        }
        if ($types !== []) {
            $value['selectedThumbnailTypes'] = $types;
        }

        return $value;
    }

    /**
     * Accordion entries sent as "accordionBlockData" (JSON or an array) as btAccordionEntries rows, or null when the
     * value doesn't send them.
     *
     * @param array<string, mixed> $value
     *
     * @return list<array{title: string, description: string}>|null
     */
    public static function accordionRows(string $handle, array $value): ?array
    {
        if ($handle !== 'accordion' || !array_key_exists('accordionBlockData', $value)) {
            return null;
        }
        $entries = $value['accordionBlockData'];
        if (is_string($entries)) {
            $entries = json_decode($entries, true);
        }
        if (!is_array($entries)) {
            throw new \RuntimeException('accordionBlockData must be a JSON array of {title, description} entries. Nothing was changed.');
        }
        $rows = [];
        foreach (array_values($entries) as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('Each accordionBlockData entry must be an object with title and description. Nothing was changed.');
            }
            $rows[] = ['title' => (string) ($entry['title'] ?? ''), 'description' => (string) ($entry['description'] ?? '')];
        }

        return $rows;
    }

    /**
     * The accordion's entries as the JSON its save() takes: [{"title": ..., "description": ...}, ...].
     */
    public static function accordionJson(int $bID): string
    {
        $entries = array_map(
            static fn (array $row): array => ['title' => (string) $row['title'], 'description' => (string) $row['description']],
            self::db()->fetchAllAssociative('SELECT title, description FROM btAccordionEntries WHERE bID = ? ORDER BY sortOrder', [$bID])
        );

        return (string) json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Accordion entries as HTML, for the text check: each title as a heading, then its description. JSON's quoting
     * and spacing then don't count as text, so a model's own JSON formatting is not a change.
     */
    public static function accordionText(string $json): string
    {
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return $json;
        }
        $html = '';
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $html .= '<h4>' . htmlspecialchars((string) ($entry['title'] ?? ''), ENT_QUOTES) . '</h4>' . (string) ($entry['description'] ?? '');
            }
        }

        return $html;
    }

    /** The Express form block may name a form only if it exists (a new block; a change of form is Express work). */
    public static function expressFormExists(string $formID): bool
    {
        return $formID !== '' && self::db()->fetchOne('SELECT id FROM ExpressForms WHERE id = ?', [$formID]) !== false;
    }

    private static function db(): \Concrete\Core\Database\Connection\Connection
    {
        return Application::getFacadeApplication()->make('database')->connection();
    }
}
