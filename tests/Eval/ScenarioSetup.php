<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;

/**
 * A scenario's `setup:` list: content a scenario needs that the seed doesn't have (tests/Scenarios/README.md).
 *
 * The runner applies it after the fixture reset and before the "before" snapshot, so the setup itself is not a change,
 * and undoes it when the scenario ends, by deleting exactly the records it created, by ID. The next reset also removes
 * them (same name and email rules as the seed), so a crashed run can't leak into the next scenario.
 *
 * Step types:
 *  - create_person: {name, email, biography?, short_biography?, active?}
 *  - hand_edit: {page, find, replace} (BF-10): a hand edit in edit mode, as the scenario's user, through the block
 *    edit dialog's own server path (GetBlockToEditTrait, then the content block's save()), in the Content block of the
 *    page's newest version that contains `find`. It leaves an unpublished version, as BF-7's browser check 6 did. The
 *    page must be under /neuron-test, so the next reset discards that version like any draft; undo() has nothing to do.
 */
final class ScenarioSetup
{
    public const NAME_PREFIX = 'Test Person ';
    public const EMAIL_SUFFIX = '@neuron-test.invalid';
    public const PERSON_CLASS = 'Concrete\\Package\\KatalysisPro\\Src\\KatalysisPro\\People\\Person';

    public const PAGE_PREFIX = '/neuron-test/';

    private const STEP_TYPES = ['create_person', 'hand_edit'];
    private const PERSON_KEYS = ['name', 'email', 'biography', 'short_biography', 'active'];
    private const HAND_EDIT_KEYS = ['page', 'find', 'replace'];

    /**
     * @param mixed $setup the value of a scenario's `setup` key
     *
     * @return list<string> problems; empty when the setup is valid
     */
    public static function validate(mixed $setup): array
    {
        if (!is_array($setup) || $setup === [] || !array_is_list($setup)) {
            return ['setup must be a non-empty list.'];
        }

        $errors = [];
        foreach ($setup as $i => $step) {
            $label = 'setup step ' . ($i + 1);
            if (!is_array($step) || count($step) !== 1) {
                $errors[] = "{$label}: must be a mapping with one key, the step type (" . implode(', ', self::STEP_TYPES) . ').';
                continue;
            }

            $type = (string) array_key_first($step);
            if (!in_array($type, self::STEP_TYPES, true)) {
                $errors[] = "{$label}: unknown step type \"{$type}\".";
                continue;
            }

            if (!is_array($step[$type])) {
                $errors[] = "{$label}: {$type} must be a mapping.";
                continue;
            }
            if ($type === 'hand_edit') {
                $errors = array_merge($errors, self::validateHandEdit($label, $step[$type]));
                continue;
            }

            $person = $step[$type];
            foreach (array_diff(array_keys($person), self::PERSON_KEYS) as $key) {
                $errors[] = "{$label}: unknown key \"{$key}\".";
            }
            $name = $person['name'] ?? null;
            if (!is_string($name) || !str_starts_with($name, self::NAME_PREFIX) || trim(substr($name, strlen(self::NAME_PREFIX))) === '') {
                $errors[] = "{$label}: name must start with \"" . self::NAME_PREFIX . '" so the reset removes it.';
            }
            $email = $person['email'] ?? null;
            if (!is_string($email) || !str_ends_with($email, self::EMAIL_SUFFIX) || strlen($email) <= strlen(self::EMAIL_SUFFIX)) {
                $errors[] = "{$label}: email must end with \"" . self::EMAIL_SUFFIX . '" so the reset removes it.';
            }
            foreach (['biography', 'short_biography'] as $field) {
                if (isset($person[$field]) && !is_string($person[$field])) {
                    $errors[] = "{$label}: {$field} must be a string.";
                }
            }
            if (isset($person['active']) && !is_bool($person['active'])) {
                $errors[] = "{$label}: active must be true or false.";
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $edit
     *
     * @return list<string>
     */
    private static function validateHandEdit(string $label, array $edit): array
    {
        $errors = [];
        foreach (array_diff(array_keys($edit), self::HAND_EDIT_KEYS) as $key) {
            $errors[] = "{$label}: unknown key \"{$key}\".";
        }
        $page = $edit['page'] ?? null;
        if (!is_string($page) || !str_starts_with($page, self::PAGE_PREFIX) || strlen($page) <= strlen(self::PAGE_PREFIX)) {
            $errors[] = "{$label}: page must be a path under " . self::PAGE_PREFIX . ' so the reset discards the edit.';
        }
        if (!is_string($edit['find'] ?? null) || $edit['find'] === '') {
            $errors[] = "{$label}: find must be a non-empty string.";
        }
        if (!is_string($edit['replace'] ?? null)) {
            $errors[] = "{$label}: replace must be a string.";
        }

        return $errors;
    }

    /**
     * Creates what the steps describe.
     *
     * @param list<array<string, mixed>> $steps validated steps
     *
     * @return list<int> the IDs of the people created, for undo()
     *
     * @throws \RuntimeException when the People classes are not installed, or a step fails. People created before the
     *                           failure are removed again.
     */
    public function apply(array $steps): array
    {
        $class = self::PERSON_CLASS;
        $created = [];
        try {
            foreach ($steps as $step) {
                if (isset($step['hand_edit'])) {
                    $this->handEdit($step['hand_edit']);
                    continue;
                }
                if (!class_exists($class)) {
                    throw new \RuntimeException('This scenario needs the katalysis_pro People classes, which are not installed.');
                }
                $p = $step['create_person'];
                $person = new $class();
                $person->setPropertiesFromArray([
                    'name' => $p['name'],
                    'email' => $p['email'],
                    'shortBiography' => $p['short_biography'] ?? '',
                    'biography' => $p['biography'] ?? '',
                    'active' => ($p['active'] ?? true) ? 1 : 0,
                    'featured' => 0,
                    'sortOrder' => 9100 + count($created),
                ]);
                $person->save();
                $created[] = (int) $person->getSID();
            }
        } catch (\Throwable $e) {
            $this->undo($created);
            throw new \RuntimeException('The scenario setup failed: ' . $e->getMessage(), 0, $e);
        }

        return $created;
    }

    /**
     * @param array{page: string, find: string, replace: string} $edit
     */
    private function handEdit(array $edit): void
    {
        CacheLocal::flush();
        $found = Page::getByPath($edit['page']);
        if (!$found || $found->isError()) {
            throw new \RuntimeException("hand_edit: there is no page {$edit['page']}.");
        }
        $page = Page::getByID((int) $found->getCollectionID(), 'RECENT');
        $db = Application::getFacadeApplication()->make(Connection::class);
        $rows = $db->fetchAllAssociative(
            'SELECT cvb.bID, cvb.arHandle, c.content FROM CollectionVersionBlocks cvb JOIN btContentLocal c ON c.bID = cvb.bID '
            . 'WHERE cvb.cID = ? AND cvb.cvID = ? ORDER BY cvb.arHandle, cvb.cbDisplayOrder',
            [(int) $page->getCollectionID(), (int) $page->getVersionID()]
        );
        foreach ($rows as $row) {
            if (!str_contains((string) $row['content'], $edit['find'])) {
                continue;
            }
            $editor = new class () {
                use GetBlockToEditTrait;
            };
            $area = (string) $row['arHandle'];
            $block = $editor->getBlockToEdit($page, Area::get($page, $area), $area, (int) $row['bID']);
            $block->update(['content' => str_replace($edit['find'], $edit['replace'], (string) $row['content'])]);
            CacheLocal::flush();

            return;
        }

        throw new \RuntimeException("hand_edit: no Content block on {$edit['page']} contains \"{$edit['find']}\".");
    }

    /**
     * Deletes the people apply() created. Never throws, so it can run in a `finally`.
     *
     * @param list<int> $ids
     */
    public function undo(array $ids): void
    {
        $class = self::PERSON_CLASS;
        foreach ($ids as $id) {
            try {
                $class::deleteByID($id);
            } catch (\Throwable) {
                // The next fixture reset removes anything left, by name and email.
            }
        }
    }
}
