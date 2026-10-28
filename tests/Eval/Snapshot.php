<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * A recording of the fixture (and the pages around it) at one moment.
 *
 * It is plain data, built by SnapshotBuilder, so the comparisons here can be
 * unit-tested without Concrete. Pages are keyed by ID, because a path changes
 * when a page moves. Scenarios name pages by path and the checks look the ID up.
 *
 * Page record keys: id, parentID, path, isTrash, type, name, versions,
 * latestVersion, approvedVersion, children (direct child IDs), and, for pages
 * under /neuron-test only: attributes (latest version), latestBlocks and
 * approvedBlocks. A block is {area, type, order, data}.
 *
 * File record keys: id, title, filename, size, description, versions, folderID, nodeParent, folder.
 * People: email (or "sID:n") => the row. null when the People table doesn't exist.
 */
final class Snapshot
{
    /** @var array<int, array<string, mixed>> */
    private array $pages;
    /** @var list<array<string, mixed>> */
    private array $files;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $people;
    /** @var array<string, array<string, string>>|null user ID => username, email, active, groups (P3.5-2) */
    private ?array $users;

    /**
     * @param array{pages: array<int, array<string, mixed>>, files: list<array<string, mixed>>, people: array<string, array<string, mixed>>|null, users?: array<string, array<string, string>>|null} $data
     */
    public function __construct(array $data)
    {
        $this->pages = $data['pages'];
        $this->files = $data['files'];
        $this->people = $data['people'];
        $this->users = $data['users'] ?? null;
    }

    /**
     * @return array<string, array<string, string>>|null
     */
    public function users(): ?array
    {
        return $this->users;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pageByID(int $id): ?array
    {
        return $this->pages[$id] ?? null;
    }

    /**
     * @return array<string, mixed>|null the page that is at this path now, or null. Trashed pages have a /!trash path.
     */
    public function pageByPath(string $path): ?array
    {
        $path = self::normalizePath($path);
        foreach ($this->pages as $page) {
            if ($page['path'] === $path) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return list<int>|null IDs of the direct children of the page at this path, or null when there is no such page
     */
    public function childrenOf(string $path): ?array
    {
        $page = $this->pageByPath($path);

        return $page === null ? null : $page['children'];
    }

    /**
     * IDs of pages that were added, removed or changed compared with $before.
     * A page's list of children isn't part of the comparison, so adding a child doesn't count as changing the parent.
     *
     * @return list<int>
     */
    public function changedPageIDs(self $before): array
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before->pages), array_keys($this->pages))) as $id) {
            $a = $before->pages[$id] ?? null;
            $b = $this->pages[$id] ?? null;
            if ($a === null || $b === null || self::fingerprint($a) !== self::fingerprint($b)) {
                $changed[] = (int) $id;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filesByTitle(string $title): array
    {
        return array_values(array_filter($this->files, static fn (array $f): bool => $f['title'] === $title));
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function people(): ?array
    {
        return $this->people;
    }

    public function filesDiffer(self $before): bool
    {
        return $this->files !== $before->files;
    }

    public function peopleDiffer(self $before): bool
    {
        return $this->people !== $before->people;
    }

    public function identicalTo(self $before): bool
    {
        return $this->describeDifferences($before) === [];
    }

    /**
     * @return list<string> one line per kind of difference, for check messages
     */
    public function describeDifferences(self $before): array
    {
        $lines = [];

        $ids = $this->changedPageIDs($before);
        if ($ids !== []) {
            $labels = array_map(function (int $id) use ($before): string {
                $page = $this->pages[$id] ?? $before->pages[$id] ?? null;
                $state = !isset($before->pages[$id]) ? ' (added)' : (!isset($this->pages[$id]) ? ' (removed)' : '');

                return ($page['path'] ?? '?') . $state;
            }, $ids);
            $lines[] = 'pages changed: ' . implode(', ', $labels);
        }
        if ($this->filesDiffer($before)) {
            $lines[] = 'files changed';
        }
        if ($this->peopleDiffer($before)) {
            $lines[] = 'people changed';
        }

        return $lines;
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path;
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function fingerprint(array $page): string
    {
        unset($page['children']);

        return (string) json_encode($page, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
