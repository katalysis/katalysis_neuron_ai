<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * The window a list tool shows: `limit` items from `offset` (BF-6; B-TRU-3). Every capped list reports the true total
 * of what the user may see, and when there are more it says how to get them, so the model never takes the first
 * page of a list for all of it.
 *
 * katalysis_pro can't depend on this package, so its three lists word the same note privately.
 */
final class ListWindow
{
    /** The description of every list tool's `offset` input. */
    public const OFFSET_DESCRIPTION = 'How many items to skip (default 0). When a result says there are more, call again with the offset it gives.';

    private function __construct(public readonly int $limit, public readonly int $offset)
    {
    }

    /**
     * A limit below 1 is 1, above $max is $max, and missing is $default; a missing or negative offset is 0.
     */
    public static function of(?int $limit, ?int $offset, int $default, int $max): self
    {
        return new self(max(1, min($limit ?? $default, $max)), max(0, $offset ?? 0));
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return array<array-key, T>
     */
    public function slice(array $items): array
    {
        return array_slice($items, $this->offset, $this->limit, !array_is_list($items));
    }

    /**
     * The fields every list result carries.
     *
     * @param string $narrow the filters that would narrow the list, for the note ("parentPath or pageType")
     *
     * @return array{total: int, offset: int, returned: int, more: bool, note?: string}
     */
    public function summary(int $total, int $returned, string $tool, string $narrow = ''): array
    {
        $summary = [
            'total' => $total,
            'offset' => $this->offset,
            'returned' => $returned,
            'more' => $this->offset + $returned < $total,
        ];
        $note = $this->note($total, $returned, $tool, $narrow);
        if ($note !== null) {
            $summary['note'] = $note;
        }

        return $summary;
    }

    /**
     * What the result says when there are more items, or when the offset is past the end; null otherwise.
     */
    public function note(int $total, int $returned, string $tool, string $narrow = ''): ?string
    {
        if ($returned === 0 && $this->offset > 0 && $this->offset >= $total) {
            return "offset {$this->offset} is past the end: there are {$total}.";
        }
        if ($this->offset + $returned >= $total) {
            return null;
        }
        $next = $this->offset + $returned;

        return sprintf(
            'Showing %d–%d of %d. For more, call %s again with offset %d%s.',
            $this->offset + 1,
            $next,
            $total,
            $tool,
            $next,
            $narrow !== '' ? ', or narrow with ' . $narrow : ''
        );
    }
}
