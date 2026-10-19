<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\Tests\Eval\Snapshot;

/**
 * Builds synthetic snapshots for the unit tests of the scenario checks.
 */
final class Snapshots
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function page(int $id, string $path, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'parentID' => 1,
            'path' => $path,
            'isTrash' => false,
            'type' => 'page',
            'name' => ucfirst(basename($path)),
            'versions' => 1,
            'latestVersion' => 1,
            'approvedVersion' => 1,
            'children' => [],
            'attributes' => [],
            'latestBlocks' => [],
            'approvedBlocks' => [],
        ], $overrides);
    }

    /**
     * @return array{area: string, type: string, order: int, data: array<string, string|null>}
     */
    public static function block(string $type, string $content, string $area = 'Main', int $order = 0): array
    {
        return ['area' => $area, 'type' => $type, 'order' => $order, 'data' => ['content' => $content]];
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param list<array<string, mixed>> $files
     * @param array<string, array<string, mixed>>|null $people
     */
    public static function make(array $pages, array $files = [], ?array $people = []): Snapshot
    {
        $byID = [];
        foreach ($pages as $page) {
            $byID[$page['id']] = $page;
        }

        return new Snapshot(['pages' => $byID, 'files' => $files, 'people' => $people]);
    }
}
