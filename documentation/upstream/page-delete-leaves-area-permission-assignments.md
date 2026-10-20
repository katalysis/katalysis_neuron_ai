# Draft issue for Concrete CMS: `Page::delete()` leaves `AreaPermissionAssignments` rows behind

Status: **draft, not filed.** Glyn decides whether to file it (P1.12, 20 October 2026).
Found by: the Neuron AI test hygiene work (documentation/plans/P1.12.md, section 1.1).

## Version
Concrete CMS 9.5.5 (`concrete/config/concrete.php`, `'version' => '9.5.5'`), MySQL, PHP 8.4.

## Where
`concrete/src/Page/Page.php`, `Page::delete()` (line 3064). It removes the page's per-page permission assignments (line 3113):

```php
$db->executeQuery('delete from PagePermissionAssignments where cID = ?', [$cID]);
```

and its areas (line 3123):

```php
$db->executeQuery('delete from Areas WHERE cID = ?', [$cID]);
```

but nothing in the method deletes from `AreaPermissionAssignments`, which is keyed by `cID` too (primary key `cID, arHandle, pkID, paID`, no foreign key).

## What happens
When an area on a page has its own permissions (`Area::overridePagePermissions()`), the area gets one `AreaPermissionAssignments` row per area permission key (nine in this install) and, for each, a duplicated `PermissionAccess` with its `PermissionAccessList` rows. Deleting the page removes the `Areas` row and the page, and leaves all of those behind, pointing at a `cID` that no longer exists:

- `AreaPermissionAssignments` rows for a page that is not in `Collections`;
- the `PermissionAccess` rows they use (and their `PermissionAccessList` rows), which nothing else refers to and which no UI can reach.

## Steps to reproduce
Any page with an area override. As a script (`php concrete/bin/concrete c5:exec repro.php`):

```php
use Concrete\Core\Area\Area;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;

$db = Application::getFacadeApplication()->make('database')->connection();
$page = Page::getByPath('/some-page');              // any existing page
$cID = (int) $page->getCollectionID();
Area::getOrCreate($page, 'Main')->overridePagePermissions();

$count = fn () => (int) $db->fetchOne('SELECT COUNT(*) FROM AreaPermissionAssignments WHERE cID = ?', [$cID]);
echo 'before delete: ' . $count() . PHP_EOL;          // 9 (checked on 9.5.5)
$page->delete();
echo 'after delete: ' . $count() . PHP_EOL;           // still 9, and no row for cID in Collections
```

## Evidence
From the dev database of a site that runs an integration test suite which makes and deletes throwaway pages:

- 369 `AreaPermissionAssignments` rows for 41 pages that no longer exist (every page exactly 9 rows: area `Main`, the nine area permission keys once each), left by test runs between 19 and 20 October.
- 63 `PermissionAccess` rows and 136 `PermissionAccessList` rows that only those rows used. Five other accesses were shared with live content and are not affected.
- `Areas`, `Pages`, `PagePermissionAssignments`, `Collections`, `CollectionVersions` and `PagePaths` had no rows for those pages: only `AreaPermissionAssignments` (and the accesses it refers to) was left.

## Suggested fix
In `Page::delete()`, next to the `PagePermissionAssignments` delete:

```php
$db->executeQuery('delete from AreaPermissionAssignments where cID = ?', [$cID]);
```

Accesses that were duplicated for the area (`PermissionAccess` rows used only by the deleted rows) would still need removing. Calling the area's own revert-to-page-permissions step for each area of the page before deleting it may be a cleaner option (not tried).

## Impact
Low for security (the rows refer to a `cID` that does not exist), but the tables grow for ever on sites that create and delete pages with area overrides, and `cID`s are not reused, so nothing ever cleans them up.
