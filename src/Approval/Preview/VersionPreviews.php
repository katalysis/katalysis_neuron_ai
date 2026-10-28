<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\Approval\DraftOwner;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;

/**
 * The approval cards of the page version writes (P3.6-2): starting a draft, and discarding one. Reads only.
 */
final class VersionPreviews
{
    public static function createDraft(array $inputs): array
    {
        $page = PreviewSupport::newest(PreviewSupport::int($inputs, 'pageID'));
        if ($page === null) {
            return [PreviewSupport::entry('Start an unpublished draft of', 'a page that was not found', '', [], ''), ['missing' => $inputs['pageID'] ?? null]];
        }

        $version = $page->getVersionObject();
        $isDraft = $version && $version->isNew() && !$version->isApproved();
        $based = PreviewSupport::int($inputs, 'basedOnVersionID');
        $details = [];
        if ($isDraft && $based === 0) {
            $details[] = sprintf(
                'The page already has a draft (version %d, by %s): nothing new is made, and later changes go into it.',
                (int) $page->getVersionID(),
                self::userName((int) $version->getVersionAuthorUserID())
            );
        } else {
            $details[] = 'A copy of ' . ($based > 0 ? 'version ' . $based : 'the live version') . ', unpublished.';
        }
        $draft = DraftOwner::of($page, ApprovalService::currentUserId());

        return [
            PreviewSupport::entry('Start an unpublished draft of', PreviewSupport::name($page), PreviewSupport::path($page), $details, 'Nothing goes live until someone publishes it.', $draft['warnings']),
            ['cID' => (int) $page->getCollectionID(), 'cvID' => (int) $page->getVersionID(), 'based' => $based] + $draft['state'],
        ];
    }

    public static function discard(array $inputs): array
    {
        $cID = PreviewSupport::int($inputs, 'pageID');
        $cvID = PreviewSupport::int($inputs, 'versionID');
        $page = $cID > 0 && $cvID > 0 ? Page::getByID($cID, (string) $cvID) : null;
        if (!$page || $page->isError() || !$page->getVersionObject()) {
            return [PreviewSupport::entry('Discard unpublished version ' . $cvID . ' of', 'a page or version that was not found', '', [], ''), ['missing' => [$cID, $cvID]]];
        }

        $version = $page->getVersionObject();
        $details = [sprintf('Made by %s on %s.', self::userName((int) $version->getVersionAuthorUserID()), (string) $version->getVersionDateCreated())];
        $changes = DraftOwner::changesAgainstLive($page);
        if ($changes !== []) {
            $details[] = 'It changes: ' . implode('; ', $changes) . '. Those changes are lost.';
        }

        return [
            PreviewSupport::entry('Discard unpublished version ' . $cvID . ' of', PreviewSupport::name($page), PreviewSupport::path($page), $details, 'The live version is not touched.'),
            ['cID' => $cID, 'cvID' => $cvID, 'new' => $version->isNew(), 'approved' => $version->isApproved(), 'rows' => hash('sha256', (string) json_encode(DraftCleanup::fingerprint($cID, $cvID)))],
        ];
    }

    private static function userName(int $uID): string
    {
        $user = $uID > 0 ? Application::getFacadeApplication()->make(UserInfoRepository::class)->getByID($uID) : null;

        return $user ? (string) $user->getUserName() : 'someone';
    }
}
