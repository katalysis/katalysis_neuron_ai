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

    /** A limit below this, with more to come, gets the suggestion of a larger one (P3.3-5). */
    private const SMALL_LIMIT = 10;

    private function __construct(public readonly int $limit, public readonly int $offset, private readonly int $max)
    {
    }

    /**
     * A limit below 1 is 1, above $max is $max, and missing is $default; a missing or negative offset is 0.
     */
    public static function of(?int $limit, ?int $offset, int $default, int $max): self
    {
        return new self(max(1, min($limit ?? $default, $max)), max(0, $offset ?? 0), $max);
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
     * The fields every list result carries. $exact false (P3.3-5, PermittedWindow): the list stopped counting, so
     * $total is a lower bound and there may be more.
     *
     * @param string $narrow the filters that would narrow the list, for the note ("parentPath or pageType")
     *
     * @return array{total: int, totalIsAtLeast?: true, offset: int, returned: int, more: bool, note?: string}
     */
    public function summary(int $total, int $returned, string $tool, string $narrow = '', bool $exact = true): array
    {
        $summary = ['total' => $total];
        if (!$exact) {
            $summary['totalIsAtLeast'] = true;
        }
        $summary += [
            'offset' => $this->offset,
            'returned' => $returned,
            'more' => !$exact || $this->offset + $returned < $total,
        ];
        $note = $this->note($total, $returned, $tool, $narrow, $exact);
        if ($note !== null) {
            $summary['note'] = $note;
        }

        return $summary;
    }

    /**
     * What the result says when there are more items, or when the offset is past the end; null otherwise.
     */
    public function note(int $total, int $returned, string $tool, string $narrow = '', bool $exact = true): ?string
    {
        if ($exact && $returned === 0 && $this->offset > 0 && $this->offset >= $total) {
            return "offset {$this->offset} is past the end: there are {$total}.";
        }
        if ($exact && $this->offset + $returned >= $total) {
            return null;
        }
        $next = $this->offset + $returned;

        return sprintf(
            'Showing %d–%d of %s. For more, call %s again with offset %d%s%s.',
            $this->offset + 1,
            $next,
            $exact ? (string) $total : "at least {$total} (counting stopped there, so there may be more)",
            $tool,
            $next,
            // Scenario 15 run 4 paged list_pages one item at a time: ten calls, about four times the input tokens.
            $this->limit < self::SMALL_LIMIT && $this->limit < $this->max
                ? ', with a larger limit' . ($this->max < PHP_INT_MAX ? " (up to {$this->max})" : '') . ' rather than a few at a time'
                : '',
            $narrow !== '' ? ', or narrow with ' . $narrow : ''
        );
    }
}
