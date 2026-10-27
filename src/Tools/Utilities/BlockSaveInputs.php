<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Support\Facade\Application;

/**
 * The save() inputs of core blocks that are not columns of the block's table (P3.3-6). The write tools fill the
 * omitted fields of an update from the stored row (TextIntegrity::keepOmittedFields), but some core blocks rebuild
 * data from form inputs that no column holds, so an update that left them out lost data or failed:
 *
 * - Feature and Image read their link from Concrete's destination picker ("link__which", "imageLink__which"). Feature
 *   refused any save without it ("Please select a value for Link"); Image silently dropped its link.
 * - Image deletes its per-breakpoint thumbnail types and re-adds only "selectedThumbnailTypes".
 * - Accordion deletes its entries and re-adds only "accordionBlockData" (JSON), so a settings change emptied it.
 * - The Express form block keeps the form it shows; changing the form's fields is Express work (Phase 6).
 *
 * This is per-block knowledge, by hand, for the core blocks P3.3-6 covers. The Level 0 generic block adapter (P5.2)
 * is the general answer.
 */
final class BlockSaveInputs
{
    /** Express form inputs its save() reads that are not columns. */
    private const EXPRESS_FORM_INPUTS = ['formName', 'resultsFolder', 'addFilesToFolder'];

    /** @var array<string, list<string>> child tables by block table */
    private static array $childTables = [];

    /**
     * Refuses an update this tool can't make for the block type. The reason, or null.
     *
     * @param array<string, mixed> $record the stored row
     * @param array<string, mixed> $value the update as sent
     */
    public static function refusal(string $handle, array $record, array $value): ?string
    {
        if ($handle !== 'express_form') {
            return null;
        }
        $allowed = array_merge(array_keys($record), self::EXPRESS_FORM_INPUTS);
        $unknown = array_values(array_diff(array_map('strval', array_keys($value)), $allowed));
        if ($unknown === []) {
            return null;
        }

        return "The Express form block's settings are: " . implode(', ', array_diff(array_keys($record), ['bID'])) . '. '
            . 'Creating or changing the form\'s fields (' . implode(', ', $unknown) . ') is not available: it is Express work, '
            . 'planned for a later phase. Tell the user it can be done in the Dashboard under System & Settings > Express. '
            . 'Nothing was changed.';
    }

    /**
     * refusal() for a new block, against the columns of the block type's table.
     *
     * @param array<string, mixed> $value
     */
    public static function refusalForNew(string $handle, ?string $table, array $value): ?string
    {
        if ($handle !== 'express_form' || $table === null || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return null;
        }
        $columns = array_fill_keys(self::db()->fetchFirstColumn("SHOW COLUMNS FROM `{$table}`"), null);

        return self::refusal($handle, $columns, $value);
    }

    /**
     * The update with the save() inputs it left out filled from the stored data, so the block keeps them.
     *
     * @param array<string, mixed> $value the update, its omitted columns already filled from the stored row
     *
     * @return array<string, mixed>
     */
    public static function fillOmitted(string $handle, int $bID, array $value): array
    {
        switch ($handle) {
            case 'feature':
                return self::withLink($value, 'link', false);
            case 'image':
                $value = self::withLink($value, 'imageLink', true);
                if (!array_key_exists('selectedThumbnailTypes', $value)) {
                    $types = [];
                    foreach (self::db()->fetchAllAssociative('SELECT breakpointHandle, ftTypeID FROM btContentImageBreakpoints WHERE bID = ?', [$bID]) as $row) {
                        $types[(string) $row['breakpointHandle']] = (int) $row['ftTypeID'];
                    }
                    if ($types !== []) {
                        $value['selectedThumbnailTypes'] = $types;
                    }
                }

                return $value;
            case 'accordion':
                if (!array_key_exists('accordionBlockData', $value)) {
                    $value['accordionBlockData'] = self::accordionJson($bID);
                }

                return $value;
            default:
                return $value;
        }
    }

    /**
     * Defaults for a new block's save() inputs: no link unless the value names one.
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function fillNew(string $handle, array $value): array
    {
        return match ($handle) {
            'feature' => self::withLink($value, 'link', false),
            'image' => self::withLink($value, 'imageLink', true),
            default => $value,
        };
    }

    /**
     * Rows the block keeps in other tables named after its own (btAccordionEntries for btAccordion,
     * btContentImageBreakpoints for btContentImage), without their row and block IDs. A read that showed only the
     * main table hid an accordion's entries.
     *
     * @return array<string, list<array<string, mixed>>> table => rows
     */
    public static function childRows(string $table, int $bID): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return [];
        }
        $db = self::db();
        // The table list doesn't change within a process (the scenario snapshot asks for every block).
        self::$childTables[$table] ??= $db->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? AND TABLE_NAME LIKE ? AND TABLE_NAME <> ? ORDER BY TABLE_NAME',
            ['bID', $table . '%', $table]
        );
        $children = self::$childTables[$table];
        $rows = [];
        foreach ($children as $child) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $child) !== 1) {
                continue;
            }
            $found = $db->fetchAllAssociative("SELECT * FROM `{$child}` WHERE bID = ?", [$bID]);
            if ($found !== []) {
                $rows[(string) $child] = array_map(static function (array $row): array {
                    unset($row['id'], $row['bID']);

                    return $row;
                }, $found);
            }
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

    /**
     * The destination picker inputs for a link, from the columns the value holds (new or kept).
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function withLink(array $value, string $key, bool $hasFileLink): array
    {
        if (array_key_exists("{$key}__which", $value)) {
            return $value;
        }
        if ((int) ($value['internalLinkCID'] ?? 0) > 0) {
            $value["{$key}__which"] = 'page';
            $value["{$key}_page"] = (int) $value['internalLinkCID'];
        } elseif ($hasFileLink && (int) ($value['fileLinkID'] ?? 0) > 0) {
            $value["{$key}__which"] = 'file';
            $value["{$key}_file"] = (int) $value['fileLinkID'];
        } elseif (trim((string) ($value['externalLink'] ?? '')) !== '') {
            $value["{$key}__which"] = 'external_url';
            $value["{$key}_external_url"] = (string) $value['externalLink'];
        } else {
            $value["{$key}__which"] = 'none';
        }

        return $value;
    }

    private static function db(): \Concrete\Core\Database\Connection\Connection
    {
        return Application::getFacadeApplication()->make('database')->connection();
    }
}
