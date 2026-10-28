<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Attribute\SetFactory;
use Concrete\Core\Support\Facade\Application;

/**
 * The approval cards of the attribute definitions and attribute sets (P3.6-2): what would be created or added, and the
 * state it depends on (whether the handle is taken, the set's members). These are not page versions: they take effect
 * at once. Reads only.
 */
final class AttributePreviews
{
    private const CATEGORY_NAMES = ['collection' => 'page', 'user' => 'user', 'file' => 'file'];

    public static function createPageAttribute(array $inputs): array
    {
        return self::createAttribute('collection', $inputs);
    }

    public static function createUserAttribute(array $inputs): array
    {
        return self::createAttribute('user', $inputs);
    }

    public static function createFileAttribute(array $inputs): array
    {
        return self::createAttribute('file', $inputs);
    }

    public static function createPageAttributeInSet(array $inputs): array
    {
        [$entry, $state] = self::createAttribute('collection', $inputs);
        $set = PreviewSupport::string($inputs, 'set_handle');
        $entry['details'][] = 'Added to the set: ' . ($set !== '' ? $set : '(none given)');
        $state['set'] = self::setMembers('collection', $set);

        return [$entry, $state];
    }

    public static function createSet(array $inputs): array
    {
        $category = self::category($inputs);
        $handle = PreviewSupport::string($inputs, 'handle');

        return [
            PreviewSupport::entry(
                'Create the ' . self::CATEGORY_NAMES[$category] . ' attribute set',
                PreviewSupport::string($inputs, 'name') . ($handle !== '' ? ' (' . $handle . ')' : ''),
                '',
                [],
                PreviewSupport::LIVE_NOTE
            ),
            ['category' => $category, 'set' => self::setMembers($category, $handle)],
        ];
    }

    public static function addToSet(array $inputs): array
    {
        $category = self::category($inputs);
        $set = PreviewSupport::string($inputs, 'set_handle');
        $attribute = PreviewSupport::string($inputs, 'attribute_handle');

        return [
            PreviewSupport::entry('Add the attribute ' . $attribute . ' to the set', $set, '', [], PreviewSupport::LIVE_NOTE),
            ['category' => $category, 'set' => self::setMembers($category, $set), 'attribute' => self::exists($category, $attribute)],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function createAttribute(string $category, array $inputs): array
    {
        $handle = PreviewSupport::string($inputs, 'handle');
        $details = ['Type: ' . (PreviewSupport::string($inputs, 'type') ?: '(not given)')];
        $options = array_values(array_filter((array) ($inputs['options'] ?? []), 'is_scalar'));
        if ($options !== []) {
            $details[] = 'Options: ' . implode(', ', array_map(static fn ($o): string => PreviewSupport::short((string) $o, 60), $options));
        }

        return [
            PreviewSupport::entry(
                'Create the ' . self::CATEGORY_NAMES[$category] . ' attribute',
                PreviewSupport::string($inputs, 'name') . ($handle !== '' ? ' (' . $handle . ')' : ''),
                '',
                $details,
                PreviewSupport::LIVE_NOTE
            ),
            ['category' => $category, 'handle' => $handle, 'exists' => self::exists($category, $handle)],
        ];
    }

    private static function category(array $inputs): string
    {
        $category = strtolower(PreviewSupport::string($inputs, 'category')) ?: 'collection';

        return isset(self::CATEGORY_NAMES[$category]) ? $category : 'collection';
    }

    private static function exists(string $category, string $handle): bool
    {
        if ($handle === '') {
            return false;
        }
        try {
            $entity = Application::getFacadeApplication()->make(CategoryService::class)->getByHandle($category);

            return $entity !== null && $entity->getController()->getAttributeKeyByHandle($handle) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string>|null the handles in the set, or null when there is no such set */
    private static function setMembers(string $category, string $handle): ?array
    {
        if ($handle === '') {
            return null;
        }
        try {
            $set = Application::getFacadeApplication()->make(SetFactory::class)->getByHandle($handle);
            if ($set === null) {
                return null;
            }
            $members = array_map(static fn ($key): string => (string) $key->getAttributeKeyHandle(), $set->getAttributeKeys());
            sort($members);

            return $members;
        } catch (\Throwable) {
            return null;
        }
    }
}
