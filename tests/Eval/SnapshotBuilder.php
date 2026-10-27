<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tools\Utilities\BlockSaveInputs;

/**
 * Takes a Snapshot of the dev site. Read-only.
 *
 * Every page gets a cheap record from SQL (parent, path, type, version counts).
 * Pages under /neuron-test also get their attributes and blocks, for the latest
 * and the approved version, because that is where the scenarios look.
 *
 * Needs Concrete's caches disabled (the runner does that), or it can read stale pages.
 */
final class SnapshotBuilder
{
    public const FIXTURE_ROOT = '/neuron-test';

    /** @var array<string, string|null> */
    private array $blockTables = [];
    /** @var list<string>|null */
    private ?array $attributeHandles = null;

    public function __construct(private Connection $db, private int $homePageID)
    {
    }

    public function take(): Snapshot
    {
        return new Snapshot([
            'pages' => $this->pages(),
            'files' => $this->files(),
            'people' => $this->people(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pages(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.cID, p.cParentID, pt.ptHandle, pp.cPath,
                (SELECT COUNT(*) FROM CollectionVersions v WHERE v.cID = p.cID) AS versions,
                (SELECT MAX(v.cvID) FROM CollectionVersions v WHERE v.cID = p.cID) AS latestVersion,
                (SELECT v.cvID FROM CollectionVersions v WHERE v.cID = p.cID AND v.cvIsApproved = 1 ORDER BY v.cvID DESC LIMIT 1) AS approvedVersion,
                (SELECT v.cvName FROM CollectionVersions v WHERE v.cID = p.cID ORDER BY v.cvID DESC LIMIT 1) AS name
             FROM Pages p
             LEFT JOIN PageTypes pt ON pt.ptID = p.ptID
             LEFT JOIN PagePaths pp ON pp.cID = p.cID AND pp.ppIsCanonical = 1
             WHERE p.cPointerID < 1 OR p.cPointerID IS NULL
             ORDER BY p.cID'
        );

        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row['cParentID']][] = (int) $row['cID'];
        }

        $pages = [];
        foreach ($rows as $row) {
            $id = (int) $row['cID'];
            $path = (string) ($row['cPath'] ?? '');
            if ($path === '' && $id === $this->homePageID) {
                $path = '/';
            }

            $latest = $row['latestVersion'] === null ? null : (int) $row['latestVersion'];
            $approved = $row['approvedVersion'] === null ? null : (int) $row['approvedVersion'];

            $page = [
                'id' => $id,
                'parentID' => (int) $row['cParentID'],
                'path' => $path,
                'isTrash' => str_starts_with($path, '/!trash'),
                'type' => (string) ($row['ptHandle'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'versions' => (int) $row['versions'],
                'latestVersion' => $latest,
                'approvedVersion' => $approved,
                'children' => $children[$id] ?? [],
            ];

            if ($path === self::FIXTURE_ROOT || str_starts_with($path, self::FIXTURE_ROOT . '/')) {
                $page['attributes'] = $latest === null ? [] : $this->attributes($id, $latest);
                $page['latestBlocks'] = $latest === null ? [] : $this->blocks($id, $latest);
                $page['approvedBlocks'] = $approved === null ? [] : $this->blocks($id, $approved);
            }

            $pages[$id] = $page;
        }

        return $pages;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(int $pageID, int $versionID): array
    {
        $page = Page::getByID($pageID, $versionID);
        if (!$page || $page->isError()) {
            return [];
        }

        $attributes = [];
        foreach ($this->attributeHandles() as $handle) {
            $value = $this->scalar($page->getAttribute($handle));
            if ($value !== null && $value !== '') {
                $attributes[$handle] = $value;
            }
        }
        ksort($attributes);

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function attributeHandles(): array
    {
        if ($this->attributeHandles === null) {
            $this->attributeHandles = [];
            foreach (CollectionKey::getList() as $key) {
                $handle = (string) $key->getAttributeKeyHandle();
                if ($handle !== '') {
                    $this->attributeHandles[] = $handle;
                }
            }
        }

        return $this->attributeHandles;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return is_object($value) ? $value::class : null;
    }

    /**
     * @return list<array{area: string, type: string, order: int, data: array<string, string|null>}>
     */
    private function blocks(int $pageID, int $versionID): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT cvb.arHandle, cvb.bID, cvb.cbDisplayOrder, bt.btHandle
             FROM CollectionVersionBlocks cvb
             JOIN Blocks b ON b.bID = cvb.bID
             JOIN BlockTypes bt ON bt.btID = b.btID
             WHERE cvb.cID = ? AND cvb.cvID = ?
             ORDER BY cvb.arHandle, cvb.cbDisplayOrder, cvb.bID',
            [$pageID, $versionID]
        );

        $blocks = [];
        foreach ($rows as $row) {
            $blocks[] = [
                'area' => (string) $row['arHandle'],
                'type' => (string) $row['btHandle'],
                'order' => (int) $row['cbDisplayOrder'],
                'data' => $this->blockData((string) $row['btHandle'], (int) $row['bID']),
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, string|null>
     */
    private function blockData(string $handle, int $blockID): array
    {
        if (!array_key_exists($handle, $this->blockTables)) {
            $this->blockTables[$handle] = null;
            try {
                $type = BlockType::getByHandle($handle);
                $table = $type ? $type->getController()->getBlockTypeDatabaseTable() : null;
                if (is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) {
                    $this->blockTables[$handle] = $table;
                }
            } catch (\Throwable) {
                // A block type with no controller table has no data to compare.
            }
        }

        $table = $this->blockTables[$handle];
        if ($table === null) {
            return [];
        }

        $row = $this->db->fetchAssociative("SELECT * FROM `{$table}` WHERE bID = ?", [$blockID]);
        if (!is_array($row)) {
            return [];
        }

        unset($row['bID']);
        $data = array_map(static fn ($v): ?string => $v === null ? null : (string) $v, $row);

        // P3.3-6: rows in tables named after the block's own (an accordion's entries), as JSON under the table name,
        // without their row and block IDs, so a copy into a draft compares equal.
        foreach (BlockSaveInputs::childRows($table, $blockID) as $child => $rows) {
            $data[$child] = (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function files(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT f.fID, f.folderTreeNodeID AS folderID,
                fv.fvTitle AS title, fv.fvFilename AS filename, fv.fvSize AS size, fv.fvDescription AS description,
                (SELECT COUNT(*) FROM FileVersions x WHERE x.fID = f.fID) AS versions,
                (SELECT tn.treeNodeParentID FROM TreeFileNodes tfn JOIN TreeNodes tn ON tn.treeNodeID = tfn.treeNodeID WHERE tfn.fID = f.fID LIMIT 1) AS nodeParent
             FROM Files f
             JOIN FileVersions fv ON fv.fID = f.fID
                AND fv.fvID = (SELECT MAX(y.fvID) FROM FileVersions y WHERE y.fID = f.fID AND y.fvIsApproved = 1)
             ORDER BY f.fID'
        );

        $folderNames = [];
        $files = [];
        foreach ($rows as $row) {
            $parent = $row['nodeParent'] === null ? null : (int) $row['nodeParent'];
            if ($parent !== null && !array_key_exists($parent, $folderNames)) {
                $name = $this->db->fetchOne('SELECT treeNodeName FROM TreeNodes WHERE treeNodeID = ?', [$parent]);
                $folderNames[$parent] = $name === false || $name === null ? null : (string) $name;
            }

            $files[] = [
                'id' => (int) $row['fID'],
                'title' => (string) $row['title'],
                'filename' => (string) $row['filename'],
                'size' => (int) $row['size'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'versions' => (int) $row['versions'],
                'folderID' => $row['folderID'] === null ? null : (int) $row['folderID'],
                'nodeParent' => $parent,
                'folder' => $parent === null ? null : $folderNames[$parent],
            ];
        }

        return $files;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function people(): ?array
    {
        if (!$this->db->fetchOne("SHOW TABLES LIKE 'KatalysisPeople'")) {
            return null;
        }

        $people = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM KatalysisPeople ORDER BY sID') as $row) {
            $row = array_map(static fn ($v): ?string => $v === null ? null : (string) $v, $row);
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $people[$email !== '' ? $email : 'sID:' . $row['sID']] = $row;
        }

        return $people;
    }
}
