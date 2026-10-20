# Draft issue for Concrete CMS: a user made by `RegistrationService::create()` cannot be deleted in the same process (`FK_FEB5D909FD71026C`)

Status: **draft, not filed.** Glyn decides whether to file it (P1.12, 20 October 2026).
Found by: the Neuron AI test hygiene work (documentation/plans/P1.12.md, section 1.4). This corrects an earlier theory (P1.10a) that the group steps caused it.

## Version
Concrete CMS 9.5.5 (`concrete/config/concrete.php`, `'version' => '9.5.5'`), MySQL, PHP 8.4.

## Where
`concrete/src/User/RegistrationService.php`, `RegistrationService::create()`, line 160:

```php
$signup = new UserSignup($ui->getEntityObject(), $createdBy);
```

`UserSignup` is the owning side of a one-to-one with the user (`concrete/src/Entity/User/UserSignup.php`, `inversedBy="signup"`). The user entity's side is `concrete/src/Entity/User/User.php` line 35:

```php
@ORM\OneToOne(targetEntity="\Concrete\Core\Entity\User\UserSignup", mappedBy="user", cascade={"remove"})
protected $signup;
```

The `User` entity has no setter or getter for `$signup`, and `create()` never sets it, so the in-memory user has `signup === null` for the rest of the request. `UserInfo::delete()` (`concrete/src/User/UserInfo.php`, line 220) relies on `cascade={"remove"}` to delete the sign-up row. In the same process Doctrine sees no sign-up to remove, so `DELETE FROM Users` runs while a `UserSignups` row still points at the user.

## What happens
```
Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException: An exception occurred while executing 'DELETE FROM Users WHERE uID = ?' with params [1142]:
SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row: a foreign key constraint fails
(`prodb`.`usersignups`, CONSTRAINT `FK_FEB5D909FD71026C` FOREIGN KEY (`uID`) REFERENCES `Users` (`uID`))
```

## Steps to reproduce
```php
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\RegistrationServiceInterface;

$app = Application::getFacadeApplication();
$ui = $app->make(RegistrationServiceInterface::class)->create([
    'uName' => 'signup_probe', 'uEmail' => 'signup_probe@example.invalid', 'uPassword' => 'Probe#Password1',
]);
$ui->delete();   // the foreign key error above
```

Variants tried (each in a transaction that was rolled back, so nothing was kept):

| Variant | Result |
|---|---|
| create, delete | foreign key error |
| create, join a group, delete | works (joining a group loads `signup` through the entity manager) |
| create, clear the entity manager, reload the user, delete | works |
| create, clear the entity manager, delete the stale object | "Detached entity ... cannot be removed" |
| create, `$em->refresh($ui->getEntityObject())`, delete | works |

## Evidence
- In the working variants `UserInfo::delete()` removes the sign-up row, its `UserSignupNotifications` row, the parent `Notifications` row and its `NotificationAlerts`: the row counts of all those tables are identical before the user is created and after it is deleted.
- Working around it in SQL (deleting `UserSignupNotifications` and `UserSignups` by hand before `delete()`) leaves the parent `Notifications` and `NotificationAlerts` rows: our test suite did that from 19 October to 20 October and left 48 dangling `usersignupnotification` rows with 96 alerts (two per run).

## Suggested fix
Any of:
- set the inverse side when the sign-up is created (add `User::setSignup()` and call it in `create()`), or
- in `UserInfo::delete()`, refresh the entity before the removal (`$em->refresh($this->getEntityObject())`) so `cascade` sees the persisted state, or
- make `UserSignup` removal explicit in `UserInfo::delete()`, with its notifications.

## Impact
Any code (tests, importers, scripts) that creates a user with `RegistrationService::create()` and deletes it in the same request fails. In web requests users are normally deleted in a later request, which is why it goes unseen.
