<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfoRepository;

/**
 * Whose unpublished changes a page write would join (P3.6-2, plans/P3.6-2.md §2; from P1.8a). A draft belongs to the
 * page: PageDraft::of() reuses the newest version while it is still a draft, whoever made it. So before Approve the card
 * says when that draft already holds someone else's changes, and what they change against the live version. Reads only.
 */
final class DraftOwner
{
    /**
     * For a page about to be written: the warnings for the card (none when the newest version is not a draft, or is the
     * user's own), and what the fingerprint must cover (the draft's version, author and state).
     *
     * @return array{warnings: list<string>, state: array<string, mixed>}
     */
    public static function of(Page $newest, int $userId): array
    {
        $version = $newest->getVersionObject();
        $isDraft = $version && $version->isNew() && !$version->isApproved();
        $author = $isDraft ? (int) $version->getVersionAuthorUserID() : 0;
        $state = ['draft' => $isDraft ? (int) $newest->getVersionID() : 0, 'author' => $author];
        if (!$isDraft || $author === $userId) {
            return ['warnings' => [], 'state' => $state];
        }

        $who = $author > 0 ? Application::getFacadeApplication()->make(UserInfoRepository::class)->getByID($author) : null;
        $name = $who ? (string) $who->getUserName() : 'someone else';
        $date = $version->getVersionDateCreated();
        $warnings = [sprintf(
            'This page already has unpublished changes by %s (version %d, %s). Your change is added to that version.',
            $name,
            (int) $newest->getVersionID(),
            $date ? date('j M Y H:i', strtotime((string) $date)) : 'date unknown'
        )];
        $changes = self::changesAgainstLive($newest);
        if ($changes !== []) {
            $warnings[] = 'That version already changes: ' . implode('; ', $changes) . '.';
        }

        return ['warnings' => $warnings, 'state' => $state];
    }

    /**
     * What the draft changes against the live version, in short words: the page fields, and per area how many blocks
     * were added, changed or removed.
     *
     * @return list<string>
     */
    public static function changesAgainstLive(Page $draft): array
    {
        $live = Page::getByID((int) $draft->getCollectionID(), 'ACTIVE');
        if (!$live || $live->isError() || !$live->getVersionObject() || !$live->getVersionObject()->isApproved()) {
            return ['everything (the page has never been published)'];
        }

        $changes = [];
        foreach (['name' => [$live->getCollectionName(), $draft->getCollectionName()], 'description' => [$live->getCollectionDescription(), $draft->getCollectionDescription()], 'template' => [$live->getPageTemplateID(), $draft->getPageTemplateID()]] as $field => [$before, $after]) {
            if ((string) $before !== (string) $after) {
                $changes[] = $field;
            }
        }

        $db = Application::getFacadeApplication()->make(Connection::class);
        $blocks = static fn (int $cvID): array => $db->fetchAllKeyValue(
            'SELECT bID, arHandle FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ?',
            [(int) $draft->getCollectionID(), $cvID]
        );
        $old = $blocks((int) $live->getVersionID());
        $new = $blocks((int) $draft->getVersionID());
        $byArea = [];
        foreach (array_diff_key($new, $old) as $area) {
            $byArea[$area]['added or changed'] = ($byArea[$area]['added or changed'] ?? 0) + 1;
        }
        foreach (array_diff_key($old, $new) as $area) {
            $byArea[$area]['replaced or removed'] = ($byArea[$area]['replaced or removed'] ?? 0) + 1;
        }
        foreach ($byArea as $area => $counts) {
            $parts = [];
            foreach ($counts as $what => $count) {
                $parts[] = $count . ' ' . ($count === 1 ? 'block' : 'blocks') . ' ' . $what;
            }
            $changes[] = $area . ': ' . implode(', ', $parts);
        }
        $attributes = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM CollectionAttributeValues n LEFT JOIN CollectionAttributeValues o ON o.cID = n.cID AND o.akID = n.akID AND o.cvID = ? AND o.avID = n.avID WHERE n.cID = ? AND n.cvID = ? AND o.avID IS NULL',
            [(int) $live->getVersionID(), (int) $draft->getCollectionID(), (int) $draft->getVersionID()]
        );
        if ($attributes > 0) {
            $changes[] = $attributes . ' ' . ($attributes === 1 ? 'attribute' : 'attributes');
        }

        return $changes;
    }
}
