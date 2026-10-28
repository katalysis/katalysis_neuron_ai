<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\Controller\SaveMode;
use Concrete\Core\Block\Events\BlockAdd;
use Concrete\Core\Block\Events\BlockEdit;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Editor\LinkAbstractor;
use Concrete\Core\Entity\Block\BlockType\BlockType;
use Concrete\Core\Events\EventDispatcher;
use Concrete\Core\File\File;
use Concrete\Core\Page\Feed;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Tree\Node\Node;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Katalysis\NeuronAi\Access\ObjectPermissions;

/**
 * Block writes through the Level 0 adapter (P3.3-7): the value is checked against what BlockFields describes, before
 * any draft, then saved in import mode (SaveMode::SAVE_MODE_IMPORT: the columns as stored, as a CIF import saves
 * them) into the draft, and the declared child tables are written as rows, as BlockController::importAdditionalData()
 * writes them.
 *
 * The checks, for every block type, before its own validate() (which the tools run on the same column-shaped value):
 * - only the block's columns, its writable child tables and the inputs BlockSaveInputs keeps (BF-17)
 * - a new block has its required columns (not null, no default)
 * - a page, file, page type, feed or folder it refers to exists, and a page or file is one the user may view
 * - a column Level 0 can't name (…ID, in no export list) keeps its stored value
 * - no script in any string, child rows included (UnsafeMarkup, B-T1-10)
 *
 * Concrete's AddBlockToPageCommand and UpdatePageBlockCommand take no save mode, so add() and update() do what their
 * handlers do (and what Block::update() does) with the mode set, and send the same events.
 */
final class BlockImportSave
{
    /**
     * The `value` input of the block write tools (P3.3-5: scenario 07 sent `htmlContent` for an HTML block, whose
     * field is `content`).
     */
    public const VALUE_DESCRIPTION = 'The block\'s fields, named exactly as get_block_data shows them for that block type: '
        . 'for example content for Content and HTML blocks, title and paragraph for a Feature. Entries kept in a table '
        . '(an accordion\'s or an image slider\'s) are a list of rows under the table\'s name, each stored row with its id.';

    /**
     * A new block's value, checked: what add() saves.
     *
     * @param array<string, mixed> $value as sent
     *
     * @return array{value: array<string, mixed>, rows: array<string, list<array<string, mixed>>>, check: array<string, mixed>}
     *
     * @throws \RuntimeException the refusal; nothing has been changed
     */
    public static function prepareNew(BlockFields $fields, array $value): array
    {
        self::refuseIf($fields->refusal());
        $handle = $fields->handle();
        $value = BlockSaveInputs::pickerToColumns($handle, $value);
        self::refuseUnknown($fields, $value);

        $missing = array_values(array_filter($fields->required(), static fn (string $c): bool => ($value[$c] ?? null) === null));
        if ($missing !== []) {
            self::refuseIf("A new {$handle} block needs " . implode(', ', array_map(static fn (string $c): string => "'{$c}'", $missing))
                . ': the block\'s table has no default for ' . (count($missing) === 1 ? 'it' : 'them') . '. Nothing was changed. '
                . 'Its fields are: ' . implode(', ', array_keys($fields->columns())) . '.');
        }

        foreach ($fields->undeclaredReferences() as $column) {
            if (self::isEmpty($value[$column] ?? null)) {
                continue;
            }
            // The one undeclared reference a new block may name, by hand: the Express form an Express form block shows.
            if ($handle === 'express_form' && $column === 'exFormID' && BlockSaveInputs::expressFormExists((string) $value[$column])) {
                continue;
            }
            self::refuseIf(self::undeclaredRefusal($handle, $column));
        }
        self::refuseReferences($fields, null, $value, []);

        $rows = self::childRowsFromValue($fields, $value, []);
        $check = self::textView($fields, $value, $rows);

        return ['value' => self::translated($fields, null, self::withoutChildKeys($fields, $value), []), 'rows' => $rows, 'check' => $check];
    }

    /**
     * An update's value, checked against the block as stored: the full row import mode saves (every stored column,
     * then the change), the child rows to write, and the stored and new values as the text check sees them.
     *
     * @param array<string, mixed> $record the stored row
     * @param array<string, mixed> $value as sent
     *
     * @return array{value: array<string, mixed>, rows: array<string, list<array<string, mixed>>>, keptFields: list<string>, checkBefore: array<string, mixed>, checkAfter: array<string, mixed>, sideTablesNamed: list<string>}
     *
     * @throws \RuntimeException the refusal; nothing has been changed
     */
    public static function prepareUpdate(BlockFields $fields, int $bID, array $record, array $value): array
    {
        self::refuseIf($fields->refusal());
        $handle = $fields->handle();
        $value = BlockSaveInputs::pickerToColumns($handle, $value);
        self::refuseUnknown($fields, $value);

        foreach ($fields->undeclaredReferences() as $column) {
            if (array_key_exists($column, $value) && (string) $value[$column] !== (string) ($record[$column] ?? '')) {
                self::refuseIf(self::undeclaredRefusal($handle, $column));
            }
        }
        self::refuseReferences($fields, null, $value, $record);

        $storedRows = $fields->rows($bID);
        $rows = self::childRowsFromValue($fields, $value, $storedRows);
        // Child tables the value doesn't name keep their rows: a save() may delete them (Accordion, Image Slider).
        foreach ($fields->childTables() as $table) {
            if (!isset($rows[$table]) && $fields->childTableRefusal($table) === null) {
                $rows[$table] = array_map(static function (array $row): array {
                    unset($row['id']);

                    return $row;
                }, $storedRows[$table] ?? []);
            }
        }

        $main = self::withoutChildKeys($fields, $value);
        $kept = TextIntegrity::keepOmittedFields($record, $main)['keptFields'];
        $stored = $record;
        unset($stored['bID']);
        $full = array_replace($stored, $main);
        $full = BlockSaveInputs::keepThumbnailTypes($handle, $bID, $full);

        $named = array_intersect_key($rows, self::namedChildTables($fields, $value));
        $before = $record;
        unset($before['bID']);
        foreach (array_keys($named) as $table) {
            $before[$table] = self::rowsText($fields, $table, $storedRows[$table] ?? []);
        }
        $after = $full;
        unset($after['selectedThumbnailTypes']);
        if (array_key_exists('accordionBlockData', $value)) {
            // The accordion's own input is compared as its entries' text, as before P3.3-7 (P3.3-6).
            $before['accordionBlockData'] = BlockSaveInputs::accordionText(BlockSaveInputs::accordionJson($bID));
            $after['accordionBlockData'] = BlockSaveInputs::accordionText((string) json_encode($rows['btAccordionEntries'] ?? []));
            unset($before['btAccordionEntries']);
        } else {
            foreach (array_keys($named) as $table) {
                $after[$table] = self::rowsText($fields, $table, $named[$table]);
            }
        }

        return [
            'value' => self::translated($fields, null, $full, $stored),
            'rows' => $rows,
            'keptFields' => $kept,
            'checkBefore' => $before,
            'checkAfter' => $after,
            'sideTablesNamed' => BlockSaveInputs::sideTablesNamed($handle, $value),
        ];
    }

    /**
     * textFields as the text check sees them: a child row or a row's field ("btImageSliderEntries[1].title", scenario
     * 36) is its table, whose rows the check compares as one field.
     *
     * @param list<string>|null $textFields
     *
     * @return list<string>|null
     */
    public static function textFields(BlockFields $fields, ?array $textFields): ?array
    {
        if ($textFields === null) {
            return null;
        }
        $named = [];
        foreach ($textFields as $field) {
            $field = (string) $field;
            foreach ($fields->childTables() as $table) {
                if (preg_match('/^' . preg_quote($table, '/') . '(\[\d+\])?(\..+)?$/', $field) === 1) {
                    $field = $table;
                    break;
                }
            }
            $named[] = $field;
        }

        return array_values(array_unique($named));
    }

    /**
     * Adds the block to the draft in import mode, as AddBlockToPageCommandHandler adds it, then its child rows.
     *
     * @param array{value: array<string, mixed>, rows: array<string, list<array<string, mixed>>>} $prepared
     */
    public static function add(Page $page, Area $area, BlockType $type, BlockFields $fields, array $prepared): Block
    {
        $db = self::db();
        $block = $db->transactional(static function () use ($page, $area, $type, $fields, $prepared): Block {
            $collection = $type->includeAll() ? $page : $page->getVersionToModify();
            $block = $collection->addBlock($type, $area, $prepared['value'], SaveMode::SAVE_MODE_IMPORT);
            self::writeRows($fields, (int) $block->getBlockID(), $prepared['rows']);

            return $block;
        });
        Application::getFacadeApplication()->make(EventDispatcher::class)->dispatch('on_block_add', new BlockAdd($block, $page));

        return $block;
    }

    /**
     * Saves the draft's copy of the block in import mode, as UpdatePageBlockCommandHandler and Block::update() do,
     * then its child rows. A save that removed rows the export doesn't describe, and the call didn't ask to change, is
     * undone and refused.
     *
     * @param array{value: array<string, mixed>, rows: array<string, list<array<string, mixed>>>, sideTablesNamed: list<string>} $prepared
     */
    public static function update(Page $page, Block $block, BlockFields $fields, array $prepared): Block
    {
        $app = Application::getFacadeApplication();
        $db = self::db();
        $bID = (int) $block->getBlockID();
        $guarded = array_values(array_diff(
            array_merge(array_filter($fields->childTables(), static fn (string $t): bool => $fields->childTableRefusal($t) !== null), $fields->sideTables()),
            $prepared['sideTablesNamed']
        ));
        $before = self::rowsOf($bID, $guarded);

        $db->transactional(static function () use ($app, $db, $block, $bID, $fields, $prepared, $guarded, $before): void {
            $db->executeStatement('UPDATE Blocks SET bDateModified = ? WHERE bID = ?', [$app->make('helper/date')->getOverridableNow(), $bID]);
            $block->refreshBlockOutputCache();
            $controller = $app->make($block->getBlockTypeObject()->getBlockTypeClass(), ['obj' => $block]);
            $controller->saveMode = SaveMode::SAVE_MODE_IMPORT;
            $controller->save($prepared['value']);
            self::writeRows($fields, $bID, $prepared['rows']);

            $lost = self::lostSideRows($before, self::rowsOf($bID, $guarded));
            if ($lost !== []) {
                throw new \RuntimeException("Saving the {$fields->handle()} block removed its stored " . implode(', ', $lost)
                    . ' rows, which the assistant can\'t describe or put back. The save was undone and nothing was changed in the block. '
                    . 'It can be changed in edit mode.');
            }
        });
        $app->make(EventDispatcher::class)->dispatch('on_block_edit', new BlockEdit($block, $page));

        return $block;
    }

    /**
     * The side tables whose rows a save removed (a row that only got a new id is not removed).
     *
     * @param array<string, list<array<string, mixed>>> $before
     * @param array<string, list<array<string, mixed>>> $after
     *
     * @return list<string>
     */
    public static function lostSideRows(array $before, array $after): array
    {
        $key = static function (array $row): string {
            unset($row['id'], $row['bID']);
            ksort($row);

            return (string) json_encode(array_map('strval', $row));
        };
        $lost = [];
        foreach ($before as $table => $rows) {
            $left = array_count_values(array_map($key, $after[$table] ?? []));
            foreach ($rows as $row) {
                $k = $key($row);
                if (($left[$k] ?? 0) === 0) {
                    $lost[] = (string) $table;
                    continue 2;
                }
                $left[$k]--;
            }
        }

        return $lost;
    }

    // ---- checks ------------------------------------------------------------------------------------------------

    /**
     * Fields the block doesn't have (BF-17), named with the block's real fields.
     *
     * @param array<string, mixed> $value
     */
    private static function refuseUnknown(BlockFields $fields, array $value): void
    {
        $handle = $fields->handle();
        $columns = array_keys($fields->columns());
        $allowed = array_merge($columns, $fields->childTables(), BlockSaveInputs::otherInputs($handle));
        $unknown = array_values(array_diff(array_map('strval', array_keys($value)), $allowed));
        if ($unknown !== []) {
            $named = BlockSaveInputs::unknownFieldRefusal($handle, $unknown, $columns);
            if ($named !== null) {
                self::refuseIf($named);
            }
            $quoted = implode(', ', array_map(static fn (string $f): string => "'{$f}'", $unknown));
            $children = [];
            foreach ($fields->childTables() as $table) {
                $children[] = "{$table} (a list of rows with " . implode(', ', array_keys($fields->columns($table))) . ')';
            }
            $others = BlockSaveInputs::otherInputs($handle);
            self::refuseIf("The {$handle} block has no field {$quoted}. Nothing was changed. Its fields are: " . implode(', ', $columns)
                . ($children === [] ? '' : '; its entries: ' . implode('; ', $children))
                . ($others === [] ? '' : ' (it also takes: ' . implode(', ', $others) . ')')
                . '. Send the change again using those field names; get_block_data shows a block\'s current values.');
        }
        foreach ($fields->childTables() as $table) {
            if (array_key_exists($table, $value)) {
                self::refuseIf($fields->childTableRefusal($table));
            }
        }
    }

    /**
     * Each page, file, page type, feed or folder the value names, where it differs from the stored value: it exists,
     * and a page or file is one the user may view.
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $stored
     */
    private static function refuseReferences(BlockFields $fields, ?string $table, array $value, array $stored, string $where = ''): void
    {
        foreach ($fields->columns($table) as $column => $info) {
            if (!array_key_exists($column, $value) || self::isEmpty($value[$column]) || (string) $value[$column] === (string) ($stored[$column] ?? '')) {
                continue;
            }
            $id = $value[$column];
            $label = "Field '{$where}{$column}'";
            if (!in_array($info['kind'], [BlockFields::PAGE, BlockFields::FILE, BlockFields::PAGE_TYPE, BlockFields::PAGE_FEED, BlockFields::FILE_FOLDER], true)) {
                continue;
            }
            if (!is_numeric($id) || (int) $id <= 0) {
                self::refuseIf("{$label} takes the ID number of a {$info['kind']}, not " . json_encode($id) . '. Nothing was changed.');
            }
            $id = (int) $id;
            switch ($info['kind']) {
                case BlockFields::PAGE:
                    $page = Page::getByID($id);
                    if (!$page || $page->isError()) {
                        self::refuseIf("{$label} refers to page {$id}, which doesn't exist. Nothing was changed. Find the page with list_pages or get_page.");
                    }
                    $denied = ObjectPermissions::viewPage($page);
                    break;
                case BlockFields::FILE:
                    $file = File::getByID($id);
                    if (!$file) {
                        self::refuseIf("{$label} refers to file {$id}, which doesn't exist. Nothing was changed. Find the file with list_files.");
                    }
                    $denied = ObjectPermissions::viewFile($file);
                    break;
                case BlockFields::PAGE_TYPE:
                    $denied = null;
                    if (!PageType::getByID($id)) {
                        self::refuseIf("{$label} refers to page type {$id}, which doesn't exist. Nothing was changed. list_page_types lists them.");
                    }
                    break;
                case BlockFields::PAGE_FEED:
                    $denied = null;
                    if (!Feed::getByID($id)) {
                        self::refuseIf("{$label} refers to feed {$id}, which doesn't exist. Nothing was changed.");
                    }
                    break;
                default:
                    $denied = null;
                    if (!Node::getByID($id) instanceof FileFolder) {
                        self::refuseIf("{$label} refers to file folder {$id}, which doesn't exist. Nothing was changed.");
                    }
            }
            if ($denied !== null) {
                self::refuseIf("{$label} can't link to {$info['kind']} {$id}: {$denied} Nothing was changed.");
            }
        }
    }

    /**
     * The rows of each child table the value names, checked: a row with an id keeps that stored row's other fields; a
     * row without one is new; stored rows left out are removed. Ordered as sent.
     *
     * @param array<string, mixed> $value
     * @param array<string, list<array<string, mixed>>> $storedRows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function childRowsFromValue(BlockFields $fields, array $value, array $storedRows): array
    {
        $handle = $fields->handle();
        $rows = [];
        $accordion = BlockSaveInputs::accordionRows($handle, $value);
        if ($accordion !== null) {
            $value['btAccordionEntries'] = $accordion;
        }
        foreach (array_keys(self::namedChildTables($fields, $value)) as $table) {
            $sent = $value[$table];
            if (!is_array($sent) || !array_is_list($sent)) {
                self::refuseIf("'{$table}' takes a list of rows, every row in order (rows left out are removed). Nothing was changed.");
            }
            $columns = $fields->columns($table);
            $byID = [];
            foreach ($storedRows[$table] ?? [] as $stored) {
                $byID[(int) ($stored['id'] ?? 0)] = $stored;
            }
            $order = $fields->orderColumn($table);
            $rows[$table] = [];
            foreach ($sent as $index => $row) {
                $where = "{$table}[{$index}].";
                if (!is_array($row)) {
                    self::refuseIf("{$where} must be an object with the row's fields. Nothing was changed.");
                }
                $unknown = array_values(array_diff(array_map('strval', array_keys($row)), array_merge(array_keys($columns), ['id'])));
                if ($unknown !== []) {
                    self::refuseIf("A {$table} row has no field " . implode(', ', array_map(static fn (string $f): string => "'{$f}'", $unknown))
                        . '. Nothing was changed. Its fields are: ' . implode(', ', array_keys($columns)) . ', and id to keep a stored row.');
                }
                $stored = [];
                if (array_key_exists('id', $row)) {
                    $stored = $byID[(int) $row['id']] ?? null;
                    if ($stored === null) {
                        self::refuseIf("{$where}id {$row['id']} is not one of this block's {$table} rows"
                            . ($byID === [] ? '' : ' (' . implode(', ', array_keys($byID)) . ')')
                            . '. Read the block again with get_block_data; send a new row without an id. Nothing was changed.');
                    }
                    unset($stored['id'], $row['id']);
                }
                foreach ($fields->undeclaredReferences($table) as $column) {
                    if (!array_key_exists($column, $row)) {
                        continue;
                    }
                    $changed = $stored !== [] ? (string) $row[$column] !== (string) ($stored[$column] ?? '') : !self::isEmpty($row[$column]);
                    if ($changed) {
                        self::refuseIf(self::undeclaredRefusal($handle, $where . $column));
                    }
                }
                self::refuseReferences($fields, $table, $row, $stored, $where);
                foreach ($row as $column => $cell) {
                    self::refuseIf(UnsafeMarkup::problem($where . $column, $cell, $stored[$column] ?? null));
                }
                $merged = array_replace($stored, $row);
                if ($order !== null) {
                    $merged[$order] = $index;
                }
                $rows[$table][] = $merged;
            }
        }

        return $rows;
    }

    // ---- writing -----------------------------------------------------------------------------------------------

    /**
     * Replaces the block's rows in each child table given, as importAdditionalData() inserts them, with HTML in
     * Concrete's stored form (translateTo() leaves a stored value as it is).
     *
     * @param array<string, list<array<string, mixed>>> $rows
     */
    private static function writeRows(BlockFields $fields, int $bID, array $rows): void
    {
        $db = self::db();
        foreach ($rows as $table => $list) {
            $columns = array_keys($fields->columns($table));
            $db->executeStatement("DELETE FROM `{$table}` WHERE bID = ?", [$bID]);
            foreach ($list as $row) {
                $row = self::translated($fields, $table, array_intersect_key($row, array_flip($columns)), []);
                $db->insert($table, $row + ['bID' => $bID]);
            }
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, true> the writable child tables the value names (accordionBlockData names the entries)
     */
    private static function namedChildTables(BlockFields $fields, array $value): array
    {
        $named = [];
        foreach ($fields->childTables() as $table) {
            if (array_key_exists($table, $value)) {
                $named[$table] = true;
            }
        }
        if (BlockSaveInputs::accordionRows($fields->handle(), $value) !== null) {
            $named['btAccordionEntries'] = true;
        }

        return $named;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed> the value's columns only (child tables and the accordion's input are rows)
     */
    private static function withoutChildKeys(BlockFields $fields, array $value): array
    {
        foreach (array_merge($fields->childTables(), ['accordionBlockData']) as $key) {
            unset($value[$key]);
        }

        return $value;
    }

    /**
     * HTML columns in Concrete's stored form ({CCM:…} links), as the block forms' save() stores them. Only changed
     * values: a stored value is already in that form.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private static function translated(BlockFields $fields, ?string $table, array $values, array $stored): array
    {
        foreach ($fields->columns($table) as $column => $info) {
            if ($info['kind'] === BlockFields::HTML && is_string($values[$column] ?? null) && $values[$column] !== ($stored[$column] ?? null)) {
                $values[$column] = LinkAbstractor::translateTo($values[$column]);
            }
        }

        return $values;
    }

    /**
     * A child table's rows as the text check sees them: each row's HTML, and its text that isn't a setting.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function rowsText(BlockFields $fields, string $table, array $rows): string
    {
        $html = '';
        foreach ($rows as $row) {
            foreach ($fields->columns($table) as $column => $info) {
                $cell = $row[$column] ?? null;
                if (!is_string($cell) || $cell === '') {
                    continue;
                }
                if ($info['kind'] === BlockFields::HTML) {
                    $html .= $cell;
                } elseif ($info['kind'] === BlockFields::TEXT && !TextIntegrity::isSetting($cell)) {
                    $html .= '<p>' . htmlspecialchars($cell, ENT_QUOTES) . '</p>';
                }
            }
        }

        return $html;
    }

    /**
     * A new block's value as the text check sees it.
     *
     * @param array<string, mixed> $value
     * @param array<string, list<array<string, mixed>>> $rows
     *
     * @return array<string, mixed>
     */
    private static function textView(BlockFields $fields, array $value, array $rows): array
    {
        $view = self::withoutChildKeys($fields, $value);
        foreach ($rows as $table => $list) {
            $view[$table] = self::rowsText($fields, $table, $list);
        }

        return $view;
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function rowsOf(int $bID, array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = self::db()->fetchAllAssociative("SELECT * FROM `{$table}` WHERE bID = ?", [$bID]);
        }

        return $rows;
    }

    private static function undeclaredRefusal(string $handle, string $column): string
    {
        return "Field '{$column}' of the {$handle} block refers to something the assistant can't identify (it is not a page, file, "
            . 'page type, feed or folder in the block\'s export), so it keeps its stored value. Nothing was changed. Send the change '
            . "without '{$column}'; it can be changed in edit mode.";
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_numeric($value) && (float) $value === 0.0);
    }

    /** @throws \RuntimeException */
    private static function refuseIf(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }
    }

    private static function db(): Connection
    {
        return Application::getFacadeApplication()->make(Connection::class);
    }
}
