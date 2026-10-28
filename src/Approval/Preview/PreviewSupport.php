<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Block\Block;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\Utilities\BlockFields;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;

/**
 * What the previews share (P3.6-2): the change entry, values as short text, and reads of pages and blocks. Reads only.
 */
final class PreviewSupport
{
    public const VERSION_NOTE = 'Saved in a new unpublished version; nothing goes live until someone publishes it.';
    public const LIVE_NOTE = 'This takes effect at once: it has no draft.';

    /**
     * @param list<string> $details
     * @param list<string> $warnings shown first, before the details
     * @return array{action: string, target: string, path: string, details: list<string>, note: string, warnings: list<string>}
     */
    public static function entry(string $action, string $target, string $path, array $details, string $note, array $warnings = []): array
    {
        $redact = static fn (string $s): string => SecretRedactor::redact($s);

        return [
            'action' => $redact($action),
            'target' => $redact($target),
            'path' => $redact($path),
            'details' => array_values(array_map($redact, $details)),
            'note' => $note,
            'warnings' => array_values(array_map($redact, $warnings)),
        ];
    }

    /** "Label: "old" → "new"", with "(not shown)" for a value the user may not view. */
    public static function change(string $label, ?string $old, string $new): string
    {
        $before = $old === null ? '(not shown)' : ($old === '' ? '(empty)' : '"' . self::short($old) . '"');

        return $label . ': ' . $before . ' → ' . ($new === '' ? '(empty)' : '"' . self::short($new) . '"');
    }

    public static function short(string $text, int $length = 200): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }

    /** A value (an attribute's, an input) as plain text, for the card and the fingerprint. */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => trim((string) $value),
            $value instanceof \DateTimeInterface => $value->format('c'),
            is_object($value) && method_exists($value, '__toString') => trim((string) $value),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }

    /** Text of a stored field: HTML is shown as its words. */
    public static function fieldText(mixed $value): string
    {
        $text = self::text($value);

        return $text !== strip_tags($text) ? HtmlToText::preview($text, 200) : self::short($text);
    }

    public static function newest(int $cID): ?Page
    {
        if ($cID <= 0) {
            return null;
        }
        $page = Page::getByID($cID, 'RECENT');

        return $page && !$page->isError() ? $page : null;
    }

    public static function canView(Page $page): bool
    {
        return ObjectPermissions::viewPage($page) === null;
    }

    public static function name(Page $page): string
    {
        return self::canView($page) ? (string) $page->getCollectionName() : 'page ' . $page->getCollectionID();
    }

    public static function path(Page $page): string
    {
        if (!self::canView($page)) {
            return '';
        }
        $path = (string) $page->getCollectionPath();

        return $path === '' ? '/' : $path;
    }

    public static function int(array $inputs, string $name): int
    {
        return is_numeric($inputs[$name] ?? null) ? (int) $inputs[$name] : 0;
    }

    public static function string(array $inputs, string $name): string
    {
        return is_string($inputs[$name] ?? null) ? trim($inputs[$name]) : '';
    }

    /** The block in this area of the page's newest version, or null. */
    public static function block(Page $page, string $area, int $bID): ?Block
    {
        if ($bID <= 0) {
            return null;
        }
        $block = Block::getByID($bID, $page, $area);

        return $block && !$block->isError() ? $block : null;
    }

    public static function blockType(Block $block): string
    {
        $type = $block->getBlockTypeObject();

        return (string) ($type ? $type->getBlockTypeName() : $block->getBlockTypeHandle());
    }

    /**
     * The block's stored fields: its main record and its child rows (BlockFields), for the card and the fingerprint.
     *
     * @return array{record: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     */
    public static function blockState(Block $block): array
    {
        try {
            $fields = BlockFields::ofController((string) $block->getBlockTypeHandle(), $block->getController());
            $table = $fields->table();
            $record = $table === null ? [] : (\Concrete\Core\Support\Facade\Application::getFacadeApplication()
                ->make(\Concrete\Core\Database\Connection\Connection::class)
                ->fetchAssociative("SELECT * FROM `{$table}` WHERE bID = ?", [(int) $block->getBlockID()]) ?: []);
            unset($record['bID']);

            return ['record' => $record, 'rows' => $fields->rows((int) $block->getBlockID())];
        } catch (\Throwable) {
            return ['record' => [], 'rows' => []];
        }
    }

    /** The first text of a block's record, shortened: what the card shows to identify it. */
    public static function firstText(array $record): string
    {
        foreach ($record as $value) {
            if (is_string($value) && trim(strip_tags($value)) !== '' && !is_numeric($value)) {
                return self::fieldText($value);
            }
        }

        return '';
    }
}
