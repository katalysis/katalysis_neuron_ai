<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;

/**
 * An assistant edit behaves like a manual edit (BF-7, plans/BF-7.md):
 *
 * - **Another user's edit mode:** a page another user has in edit mode is refused, as Concrete refuses that user's
 *   edit mode to anyone else ("Editing Unavailable. <name> is currently editing this page.", page_controls_footer.php:
 *   isCheckedOut() && !isCheckedOutByMe()). The same user, in any tab, is allowed. Every write tool that takes a page
 *   calls refuse() right after SystemPageGuard::refuse(); EditModeWriteRefusalTest checks that each one does.
 * - **A block open in the user's editor:** the panel sends the IDs of the blocks it has an editor open on, and the
 *   block tools refuse them (refuseOpenBlock()). An edit copies a block into the draft under a new ID, so the editor's
 *   save would fail with Concrete's "Access Denied" and lose what was typed (plans/BF-7.md §2, probe A1).
 */
final class EditModeGuard
{
    /** The page the open-editor block IDs belong to, for this request. */
    private static ?int $openBlocksPageID = null;

    /** @var array<int, true> */
    private static array $openBlockIDs = [];

    /**
     * @throws \RuntimeException when another user has the page in edit mode
     */
    public static function refuse(Page $page): void
    {
        $name = self::editingUserName($page);
        if ($name !== null) {
            throw new \RuntimeException(sprintf(
                '%1$s is editing this page in edit mode. Nothing was changed. Tell the user to try again when %1$s has '
                . 'published or left edit mode.',
                $name
            ));
        }
    }

    /**
     * The user name of someone other than the acting user who has the page in edit mode now, or null.
     *
     * Concrete's rule (Page::isCheckedOut()): checked out, and the last edit (which edit mode's heartbeat keeps fresh) is
     * no older than CHECKOUT_TIMEOUT; with no last-edit time the checkout counts. This reads the row itself rather than
     * calling isCheckedOut(), which checks a timed-out page in as a side effect and caches its answer on the page object.
     */
    public static function editingUserName(Page $page): ?string
    {
        $app = Application::getFacadeApplication();
        $row = $app->make(Connection::class)->fetchAssociative(
            'SELECT cIsCheckedOut, cCheckedOutUID, cCheckedOutDatetimeLastEdit FROM Pages WHERE cID = ?',
            [(int) $page->getCollectionID()]
        );
        if (!$row || (int) $row['cIsCheckedOut'] !== 1 || (int) $row['cCheckedOutUID'] <= 0) {
            return null;
        }

        $lastEdit = $row['cCheckedOutDatetimeLastEdit'] ?? null;
        if (!empty($lastEdit)) {
            $since = $app->make('helper/date')->getOverridableNow(true) - strtotime((string) $lastEdit);
            if ($since > CHECKOUT_TIMEOUT) {
                return null;
            }
        }

        $editorID = (int) $row['cCheckedOutUID'];
        if ($editorID === (int) (new User())->getUserID()) {
            return null;
        }

        $editor = $app->make(UserInfoRepository::class)->getByID($editorID);

        return $editor ? (string) $editor->getUserName() : t('Unknown User');
    }

    /**
     * The blocks the user has an editor open on, on the page the panel is on, for this request. Replaces any earlier
     * set. The IDs come from the browser: they can only make the assistant refuse more, never less.
     *
     * @param array<int, int> $blockIDs
     */
    public static function setOpenBlocks(?int $pageID, array $blockIDs): void
    {
        self::$openBlocksPageID = $pageID;
        self::$openBlockIDs = [];
        foreach ($blockIDs as $blockID) {
            self::$openBlockIDs[(int) $blockID] = true;
        }
    }

    public static function clearOpenBlocks(): void
    {
        self::setOpenBlocks(null, []);
    }

    /**
     * @throws \RuntimeException when the block is open in the user's editor on that page
     */
    public static function refuseOpenBlock(Page $page, int $blockID): void
    {
        if (self::$openBlocksPageID === (int) $page->getCollectionID() && isset(self::$openBlockIDs[$blockID])) {
            throw new \RuntimeException(sprintf(
                'Block %d on this page is open in the user\'s editor. Nothing was changed. Tell the user to save or cancel '
                . 'that edit first, then ask again.',
                $blockID
            ));
        }
    }
}
