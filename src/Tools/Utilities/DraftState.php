<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Page\Page;

/**
 * What a write to a page draft tells the model about the version it wrote (BF-7; B-WRT-3, B-REP-1 (c)). By rule 5
 * this is in the tool result, not the prompt: the facts in `summary`, and the one short line the reply should be in
 * `reply` (Glyn's decision, plans/BF-7.md §11).
 *
 * The labels are Concrete 9's own: "Publish Changes" in edit mode's check-in panel (Composer::getPublishButtonTitle()),
 * "Approve Version" in the notice an unpublished version shows outside edit mode (page_controls_footer.php).
 * DraftStateResultTest checks every draft-writing tool carries it, and that the wording never trips the injection
 * detector.
 */
final class DraftState
{
    public const REPLY = 'Reply in one short, natural line, for example: "Done. You can see it on the page now; it goes live when you publish." '
        . 'Give the details below only if the user asks.';

    public const NEW_PAGE_REPLY = 'Reply in one short, natural line, for example: "Done. The page is created but not published yet." '
        . 'Give the details below only if the user asks.';

    /**
     * @return array{versionState: array{status: string, pageID: int, versionID: int, summary: string, reply: string}}
     */
    public static function of(Page $draft): array
    {
        $versionID = (int) $draft->getVersionID();

        return ['versionState' => [
            'status' => 'unpublished',
            'pageID' => (int) $draft->getCollectionID(),
            'versionID' => $versionID,
            'summary' => self::summary($versionID),
            'reply' => self::REPLY,
        ]];
    }

    /**
     * For a page create_page made: it has no approved version, so visitors get a 404 until it is approved (P1.13).
     *
     * @return array{versionState: array{status: string, pageID: int, versionID: int, summary: string, reply: string}}
     */
    public static function ofNewPage(Page $page): array
    {
        $versionID = (int) $page->getVersionID();

        return ['versionState' => [
            'status' => 'unpublished',
            'pageID' => (int) $page->getCollectionID(),
            'versionID' => $versionID,
            'summary' => "The new page is unpublished (version {$versionID}): visitors can't see it until someone opens it "
                . 'and clicks "Approve Version", or approves it in Page Versions.',
            'reply' => self::NEW_PAGE_REPLY,
        ]];
    }

    public static function summary(int $versionID): string
    {
        return "Saved in version {$versionID} of the page, which is unpublished: it is on the page now for editors, and it "
            . 'goes live only when someone clicks "Publish Changes" in edit mode, or "Approve Version" outside edit mode. '
            . 'To undo it, restore the previous version from Page Versions.';
    }
}
