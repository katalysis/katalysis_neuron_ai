<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Access;

use Concrete\Core\Entity\Package;
use Concrete\Core\Permission\Access\Access;
use Concrete\Core\Permission\Access\Entity\GroupEntity;
use Concrete\Core\Permission\Key\Key;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Extension\Tier;

/**
 * Neuron's task permission keys. They live in Concrete's `admin` category, which is the only one
 * that Dashboard > System & Settings > Permissions & Access > Tasks lists and saves, and carry this
 * package's ID, so uninstalling the package removes them.
 *
 * A task permission is true for the super user and for anyone in the key's access list. A key with no
 * access list is therefore true for the super user only.
 *
 * The tiers (P3.2-1, BEHAVIOUR_SPEC B-ACC-9) are keys too: `use_neuron_ai` is Tier 1 (Content), and the two
 * `neuron_ai_tier_*` keys are Tiers 2 and 3. They are not the settings key `configure_neuron_ai`.
 */
final class NeuronPermissions
{
    public const USE_HANDLE = 'use_neuron_ai';
    public const CONFIGURE_HANDLE = 'configure_neuron_ai';
    public const TIER_CONFIGURE_HANDLE = 'neuron_ai_tier_configure';
    public const TIER_GENERATE_HANDLE = 'neuron_ai_tier_generate';

    /**
     * Adds whichever of the keys is missing, and sets its default holders only when it is added,
     * so running this again (every package upgrade does) never changes what an admin has chosen.
     *
     * Defaults: Administrators can use the assistant and are Tier 2 (they had the Tier 2 tools through the use key
     * before P3.2-1); nobody but the super user can configure it or is Tier 3 (Glyn, on approving P3.2-1's plan).
     *
     * @param Package $pkg the installed package entity (what Package::getByHandle() and the package controller's install() give)
     *
     * @return string[] the handles of the keys that were added
     */
    public static function install(Package $pkg): array
    {
        $keys = [
            self::USE_HANDLE => ['Use Neuron AI assistant', 'Can open the Neuron AI chat panel and use the assistant (Tier 1: Content).', true],
            self::CONFIGURE_HANDLE => ['Configure Neuron AI', 'Can change the Neuron AI API keys and settings.', false],
            self::TIER_CONFIGURE_HANDLE => [
                'Neuron AI tier 2: Configure',
                'The assistant may also define the site\'s structure: attributes, attribute sets and topic trees. Includes Tier 1.',
                true,
            ],
            self::TIER_GENERATE_HANDLE => [
                'Neuron AI tier 3: Generate',
                'The assistant may also use Tier 3 tools, and other packages\' write tools that declare no tier. Includes Tiers 1 and 2.',
                false,
            ],
        ];

        $added = [];
        foreach ($keys as $handle => [$name, $description, $administrators]) {
            if (Key::getByHandle($handle) instanceof Key) {
                continue;
            }
            $key = Key::add('admin', $handle, $name, $description, false, false, $pkg);
            $adminGroup = $administrators ? Group::getByID(ADMIN_GROUP_ID) : null;
            if ($adminGroup) {
                $access = $key->getPermissionAccessObject();
                if (!is_object($access)) {
                    $access = Access::create($key);
                }
                $access->addListItem(GroupEntity::getOrCreate($adminGroup));
                $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
            }
            $added[] = $handle;
        }

        return $added;
    }

    /**
     * The user's tier (P3.2-1, B-ACC-9): 3 for the super user and the Generate key, 2 for the Configure key, 1 for the
     * use key, otherwise 0. Cumulative: a higher key also passes a lower tier's check. Guests are always 0, and a key
     * that isn't installed yet counts as not held (userHas()).
     */
    public static function tier(): int
    {
        $user = Application::getFacadeApplication()->make(User::class);
        if (!$user->isRegistered()) {
            return 0;
        }
        if ($user->isSuperUser() || self::userHas(self::TIER_GENERATE_HANDLE)) {
            return Tier::GENERATE->value;
        }
        if (self::userHas(self::TIER_CONFIGURE_HANDLE)) {
            return Tier::CONFIGURE->value;
        }

        return self::userHas(self::USE_HANDLE) ? Tier::CONTENT->value : 0;
    }

    /**
     * Registered users only: a key can be granted to the Guest group in the Tasks dialog, and
     * guests never get the assistant (deliberate, BEHAVIOUR_SPEC B-ACC-1). If the key doesn't exist
     * yet (the package files are newer than the installed package: run c5:package:update), only the
     * super user gets in.
     */
    public static function userHas(string $handle): bool
    {
        $user = Application::getFacadeApplication()->make(User::class);
        if (!$user->isRegistered()) {
            return false;
        }

        $key = Key::getByHandle($handle);
        if (!$key instanceof Key) {
            return $user->isSuperUser();
        }

        return (bool) $key->validate();
    }
}
