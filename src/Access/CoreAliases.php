<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

/**
 * BF-19: loads the global class aliases Concrete's permission classes type-check against.
 *
 * Concrete registers its aliases (`Page`, `PermissionKey`, …) lazily, through the autoloader, the first time a name is
 * used. Some core permission code imports an alias and tests `instanceof Page`. `instanceof` never autoloads, so in a
 * PHP process where nothing has referenced `\Page` yet, the test is false. In Concrete 9.5.5, BlockAssignment then
 * finds no access for a block in an area that inherits the page's permissions, and View Block and Edit Block refuse
 * everyone but the super user, though the page's own checks pass. Whether it happened depended on what ran earlier in
 * the request (a page read loads the alias): P3.S2's scenario 06 failed after 04, and 23 passed later in the same run.
 * See documentation/upstream/ for the Concrete issue.
 *
 * The list is the class aliases (not facades or PHP classes) imported by concrete/src/Permission in 9.5.5.
 */
final class CoreAliases
{
    private const ALIASES = ['Page', 'PermissionKey', 'PermissionKeyCategory', 'Permissions', 'TaskPermission', 'UserInfo', 'Group', 'FileSet'];

    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        foreach (self::ALIASES as $alias) {
            class_exists($alias);
        }
        self::$loaded = true;
    }
}
