<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Area\GlobalArea;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Stack\Stack;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;

/**
 * Throwaway global areas, stacks and pages for tests (BF-2), and their removal.
 *
 * A global area is a stack (a system page under /!stacks) plus an `Areas` row with arIsGlobal = 1 on each page that
 * shows it. restore() deletes what this fixture made: the pages, the stacks (with Stack::delete(), which also removes
 * the `Stacks` row and the pages' global `Areas` rows; Page::delete() would leave the `Stacks` row), the related-edit
 * rows, and the `PageSummaryTemplates` rows that approving a version adds and Page::delete() leaves behind
 * (documentation/upstream/page-delete-leaves-page-summary-templates.md). The P1.12 leak check proves nothing is left.
 *
 * Make everything as the superuser.
 */
final class GlobalAreaFixture
{
    /** @var int[] */
    private array $pages = [];
    /** @var int[] */
    private array $stacks = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * A new global area holding one approved Content block. Returns [handle, stack page ID, block ID].
     *
     * @return array{string, int, int}
     */
    public function globalArea(string $content = '<p>Original global content</p>'): array
    {
        $handle = 'BF2 Test Area ' . bin2hex(random_bytes(3));
        $stack = Stack::addGlobalArea($handle);
        $stackID = (int) $stack->getCollectionID();
        $this->stacks[] = $stackID;

        $version = Page::getByID($stackID, 'RECENT')->getVersionToModify();
        $block = $version->addBlock(BlockType::getByHandle('content'), STACKS_AREA_NAME, ['content' => $content]);
        $version->getVersionObject()->approve();
        CacheLocal::flush();

        return [$handle, $stackID, (int) $block->getBlockID()];
    }

    /**
     * A new stack (not a global area). Returns its page ID.
     */
    public function stack(): int
    {
        $stack = Stack::addStack('BF2 Test Stack ' . bin2hex(random_bytes(3)));
        $stackID = (int) $stack->getCollectionID();
        $this->stacks[] = $stackID;

        return $stackID;
    }

    /**
     * A new page under /neuron-test, showing the given global areas.
     *
     * @param string[] $globalAreas
     */
    public function page(array $globalAreas = [], string $prefix = 'BF2 Test Page '): Page
    {
        $parent = Page::getByPath('/neuron-test');
        if (!$parent || $parent->isError()) {
            throw new \RuntimeException('/neuron-test does not exist. Run the seed script first.');
        }
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => $prefix . $unique,
            'cHandle' => 'bf2-test-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->pages[] = (int) $page->getCollectionID();
        foreach ($globalAreas as $handle) {
            (new GlobalArea($handle))->create($page, $handle);
        }
        CacheLocal::flush();

        return Page::getByID($page->getCollectionID());
    }

    /**
     * Versions of a page or stack, with the blocks in each, for before/after comparisons.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(int $cID): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT cvID, cvIsApproved FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]) as $v) {
            $v['blocks'] = $this->db->fetchAllAssociative(
                'SELECT bID, arHandle FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? ORDER BY arHandle, cbDisplayOrder',
                [$cID, $v['cvID']]
            );
            $v['related'] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM CollectionVersionRelatedEdits WHERE cID = ? AND cvID = ?', [$cID, $v['cvID']]);
            $out[] = $v;
        }

        return $out;
    }

    public function restore(): void
    {
        CacheLocal::flush();
        $all = array_merge($this->pages, $this->stacks);
        foreach (array_reverse($this->pages) as $cID) {
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
        }
        foreach (array_reverse($this->stacks) as $cID) {
            $stack = Stack::getByID($cID);
            if ($stack) {
                $stack->delete();
            }
        }
        foreach ($all as $cID) {
            $this->db->executeStatement('DELETE FROM PageSummaryTemplates WHERE cID = ?', [$cID]);
            $this->db->executeStatement('DELETE FROM CollectionVersionRelatedEdits WHERE cID = ? OR cRelationID = ?', [$cID, $cID]);
        }
        $this->pages = $this->stacks = [];
        CacheLocal::flush();
    }
}
