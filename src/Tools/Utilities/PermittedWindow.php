<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Search\ItemList\Database\ItemList;

/**
 * A list's window of the items the user may see, without loading every match on a large site (P3.3-5, BF-6).
 *
 * Concrete has no permission-aware count: with a permission checker a list's getTotalResults() is -1, and its
 * getResults() loads and checks every match. So the matches' rows are fetched (cheap: IDs), and each is loaded and
 * checked (the list's own getResult()) only until the window is full and CHECK_LIMIT matches have been checked. A total
 * reached that way is exact when every match was checked, and otherwise a lower bound the result says is "at least".
 */
final class PermittedWindow
{
    /** Matches checked before counting stops (the window is always filled first). */
    public const CHECK_LIMIT = 500;

    /** The limit in force: CHECK_LIMIT, lowered by the tests rather than building a thousand pages. */
    public static int $checkLimit = self::CHECK_LIMIT;

    /**
     * @param ItemList $list a Concrete list whose getResult() loads a row and returns null for one the user may not see
     * @param (callable(object): bool)|null $permitted a further check, for a list whose getResult() doesn't check
     *                                                 (UserList ignores its permission checker; BF-14)
     *
     * @return array{items: list<object>, total: int, exact: bool}
     */
    public static function of(ItemList $list, ListWindow $window, ?callable $permitted = null): array
    {
        $items = [];
        $count = 0;
        $checked = 0;
        $rows = $list->executeGetResults();
        $end = $window->offset + $window->limit;
        foreach ($rows as $row) {
            if ($checked >= self::$checkLimit && $count >= $end) {
                break;
            }
            $checked++;
            $item = $list->getResult($row);
            if ($item === null || ($permitted !== null && !$permitted($item))) {
                continue;
            }
            if ($count >= $window->offset && $count < $end) {
                $items[] = $item;
            }
            $count++;
        }

        return ['items' => $items, 'total' => $count, 'exact' => $checked >= count($rows)];
    }

    /**
     * Whether another match may still be checked, for a count that checks its matches itself (the pages of
     * count_blocks_by_type and list_page_types); $checked is how many it has checked.
     */
    public static function mayCheck(int $checked): bool
    {
        return $checked < self::$checkLimit;
    }
}
