<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Block\BlockType\BlockType;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Stack\Stack;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Access\ObjectPermissions;

/**
 * A stack or global area a block tool writes to (P3.6a, plans/P3.6a.md §1; B-WRT-5 (b)). The block tools edit it the
 * way edit mode and Dashboard > Stacks & Global Areas do (plans/BF-2.md 2.1):
 *
 * - **Draft only.** The change goes into the stack's version to modify (an unapproved version, made through
 *   PageDraft::of(), so the approval dry run stops before it and a failed call removes it). Through a page that shows a
 *   global area, that version is related to the page's draft, as Concrete relates it, so publishing the page publishes
 *   it (Concrete approves it only for someone with "Approve Changes" on the stack). On the stack itself, it is
 *   approved under Stacks & Global Areas. The assistant approves neither.
 * - **The stack's own permissions**, after the page's: Edit Contents on the stack, and Add Block to Area on its Main area,
 *   or Edit Block or Delete Block on its block. The stack's edit mode counts as the page's.
 * - **A notice** (notice()): the change appears on every page that shows the area, with the count and a few examples.
 *
 * of() returns null for an area that isn't shared, so every other write keeps its own path.
 */
final class SharedArea
{
    /** How many pages a notice names. */
    private const EXAMPLES = 3;

    private function __construct(
        private readonly Stack $stack,
        private readonly bool $throughPage,
    ) {
    }

    /**
     * The shared area that a block tool's page and area name: a global area on an ordinary page, or a stack itself
     * (pageID is the stack's page). Null for an area of an ordinary page.
     */
    public static function of(Page $page, string $areaHandle): ?self
    {
        if (SystemPageGuard::isStack($page)) {
            $stack = Stack::getByID((int) $page->getCollectionID());

            return $stack ? new self($stack, false) : null;
        }

        $area = Area::get($page, $areaHandle);
        if (!$area instanceof Area || !$area->isGlobalArea()) {
            return null;
        }
        if (!SystemPageGuard::stackEditingEnabled()) {
            throw new \RuntimeException(sprintf(
                'The area "%s" on this page is a global area, shared by every page that shows it. The assistant can\'t edit '
                . 'stacks or global areas. Nothing was changed. Tell the user they can edit it in edit mode or under '
                . 'Dashboard > Stacks & Global Areas.',
                $areaHandle
            ));
        }
        // The stack Concrete's own block paths use for this area (GetBlockToEditTrait).
        $stack = Stack::getByName($areaHandle);
        if (!$stack) {
            throw new \RuntimeException(sprintf(
                'The global area "%s" has no content yet: Concrete makes its stack the first time a page shows it. Nothing '
                . 'was changed. Tell the user to open a page that shows it, or to edit it in edit mode.',
                $areaHandle
            ));
        }

        return new self($stack, true);
    }

    public function name(): string
    {
        return (string) $this->stack->getStackName();
    }

    /** 'global area' or 'stack'. */
    public function kind(): string
    {
        return (int) $this->stack->getStackType() === Stack::ST_TYPE_GLOBAL_AREA ? 'global area' : 'stack';
    }

    /** How the refusals and the card name it: 'the global area "Header Navigation"'. */
    public function label(): string
    {
        return 'the ' . $this->kind() . ' "' . $this->name() . '"';
    }

    public function stackID(): int
    {
        return (int) $this->stack->getCollectionID();
    }

    public function isThroughPage(): bool
    {
        return $this->throughPage;
    }

    /** The stack's newest version, as Concrete's block paths read it. */
    public function stackPage(): Page
    {
        return Page::getByID($this->stackID(), 'RECENT');
    }

    /** The area on the stack that holds the blocks (STACKS_AREA_NAME, "Main"). */
    public function stackAreaHandle(): string
    {
        return STACKS_AREA_NAME;
    }

    /**
     * The checks on the stack itself, after the page's own: another user's edit mode on the stack, then Edit Contents,
     * then the block's or the area's permission. $doing is 'add', 'edit' or 'delete'.
     */
    public function refuseUnlessAllowed(string $doing, ?int $blockID = null, ?BlockType $type = null): void
    {
        $stackPage = $this->stackPage();
        EditModeGuard::refuse($stackPage);
        ObjectPermissions::deny(ObjectPermissions::editStackContents($stackPage, $this->label()));
        if ($doing === 'add' && $type !== null) {
            $area = Area::get($stackPage, $this->stackAreaHandle()) ?: Area::getOrCreate($stackPage, $this->stackAreaHandle());
            ObjectPermissions::deny(ObjectPermissions::addBlockToStack($area, $type, $this->label()));

            return;
        }
        $block = $blockID !== null ? Block::getByID($blockID, $stackPage, $this->stackAreaHandle()) : null;
        if ($block) {
            ObjectPermissions::deny($doing === 'delete'
                ? ObjectPermissions::deleteStackBlock($block, $this->label())
                : ObjectPermissions::editStackBlock($block, $this->label()));
        }
    }

    /**
     * The stack version to write to: its draft (made or reused through PageDraft::of(), which stops a dry run), related
     * to the page's draft when the area is reached through a page, as Concrete relates them.
     */
    public function versionToModify(?Page $pageDraft): Page
    {
        $stackDraft = PageDraft::of($this->stackPage());
        if ($this->throughPage && $pageDraft !== null) {
            $pageDraft->relateVersionEdits($stackDraft);
        }

        return $stackDraft;
    }

    /**
     * What the tool result tells the model, and the card the user: the change shows on every page that shows the area,
     * how many and which, and when it reaches the site.
     *
     * @return array{name: string, kind: string, pages: int, examples: list<string>, notice: string}
     */
    public function notice(): array
    {
        [$count, $examples] = self::pagesShowing($this->stack);
        $where = $count === 0
            ? 'no page has been recorded as showing it yet'
            : sprintf('%s %d %s, e.g. %s', $this->kind() === 'global area' ? 'at least' : '', $count, $count === 1 ? 'page' : 'pages', implode(', ', $examples));
        // Concrete publishes a stack version with the page only for someone allowed to approve the stack (Version::approve()).
        $canApprove = ObjectPermissions::approvePageVersions($this->stackPage()) === null;
        $when = match (true) {
            $this->throughPage && $canApprove => 'It reaches the site when this page\'s changes are published: Concrete publishes the ' . $this->kind() . '\'s new version with them.',
            $this->throughPage => 'Publishing this page won\'t publish it, because you can\'t approve the ' . $this->kind() . ': it reaches the site '
                . 'when someone who can approves its changes under Dashboard > Stacks & Global Areas.',
            default => 'It reaches the site when someone approves the ' . $this->kind() . '\'s changes under Dashboard > Stacks & Global Areas.',
        };

        return [
            'name' => $this->name(),
            'kind' => $this->kind(),
            'pages' => $count,
            'examples' => $examples,
            'notice' => sprintf('This changes %s, which is shared: it appears on every page that shows it (%s). %s', $this->label(), trim($where), $when),
        ];
    }

    /**
     * The live, ordinary pages that show the stack, and up to three of them as "Name (/path)". A global area: the pages
     * with its global Areas row (Concrete writes it the first time a page shows the area, so this is a floor). A stack:
     * the pages whose live version holds a Stack block for it.
     *
     * @return array{0: int, 1: list<string>}
     */
    public static function pagesShowing(Stack $stack): array
    {
        $db = Application::getFacadeApplication()->make(Connection::class);
        $live = 'JOIN Pages p ON p.cID = x.cID AND p.cIsActive = 1 AND p.cIsSystemPage = 0 AND p.cIsTemplate = 0'
            . ' JOIN CollectionVersions cv ON cv.cID = p.cID AND cv.cvIsApproved = 1'
            . ' LEFT JOIN PagePaths pp ON pp.cID = p.cID AND pp.ppIsCanonical = 1';
        $notTrash = " WHERE (pp.cPath IS NULL OR pp.cPath NOT LIKE '/!trash%')";
        if ((int) $stack->getStackType() === Stack::ST_TYPE_GLOBAL_AREA) {
            $from = 'FROM (SELECT DISTINCT cID FROM Areas WHERE arHandle = ? AND arIsGlobal = 1) x ' . $live . $notTrash;
            $params = [(string) $stack->getStackName()];
        } else {
            $from = 'FROM (SELECT DISTINCT cvb.cID, cvb.cvID FROM btCoreStackDisplay s JOIN CollectionVersionBlocks cvb ON cvb.bID = s.bID WHERE s.stID = ?) x '
                . $live . $notTrash . ' AND cv.cvID = x.cvID';
            $params = [(int) $stack->getCollectionID()];
        }
        $count = (int) $db->fetchOne('SELECT COUNT(DISTINCT p.cID) ' . $from, $params);
        $examples = array_map(
            static fn (array $row): string => $row['cvName'] . ' (' . ($row['cPath'] ?: '/') . ')',
            $db->fetchAllAssociative('SELECT p.cID, MAX(cv.cvName) AS cvName, MAX(pp.cPath) AS cPath ' . $from . ' GROUP BY p.cID ORDER BY p.cID LIMIT ' . self::EXAMPLES, $params)
        );

        return [$count, $examples];
    }
}
