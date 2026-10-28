<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template;
use Concrete\Core\Page\Type\Type as PageType;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\Utilities\PageByName;

/**
 * What a gated call would change, read before it runs (P3.6-1, plans/P3.6-1.md §1, §4, §5): the change entry the
 * user approves or denies, and a fingerprint of the state the entry was read from. Reads only.
 *
 * A change entry is one shape for the preview card ("will") and the turn's "Changed: …" line ("did"):
 * {action, target, path, details[], note}. Every string in it is redacted. The server writes all of its text; the
 * panel shows it with textContent only (BF-11).
 *
 * The fingerprint is a sha256 of what the change depends on: the page (or parent) ID, its newest version ID and the
 * current values of the fields the call names. A different fingerprint at Approve means the page changed after the
 * preview, and the call is not run (ApprovalService). Calls in one batch are all checked before any of them runs, so
 * one call's own write never makes the next one stale.
 */
final class ChangePreview
{
    /**
     * @param array<string, mixed> $inputs the call's inputs
     * @return array{entry: array{action: string, target: string, path: string, details: list<string>, note: string}, fingerprint: string}
     */
    public static function of(string $tool, array $inputs): array
    {
        [$entry, $state] = match ($tool) {
            'update_page' => self::updatePage($inputs),
            'set_page_attribute_value' => self::setAttribute($inputs),
            'create_page' => self::createPage($inputs),
            default => [self::entry('Run ' . $tool, '', '', [], ''), ['tool' => $tool, 'inputs' => $inputs]],
        };

        return ['entry' => $entry, 'fingerprint' => self::hash($tool, $state)];
    }

    /**
     * The fingerprint alone, computed again at Approve.
     *
     * @param array<string, mixed> $inputs
     */
    public static function fingerprint(string $tool, array $inputs): string
    {
        return self::of($tool, $inputs)['fingerprint'];
    }

    private static function updatePage(array $inputs): array
    {
        $page = self::newest(is_numeric($inputs['pageID'] ?? null) ? (int) $inputs['pageID'] : 0);
        if ($page === null) {
            return [self::entry('Change page', 'page ' . (int) ($inputs['pageID'] ?? 0), '', [], self::VERSION_NOTE), ['missing' => $inputs['pageID'] ?? null]];
        }

        $visible = self::canView($page);
        $details = [];
        $state = ['cID' => (int) $page->getCollectionID(), 'cvID' => (int) $page->getVersionID()];
        if (isset($inputs['name']) && is_string($inputs['name'])) {
            $state['name'] = (string) $page->getCollectionName();
            $details[] = self::change('Name', $visible ? $state['name'] : null, $inputs['name']);
        }
        if (isset($inputs['description']) && is_string($inputs['description'])) {
            $state['description'] = (string) $page->getCollectionDescription();
            $details[] = self::change('Description', $visible ? $state['description'] : null, $inputs['description']);
        }
        if (isset($inputs['template']) && is_string($inputs['template']) && trim($inputs['template']) !== '') {
            $state['template'] = (string) $page->getPageTemplateHandle();
            $template = Template::getByHandle($inputs['template']);
            $details[] = self::change('Template', $visible ? $state['template'] : null, $template ? (string) $template->getPageTemplateName() : $inputs['template']);
        }
        foreach ((array) ($inputs['attributes'] ?? []) as $handle => $value) {
            $key = CollectionKey::getByHandle((string) $handle);
            $current = $key ? self::text($page->getAttribute($key)) : '';
            $state['attributes'][(string) $handle] = $current;
            $details[] = self::change($key ? (string) $key->getAttributeKeyName() : (string) $handle, $visible ? $current : null, self::text($value));
        }

        return [self::entry('Change page', self::name($page, $visible), self::path($page, $visible), $details, self::VERSION_NOTE), $state];
    }

    private static function setAttribute(array $inputs): array
    {
        $page = null;
        if (is_numeric($inputs['pageID'] ?? null) && (int) $inputs['pageID'] > 0) {
            $page = self::newest((int) $inputs['pageID']);
        } elseif (is_string($inputs['pagePath'] ?? null) && $inputs['pagePath'] !== '') {
            $found = Page::getByPath($inputs['pagePath']);
            $page = $found && !$found->isError() ? self::newest((int) $found->getCollectionID()) : null;
        } elseif (is_string($inputs['pageName'] ?? null) && $inputs['pageName'] !== '') {
            try {
                $page = self::newest((int) PageByName::find($inputs['pageName'])->getCollectionID());
            } catch (\RuntimeException) {
                $page = null;
            }
        }

        $handle = is_string($inputs['handle'] ?? null) ? $inputs['handle'] : '';
        $key = $handle !== '' ? CollectionKey::getByHandle($handle) : null;
        $label = $key ? (string) $key->getAttributeKeyName() : $handle;
        $value = self::text($inputs['value'] ?? '');
        if ($page === null) {
            return [self::entry('Set ' . $label, 'a page that was not found', '', [self::change($label, null, $value)], self::VERSION_NOTE), ['missing' => $inputs]];
        }

        $visible = self::canView($page);
        $current = $key ? self::text($page->getAttribute($key)) : '';
        $state = ['cID' => (int) $page->getCollectionID(), 'cvID' => (int) $page->getVersionID(), 'handle' => $handle, 'value' => $current];

        return [self::entry('Set ' . $label, self::name($page, $visible), self::path($page, $visible), [self::change($label, $visible ? $current : null, $value)], self::VERSION_NOTE), $state];
    }

    private static function createPage(array $inputs): array
    {
        $name = is_string($inputs['name'] ?? null) ? $inputs['name'] : '';
        $typeHandle = is_string($inputs['pageType'] ?? null) && $inputs['pageType'] !== '' ? $inputs['pageType'] : 'page';
        $templateHandle = is_string($inputs['template'] ?? null) && trim($inputs['template']) !== '' ? $inputs['template'] : 'full';
        $type = PageType::getByHandle($typeHandle);
        $template = Template::getByHandle($templateHandle);
        $details = [
            'Name: "' . $name . '"',
            'Page type: ' . ($type ? (string) $type->getPageTypeDisplayName('text') : $typeHandle),
            'Template: ' . ($template ? (string) $template->getPageTemplateName() : $templateHandle),
        ];
        $note = "It will be unpublished: visitors won't see it until someone publishes it.";

        $parent = self::newest(is_numeric($inputs['parentID'] ?? null) ? (int) $inputs['parentID'] : 0);
        if ($parent === null) {
            return [self::entry('Create page', '"' . $name . '" under a page that was not found', '', $details, $note), ['missing' => $inputs['parentID'] ?? null]];
        }

        $visible = self::canView($parent);
        $children = array_map(static fn (Page $child): string => (string) $child->getCollectionHandle(), $parent->getCollectionChildren());
        sort($children);
        $state = [
            'parent' => (int) $parent->getCollectionID(),
            'cvID' => (int) $parent->getVersionID(),
            'children' => $children,
            'canAdd' => ObjectPermissions::addSubpage($parent, $type ?: null) === null,
        ];

        return [self::entry('Create page', '"' . $name . '" under ' . self::name($parent, $visible), self::path($parent, $visible), $details, $note), $state];
    }

    private const VERSION_NOTE = 'Saved in a new unpublished version; nothing goes live until someone publishes it.';

    /**
     * @param list<string> $details
     * @return array{action: string, target: string, path: string, details: list<string>, note: string}
     */
    private static function entry(string $action, string $target, string $path, array $details, string $note): array
    {
        return [
            'action' => SecretRedactor::redact($action),
            'target' => SecretRedactor::redact($target),
            'path' => SecretRedactor::redact($path),
            'details' => array_map(static fn (string $d): string => SecretRedactor::redact($d), $details),
            'note' => $note,
        ];
    }

    /** "Label: "old" → "new"", with "(not shown)" for a value the user may not view. */
    private static function change(string $label, ?string $old, string $new): string
    {
        $before = $old === null ? '(not shown)' : ($old === '' ? '(empty)' : '"' . self::short($old) . '"');

        return $label . ': ' . $before . ' → ' . ($new === '' ? '(empty)' : '"' . self::short($new) . '"');
    }

    private static function short(string $text): string
    {
        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }

    private static function newest(int $cID): ?Page
    {
        if ($cID <= 0) {
            return null;
        }
        $page = Page::getByID($cID, 'RECENT');

        return $page && !$page->isError() ? $page : null;
    }

    private static function canView(Page $page): bool
    {
        return ObjectPermissions::viewPage($page) === null;
    }

    private static function name(Page $page, bool $visible): string
    {
        return $visible ? (string) $page->getCollectionName() : 'page ' . $page->getCollectionID();
    }

    private static function path(Page $page, bool $visible): string
    {
        if (!$visible) {
            return '';
        }
        $path = (string) $page->getCollectionPath();

        return $path === '' ? '/' : $path;
    }

    /** An attribute value or an input as plain text, for the preview and the fingerprint. */
    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => trim((string) $value),
            $value instanceof \DateTimeInterface => $value->format('c'),
            is_object($value) && method_exists($value, '__toString') => trim((string) $value),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }

    private static function hash(string $tool, array $state): string
    {
        return hash('sha256', $tool . "\n" . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
