<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Entity\Block\BlockType\BlockType;
use Concrete\Core\Support\Facade\Application;

/**
 * The Level 0 generic block adapter's description of a block type (P3.3-7, EXTENSIONS.md "Level 0"): what Concrete's
 * own export metadata says about it, and nothing guessed.
 *
 * - Tables: $btExportTables, or [$btTable], the list BlockController::export() writes. The first is the block's table;
 *   the others are child tables (an accordion's entries, an image slider's slides), one row per entry.
 * - Kinds: a column in a $btExport…Columns list is a page, file, page type, page feed, file folder or HTML; any other
 *   is text, number or flag from its database type.
 * - Not described, so never written: a type with no table, Concrete's system blocks (core_*), child tables whose export
 *   or import is the controller's own code, columns named …ID that no export list covers (their meaning is unknown),
 *   and side tables named after the block's table that the export leaves out (Image's thumbnail types). Reads show
 *   all of it.
 */
final class BlockFields
{
    public const PAGE = 'page';
    public const FILE = 'file';
    public const PAGE_TYPE = 'page_type';
    public const PAGE_FEED = 'page_feed';
    public const FILE_FOLDER = 'file_folder';
    public const HTML = 'html';
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const FLAG = 'flag';

    /** Export list => kind. */
    private const EXPORT_LISTS = [
        'btExportPageColumns' => self::PAGE,
        'btExportFileColumns' => self::FILE,
        'btExportPageTypeColumns' => self::PAGE_TYPE,
        'btExportPageFeedColumns' => self::PAGE_FEED,
        'btExportFileFolderColumns' => self::FILE_FOLDER,
        'btExportContentColumns' => self::HTML,
    ];

    /**
     * Core controllers whose export() and importAdditionalData() overrides only drop the child rows' `id` (read in
     * Concrete 9.5.5), so their child tables import as Concrete's own code imports them. Any other override is the
     * block's own code, and its child tables are read-only here.
     */
    private const PLAIN_CHILD_IMPORT = [
        'Concrete\Block\Accordion\Controller',
        'Concrete\Block\ImageSlider\Controller',
        'Concrete\Block\Faq\Controller',
    ];

    /** Columns that order a child table's rows, first found wins. */
    private const ORDER_COLUMNS = ['sortOrder', 'displayOrder', 'position'];

    /** @var array<string, array<string, array{type: string, nullable: bool, default: mixed}>> */
    private static array $tableColumns = [];

    /** @var array<string, list<string>> */
    private static array $sideTables = [];

    /** @var array<string, string> column => kind, from the export lists */
    private array $kinds = [];

    /** @var list<string> */
    private array $tables;

    private function __construct(private readonly string $handle, private readonly BlockController $controller)
    {
        $tables = $this->property('btExportTables');
        $main = $controller->getBlockTypeDatabaseTable();
        $this->tables = array_values(array_filter(
            is_array($tables) ? array_map('strval', $tables) : [(string) $main],
            static fn (string $t): bool => preg_match('/^[A-Za-z0-9_]+$/', $t) === 1
        ));
        foreach (self::EXPORT_LISTS as $list => $kind) {
            $columns = $list === 'btExportPageColumns' ? $controller->getBlockTypeExportPageColumns() : $this->property($list);
            foreach ((array) $columns as $column) {
                $this->kinds[(string) $column] = $kind;
            }
        }
    }

    public static function ofType(BlockType $type): self
    {
        return new self((string) $type->getBlockTypeHandle(), $type->getController());
    }

    public static function ofController(string $handle, BlockController $controller): self
    {
        return new self($handle, $controller);
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function controller(): BlockController
    {
        return $this->controller;
    }

    /**
     * Why Level 0 can't write this block type at all, or null.
     */
    public function refusal(): ?string
    {
        if (str_starts_with($this->handle, 'core_')) {
            return "'{$this->handle}' is one of Concrete's system blocks (layouts, stacks, page type defaults); the assistant doesn't change them. Nothing was changed.";
        }
        if ($this->table() === null) {
            return "The '{$this->handle}' block type keeps no fields in a block table, so the assistant can't tell what to save. "
                . 'It can be changed in edit mode. Nothing was changed.';
        }

        return null;
    }

    /** The block's own table, or null. */
    public function table(): ?string
    {
        $main = (string) $this->controller->getBlockTypeDatabaseTable();

        return $main !== '' && preg_match('/^[A-Za-z0-9_]+$/', $main) === 1 ? $main : null;
    }

    /**
     * Columns of the block's table (or of a child table), without bID and a child table's id.
     *
     * @return array<string, array{kind: string, type: string, nullable: bool, default: mixed}>
     */
    public function columns(?string $table = null): array
    {
        $table ??= $this->table();
        if ($table === null) {
            return [];
        }
        $columns = [];
        foreach (self::rawColumns($table) as $name => $column) {
            if ($name === 'bID' || ($name === 'id' && $table !== $this->table())) {
                continue;
            }
            $columns[$name] = ['kind' => $this->kinds[$name] ?? self::kindOfType($column['type'])] + $column;
        }

        return $columns;
    }

    /**
     * The child tables the block declares ($btExportTables after its own).
     *
     * @return list<string>
     */
    public function childTables(): array
    {
        return array_values(array_filter($this->tables, fn (string $t): bool => $t !== $this->table()));
    }

    /**
     * Why a declared child table isn't written here, or null.
     */
    public function childTableRefusal(string $table): ?string
    {
        if (!in_array($table, $this->childTables(), true)) {
            return "The '{$this->handle}' block has no table '{$table}'. Nothing was changed.";
        }
        $class = get_class($this->controller);
        if (in_array($class, self::PLAIN_CHILD_IMPORT, true)) {
            return null;
        }
        foreach (['export', 'importAdditionalData'] as $method) {
            if ((new \ReflectionMethod($this->controller, $method))->getDeclaringClass()->getName() !== BlockController::class) {
                return "The '{$this->handle}' block saves its {$table} rows with its own code, so the assistant can read them but "
                    . 'not change them. They can be changed in edit mode. Nothing was changed.';
            }
        }

        return null;
    }

    /**
     * Tables named after the block's own, with a bID, that the export doesn't list (Image's btContentImageBreakpoints).
     *
     * @return list<string>
     */
    public function sideTables(): array
    {
        $table = $this->table();
        if ($table === null) {
            return [];
        }
        // The table list doesn't change within a process (the scenario snapshot asks for every block).
        self::$sideTables[$table] ??= array_map('strval', self::db()->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? AND TABLE_NAME LIKE ? AND TABLE_NAME <> ? ORDER BY TABLE_NAME',
            ['bID', $table . '%', $table]
        ));

        return array_values(array_filter(
            self::$sideTables[$table],
            fn (string $t): bool => !in_array($t, $this->tables, true) && preg_match('/^[A-Za-z0-9_]+$/', $t) === 1
        ));
    }

    /**
     * Columns named …ID that no export list covers: they refer to something Level 0 can't name (a topic tree node,
     * an Express form), so they keep their stored values.
     *
     * @return list<string>
     */
    public function undeclaredReferences(?string $table = null): array
    {
        $found = [];
        foreach ($this->columns($table) as $name => $column) {
            if ($name !== 'id' && str_ends_with($name, 'ID') && !isset($this->kinds[$name])) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Columns of the block's table a new block must have: not null, with no default.
     *
     * @return list<string>
     */
    public function required(): array
    {
        $found = [];
        foreach ($this->columns() as $name => $column) {
            if (!$column['nullable'] && $column['default'] === null) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /** The column that orders a child table's rows, or null. */
    public function orderColumn(string $table): ?string
    {
        foreach (self::ORDER_COLUMNS as $column) {
            if (array_key_exists($column, $this->columns($table))) {
                return $column;
            }
        }

        return null;
    }

    /**
     * A block's rows in its child tables (and, with $sideTables, its side tables), in order, with each row's id.
     *
     * @return array<string, list<array<string, mixed>>> table => rows
     */
    public function rows(int $bID, bool $sideTables = false): array
    {
        $rows = [];
        foreach (array_merge($this->childTables(), $sideTables ? $this->sideTables() : []) as $table) {
            $order = $this->orderColumn($table);
            $hasID = array_key_exists('id', self::rawColumns($table));
            $orderBy = implode(', ', array_filter([$order !== null ? "`{$order}`" : null, $hasID ? '`id`' : null]));
            $found = self::db()->fetchAllAssociative("SELECT * FROM `{$table}` WHERE bID = ?" . ($orderBy !== '' ? " ORDER BY {$orderBy}" : ''), [$bID]);
            $rows[$table] = array_map(static function (array $row): array {
                unset($row['bID']);

                return $row;
            }, $found);
        }

        return $rows;
    }

    /**
     * @return array<string, array{type: string, nullable: bool, default: mixed}> every column, as the database has it
     */
    private static function rawColumns(string $table): array
    {
        if (!isset(self::$tableColumns[$table])) {
            self::$tableColumns[$table] = [];
            foreach (self::db()->fetchAllAssociative("SHOW COLUMNS FROM `{$table}`") as $column) {
                self::$tableColumns[$table][(string) $column['Field']] = [
                    'type' => strtolower((string) $column['Type']),
                    'nullable' => $column['Null'] === 'YES',
                    'default' => $column['Default'],
                ];
            }
        }

        return self::$tableColumns[$table];
    }

    private static function kindOfType(string $type): string
    {
        if (preg_match('/^tinyint\(1\)/', $type) === 1) {
            return self::FLAG;
        }
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double|numeric)/', $type) === 1) {
            return self::NUMBER;
        }

        return self::TEXT;
    }

    private function property(string $name): mixed
    {
        if (!property_exists($this->controller, $name)) {
            return null;
        }

        return (new \ReflectionProperty($this->controller, $name))->getValue($this->controller);
    }

    private static function db(): \Concrete\Core\Database\Connection\Connection
    {
        return Application::getFacadeApplication()->make('database')->connection();
    }
}
