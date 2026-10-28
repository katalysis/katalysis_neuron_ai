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
 * BF-17: an update or a new block may name only the block's fields: its table's columns and, for the blocks below,
 * the save() inputs that are not columns. Anything else was dropped by save() while the tool reported success
 * (P3.S1's scenario 30). A block type whose save() is Concrete's own (BlockController::save()) stores only columns;
 * one with its own save() that isn't listed here isn't checked yet.
 *
 * This is per-block knowledge, by hand, for the core blocks P3.3-6 covers. The Level 0 generic block adapter (P3.3-7)
 * is the general answer, and replaces these lists.
 */
final class BlockSaveInputs
{
    /** Express form inputs its save() reads that are not columns. */
    private const EXPRESS_FORM_INPUTS = ['formName', 'resultsFolder', 'addFilesToFolder'];

    /**
     * BF-17: the save() inputs that are not columns, for each block type whose save() is known (read from Concrete
     * 9.5.5's controllers). A type listed with none takes its columns only.
     *
     * @var array<string, list<string>>
     */
    private const OTHER_INPUTS = [
        'content' => [],
        'html' => [],
        'image' => ['imageLink__which', 'imageLink_page', 'imageLink_file', 'imageLink_external_url', 'selectedThumbnailTypes'],
        'feature' => ['link__which', 'link_page', 'link_external_url'],
        'accordion' => ['accordionBlockData'],
        'page_list' => ['cParentIDValue', 'filterDateFuture', 'filterDatePast', 'rss', 'rssDescription', 'rssHandle', 'rssTitle', 'topicFilter'],
        'express_form' => self::EXPRESS_FORM_INPUTS,
    ];

    /** @var array<string, list<string>> child tables by block table */
    private static array $childTables = [];

    /**
     * Refuses an update this tool can't make for the block type: a field the block doesn't have (BF-17), or a change
     * to an Express form's fields. The reason, or null.
     *
     * @param array<string, mixed> $record the stored row (its keys are the table's columns)
     * @param array<string, mixed> $value the update as sent
     * @param object|null $controller the block's controller, to tell Concrete's own save() from the block's
     */
    public static function refusal(string $handle, array $record, array $value, ?object $controller = null): ?string
    {
        if ($record === []) {
            return null;
        }
        $others = self::OTHER_INPUTS[$handle] ?? null;
        if ($others === null) {
            if ($controller === null || !self::savesColumnsOnly($controller)) {
                return null;
            }
            $others = [];
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($value)), array_keys($record), $others));
        if ($unknown === []) {
            return null;
        }
        $fields = array_values(array_diff(array_keys($record), ['bID']));

        if ($handle === 'express_form') {
            return "The Express form block's settings are: " . implode(', ', $fields) . '. '
                . 'Creating or changing the form\'s fields (' . implode(', ', $unknown) . ') is not available: it is Express work, '
                . 'planned for a later phase. Tell the user it can be done in the Dashboard under System & Settings > Express. '
                . 'Nothing was changed.';
        }

        $quoted = implode(', ', array_map(static fn (string $f): string => "'{$f}'", $unknown));

        return "The {$handle} block has no field {$quoted}. Nothing was changed. Its fields are: " . implode(', ', $fields)
            . ($others === [] ? '' : ' (its save also takes: ' . implode(', ', $others) . ')')
            . '. Send the change again using those field names; get_block_controller_data shows a block\'s current values.';
    }

    /**
     * refusal() for a new block, against the columns of the block type's table.
     *
     * @param array<string, mixed> $value
     */
    public static function refusalForNew(string $handle, ?string $table, array $value, ?object $controller = null): ?string
    {
        if ($table === null || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return null;
        }
        $columns = array_fill_keys(self::db()->fetchFirstColumn("SHOW COLUMNS FROM `{$table}`"), null);

        return self::refusal($handle, $columns, $value, $controller);
    }

    /** A block type whose save() is Concrete's own stores its table's columns and nothing else. */
    private static function savesColumnsOnly(object $controller): bool
    {
        return method_exists($controller, 'save')
            && (new \ReflectionMethod($controller, 'save'))->getDeclaringClass()->getName() === \Concrete\Core\Block\BlockController::class;
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

                return self::accordionAsJson($value);
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
            'accordion' => self::accordionAsJson($value),
            default => $value,
        };
    }

    /**
     * Models send the entries as an array as often as a JSON string (scenario 31); save() takes only the string.
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function accordionAsJson(array $value): array
    {
        if (is_array($value['accordionBlockData'] ?? null)) {
            $value['accordionBlockData'] = (string) json_encode(array_values($value['accordionBlockData']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $value;
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
