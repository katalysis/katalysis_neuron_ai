# Draft issue for Concrete CMS: `UserInfo::delete()` leaves the user's `PermissionAccessEntities` row behind

Status: **draft, not filed.** Glyn decides whether to file it (P1.12, 20 October 2026).
Found by: the Neuron AI test hygiene work (documentation/plans/P1.12.md, section 1.2).

## Version
Concrete CMS 9.5.5 (`concrete/config/concrete.php`, `'version' => '9.5.5'`), MySQL, PHP 8.4.

## Where
`concrete/src/User/UserInfo.php`, `UserInfo::delete()` (line 220). Line 252:

```php
$this->connection->executeQuery('DELETE FROM PermissionAccessEntityUsers WHERE uID = ?', [(int)$this->getUserID()]);
```

removes the link between the user and its permission access entity. The entity itself, the `PermissionAccessEntities` row (type `user`) that `UserEntity::getOrCreate($userInfo)` made, is not deleted, and neither are the `PermissionAccessList` rows that use it.

## What happens
A user that was added to any permission access list (for example by `UserEntity::getOrCreate($user)` when somebody grants one user a permission, in the dashboard or in code) has a row in `PermissionAccessEntities` and one in `PermissionAccessEntityUsers`. After `UserInfo::delete()` the first stays with no user: an entity of type `user` with no `PermissionAccessEntityUsers` row, and the access lists that name it keep naming it.

## Steps to reproduce
```php
use Concrete\Core\Permission\Access\Entity\UserEntity;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\RegistrationServiceInterface;

$app = Application::getFacadeApplication();
$db = $app->make('database')->connection();
$ui = $app->make(RegistrationServiceInterface::class)->create([
    'uName' => 'entity_probe', 'uEmail' => 'entity_probe@example.invalid', 'uPassword' => 'Probe#Password1',
]);
$entity = UserEntity::getOrCreate($ui);
$peID = (int) $entity->getAccessEntityID();

// Delete the user. In the same process the delete needs the sign-up row to be loaded first (see the third draft),
// so refresh the entity before it.
$app->make(\Doctrine\ORM\EntityManagerInterface::class)->refresh($ui->getEntityObject());
$ui->delete();

echo $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessEntities WHERE peID = ?', [$peID]);       // 1 (checked on 9.5.5)
echo $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessEntityUsers WHERE peID = ?', [$peID]);    // 0
```

## Evidence
From the dev database of a site that runs an integration test suite which creates and deletes users:

- 16 `PermissionAccessEntities` rows of type `user` with no `PermissionAccessEntityUsers` row (peIDs 370 to 914). All were referenced only by `PermissionAccessList` rows of accesses that were themselves leftovers; the live users' entities were not affected.
- A user that is deleted cleanly leaves no other row behind: counts of `Users`, `UserGroups`, `UserSignups`, `UserSignupNotifications`, `Notifications`, `NotificationAlerts`, `UserSearchIndexAttributes` and `UserAttributeValues` were identical before the user was created and after it was deleted.

## Suggested fix
In `UserInfo::delete()`, after the `PermissionAccessEntityUsers` delete, remove the entity and its list rows:

```php
$peIDs = $this->connection->fetchFirstColumn('SELECT peID FROM PermissionAccessEntityUsers WHERE uID = ?', [(int) $this->getUserID()]);
foreach ($peIDs as $peID) {
    $this->connection->executeStatement('DELETE FROM PermissionAccessList WHERE peID = ?', [$peID]);
    $this->connection->executeStatement('DELETE FROM PermissionAccessEntities WHERE peID = ?', [$peID]);
}
// ...then the existing DELETE FROM PermissionAccessEntityUsers
```

(the entity types with their own tables, such as the child tables of `AreaPermissionBlockTypeAccessList`, would need the same treatment; `PermissionAccessEntity::delete()` may already do it).

## Impact
Low: a dangling grant to a user id that no longer exists. But the rows are never cleaned up, and a later user cannot inherit them (user ids are not reused).
