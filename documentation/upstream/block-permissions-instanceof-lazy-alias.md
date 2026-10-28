# Concrete CMS issue: block permissions (and some type checks) depend on whether the `Page` alias is loaded

Status: **ready to file, not filed.** The issue and pull request to Concrete CMS are raised outside this repository (Glyn, 28 October 2026).
Found by: Neuron AI's BF-19 investigation. A user's block edit was refused in one run and allowed in another, with the same data.

## Summary
Some core files import the global class alias `Page` (`use Page;`) and then test against it:
- with `instanceof Page`, or
- with a parameter type `Page $c`.

Concrete registers its aliases lazily, through the autoloader, the first time a name is used. PHP never autoloads for `instanceof` or for a parameter type check. So until something in the request has used the name `Page` (any `\Page::…` call, `class_exists('Page')`, …), these checks treat a real `Concrete\Core\Page\Page` object as not a page.

The most serious effect is on block permissions. In `BlockAssignment`, every block in an area that inherits the page's permissions is refused **View Block** and **Edit Block** for every user except the super user, even though the same user passes **View Page** and **Edit Page Contents**. The result depends on what ran earlier in the request, so it's intermittent and hard to trace.

## Environment
- Concrete CMS 9.5.5 (`concrete/src` unchanged since its release)
- PHP 8.4, MySQL
- Permission model "simple" (the advanced model wasn't tried)

## Steps to reproduce
1. **Prepare.** You need:
   - a non-super user who may view and edit a page
   - on that page, a block in an area that **doesn't** override the page's permissions (the default for a new page)
2. **Save the script below** as `repro_lazy_page_alias.php` in the site root.
3. **Run it from the site root:**
   ```
   php concrete/bin/concrete c5:exec repro_lazy_page_alias.php -- <username> <page path>
   ```

```php
<?php
use Concrete\Core\Block\Block;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page as CorePage;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfoRepository;

[$username, $path] = [$args[0] ?? 'editor', $args[1] ?? '/'];
$app = Application::getFacadeApplication();
$db = $app->make('database')->connection();

$user = $app->make(UserInfoRepository::class)->getByUserName($username);
if (!$user) {
    exit("No user {$username}\n");
}
Request::getInstance()->setCustomRequestUser($user);

$page = CorePage::getByPath($path, 'RECENT');
$row = $db->fetchAssociative(
    'SELECT cvb.bID, cvb.arHandle FROM CollectionVersionBlocks cvb'
    . ' LEFT JOIN Areas a ON a.cID = cvb.cID AND a.arHandle = cvb.arHandle'
    . ' WHERE cvb.cID = ? AND cvb.cvID = ? AND COALESCE(a.arOverrideCollectionPermissions, 0) = 0 AND cvb.cbOverrideAreaPermissions = 0'
    . ' ORDER BY cvb.cbDisplayOrder LIMIT 1',
    [$page->getCollectionID(), $page->getVersionID()]
);
if (!$row) {
    exit("No block in a page-inherited area on {$path}\n");
}

$check = static function (string $when) use ($app, $page, $row): void {
    $app->make('cache/request')->flush();
    $block = Block::getByID((int) $row['bID'], $page, (string) $row['arHandle']);
    printf(
        "%-40s alias loaded: %-5s  canViewPage: %-5s  canEditPageContents: %-5s  canViewBlock: %-5s  canEditBlock: %s\n",
        $when,
        var_export(class_exists('Page', false), true),
        var_export((bool) (new Checker($page))->canViewPage(), true),
        var_export((bool) (new Checker($page))->canEditPageContents(), true),
        var_export((bool) (new Checker($block))->canViewBlock(), true),
        var_export((bool) (new Checker($block))->canEditBlock(), true)
    );
};

$check('Before anything uses the name Page:');
class_exists('Page'); // what any \Page::... call elsewhere in the request would do
$check('After class_exists(\'Page\'):');
```

**What we got** (Concrete 9.5.5, an editor with edit rights on the page):

```
Before anything uses the name Page:      alias loaded: false  canViewPage: true   canEditPageContents: true   canViewBlock: false  canEditBlock: false
After class_exists('Page'):              alias loaded: true   canViewPage: true   canEditPageContents: true   canViewBlock: true   canEditBlock: true
```

The same object, the same user and the same data give a different answer once the name `Page` has been used.

**The type check fails the same way.** In a fresh `c5:exec` script:

```php
$page = \Concrete\Core\Page\Page::getByPath('/some-page');
\Concrete\Core\Area\Area::getListOnPage($page);
```

```
TypeError: Concrete\Core\Area\Area::getListOnPage(): Argument #1 ($c) must be of type Page, Concrete\Core\Page\Page given
```

## Expected
The same answer whether or not the alias has been loaded:
- `canViewBlock` and `canEditBlock` follow `canViewPage` and `canEditPageContents` for a block in a page-inherited area;
- `Area::getListOnPage()` accepts a `Concrete\Core\Page\Page`.

## Cause
`concrete/src/Permission/Assignment/BlockAssignment.php`:

```php
use Page;   // line 12: the global class alias
...
public function getPermissionAccessObject()
{
    ...
    } elseif ($this->permissionObjectToCheck instanceof Page && $registry->getEntry('page', $this->pk->getPermissionKeyHandle())) {   // line 88
```

For a block whose area doesn't override permissions, `setPermissionObject()` sets `permissionObjectToCheck` to the page. With the alias not loaded, `instanceof Page` is false. The page branch is skipped, `getPermissionAccessObject()` returns `null`, and every block key is refused, except for the super user, who bypasses the check.

## Affected places found
Every file below has `use Page;` (the alias), and the line tests against it:

| File | Line | Check |
|---|---|---|
| `src/Permission/Assignment/BlockAssignment.php` | 88 | `instanceof Page`: **block permissions** (above) |
| `src/Area/Area.php` | 566 | `getListOnPage(Page $c)`: **TypeError** (above) |
| `src/Page/Collection/Collection.php` | 391 | `$this instanceof Page` |
| `src/Entity/File/Version.php` | 1037, 1055 | `$c instanceof Page` |
| `src/Application/Application.php` | 122 | `$c instanceof Page` |

Other files that import the alias weren't checked line by line:
- `src/Area/GlobalArea.php`
- `src/Block/BlockController.php`
- `src/Html/Service/Navigation.php`
- `src/Routing/Redirect.php`
- `src/Workflow/Progress/PageProgress.php`
- `src/Application/Service/UserInterface.php`
- `src/Page/Type/PublishTarget/Configuration/ParentPageConfiguration.php`

The other permission classes that test `instanceof Page` (`AddBlockBlockTypeAccess`, `AddBlockToAreaAreaAccess`, `AreaAssignment`, `PageOwnerEntity`) import the real class, `Concrete\Core\Page\Page`, and are not affected.

**A related slip to check:** `src/Permission/Access/ViewUserAttributesUserAccess.php` line 72 tests `instanceof Page` with no `Page` import at all. In namespace `Concrete\Core\Permission\Access`, that name means `Concrete\Core\Permission\Access\Page`, which doesn't exist, so the test is always false.

## Proposed fix
In each affected file, import the class instead of the alias:

```diff
-use Page;
+use Concrete\Core\Page\Page;
```

A wider option is to register the aliases eagerly at boot, so the alias always exists. The narrow fix is safer: it changes only how each file names the class.

**Suggested regression test:** run the check in a fresh PHP process, because a test runner has usually loaded the alias already. For example, run the script above with `c5:exec` and assert that `canEditBlock` is true before `class_exists('Page')`.

## Impact
- **Who is refused:** editors (anyone but the super user) can be refused View or Edit Block on ordinary blocks.
- **When:** in any code path where nothing has used the name `Page` before the check. We hit it from a CLI process; AJAX or API routes that check a block early may hit it too.
- **Not checked:** how often an ordinary page view loads the alias before a block check.
- **It fails closed**, refusing access rather than granting it, so it's not a security hole. But the result depends on what ran before, which makes it hard to diagnose.

## Our workaround
Neuron AI loads the class aliases imported by `concrete/src/Permission` before its own permission checks (`src/Access/CoreAliases.php`; BF-19, PR #88).
