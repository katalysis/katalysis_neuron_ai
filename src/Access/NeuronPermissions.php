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

/**
 * Neuron's two task permission keys. They live in Concrete's `admin` category, which is the only one
 * that Dashboard > System & Settings > Permissions & Access > Tasks lists and saves, and carry this
 * package's ID, so uninstalling the package removes them.
 *
 * A task permission is true for the super user and for anyone in the key's access list. A key with no
 * access list is therefore true for the super user only.
 */
final class NeuronPermissions
{
    public const USE_HANDLE = 'use_neuron_ai';
    public const CONFIGURE_HANDLE = 'configure_neuron_ai';

    /**
     * Adds whichever of the two keys is missing, and sets its default holders only when it is added,
     * so running this again (every package upgrade does) never changes what an admin has chosen.
     *
     * Defaults: Administrators can use the assistant; nobody but the super user can configure it.
     *
     * @param Package $pkg the installed package entity (what Package::getByHandle() and the package controller's install() give)
     *
     * @return string[] the handles of the keys that were added
     */
    public static function install(Package $pkg): array
    {
        $added = [];

        if (!Key::getByHandle(self::USE_HANDLE) instanceof Key) {
            $key = Key::add(
                'admin',
                self::USE_HANDLE,
                'Use Neuron AI assistant',
                'Can open the Neuron AI chat panel and use the assistant.',
                false,
                false,
                $pkg
            );
            $adminGroup = Group::getByID(ADMIN_GROUP_ID);
            if ($adminGroup) {
                $access = $key->getPermissionAccessObject();
                if (!is_object($access)) {
                    $access = Access::create($key);
                }
                $access->addListItem(GroupEntity::getOrCreate($adminGroup));
                $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
            }
            $added[] = self::USE_HANDLE;
        }

        if (!Key::getByHandle(self::CONFIGURE_HANDLE) instanceof Key) {
            Key::add(
                'admin',
                self::CONFIGURE_HANDLE,
                'Configure Neuron AI',
                'Can change the Neuron AI API keys and settings.',
                false,
                false,
                $pkg
            );
            $added[] = self::CONFIGURE_HANDLE;
        }

        return $added;
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
