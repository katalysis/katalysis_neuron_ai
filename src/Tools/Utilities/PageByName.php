<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;

/**
 * Finds the one page a name refers to, for tools that accept a page name (BF-2).
 *
 * Three steps, the first that finds anything wins: the exact name; the exact name ignoring case; the name containing
 * the text, ignoring case. Each step is a PageList with its own conditions kept (active, approved, not a system page,
 * the current site, and the current user's view permission), and the name compared through a bound parameter.
 */
final class PageByName
{
    /**
     * @throws \RuntimeException when no page, or more than one page, matches
     */
    public static function find(string $name): Page
    {
        $name = trim($name);
        $steps = [
            static fn (PageList $list) => $list->filterByName($name, true),
            static function (PageList $list) use ($name): void {
                $query = $list->getQueryObject();
                $query->andWhere('lower(cv.cvName) = :pageByName')->setParameter('pageByName', mb_strtolower($name));
            },
            static function (PageList $list) use ($name): void {
                $query = $list->getQueryObject();
                $query->andWhere('lower(cv.cvName) LIKE :pageByName')
                    ->setParameter('pageByName', '%' . addcslashes(mb_strtolower($name), '%_\\') . '%');
            },
        ];

        foreach ($steps as $step) {
            $list = new PageList();
            $step($list);
            $results = $list->getResults();

            if (count($results) === 1) {
                return $results[0];
            }
            if (count($results) > 1) {
                $names = array_map(static fn (Page $page): string => $page->getCollectionName() . ' (ID: ' . $page->getCollectionID() . ')', $results);
                throw new \RuntimeException("Multiple pages found matching '{$name}': " . implode(', ', $names) . '. Please use pageID or pagePath instead.');
            }
        }

        throw new \RuntimeException("No page found matching name '{$name}'");
    }
}
