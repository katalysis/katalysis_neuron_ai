# Draft issue for Concrete CMS: block permissions in page-inherited areas depend on whether the `Page` alias is loaded

Status: **draft, not filed.** Glyn decides whether to file it (BF-19, 28 October 2026).
Found by: the Neuron AI BF-19 investigation of P3.S2's scenario 06, where a fixture user's block edit was refused in one run and allowed in another, with the same data.

## Version
Concrete CMS 9.5.5, MySQL, PHP 8.4. The permission model is "simple".

## Where
`concrete/src/Permission/Assignment/BlockAssignment.php`, `getPermissionAccessObject()`:

```php
use Page;   // the global class alias
...
} elseif ($this->permissionObjectToCheck instanceof Page && $registry->getEntry('page', ...)) {
```

Concrete registers its class aliases (`Page`, `PermissionKey`, …) lazily, through the autoloader. `instanceof` never triggers autoloading. So until something in the PHP process has referenced the name `Page` (a static call, `class_exists('Page')`, …), the alias doesn't exist and the test is false, even for a `Concrete\Core\Page\Page` object.

The same pattern (`use Page;` with `instanceof Page`) is in:
- `Permission/Access/AddBlockBlockTypeAccess.php`
- `Permission/Access/ViewUserAttributesUserAccess.php`
- `Permission/Access/AddBlockToAreaAreaAccess.php`
- `Permission/Access/Entity/PageOwnerEntity.php`

## What happens
For a block whose area doesn't override permissions, `BlockAssignment::setPermissionObject()` checks against the page. `getPermissionAccessObject()` then skips the page branch, because `instanceof Page` is false, and returns null. View Block, Edit Block and the other block keys are refused for every user except the super user, though the user's View Page and Edit Page Contents pass. Any earlier use of the name `Page` in the same process (any `\Page::` call, for example) hides the problem, so the result depends on what ran before. That is how we found it: the same check passed or failed depending on the order of our tests. Not checked: how often an ordinary page request loads the alias before a block check.

## Steps to reproduce
A sketch, adapted from Neuron AI's `tests/Support/fresh_process_block_check.php` (which is what we ran). Run it as a script (`php concrete/bin/concrete c5:exec repro.php`), on a page whose Main area inherits the page's permissions, for a non-super user who may edit the page:

```php
use Concrete\Core\Block\Block;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page as CorePage;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfoRepository;

$app = Application::getFacadeApplication();
Request::getInstance()->setCustomRequestUser($app->make(UserInfoRepository::class)->getByUserName('editor'));
$page = CorePage::getByPath('/some-page', 'RECENT');
$block = $page->getBlocks('Main')[0];

var_dump((bool) (new Checker($page))->canEditPageContents()); // true
var_dump((bool) (new Checker($block))->canEditBlock());       // false
class_exists('Page');
$app->make('cache/request')->flush();
var_dump((bool) (new Checker(Block::getByID($block->getBlockID(), $page, 'Main')))->canEditBlock()); // true
```

## Expected
The same answer whether or not the alias has been loaded. Importing the class (`use Concrete\Core\Page\Page;`) in place of the alias in these files would fix it.

## Workaround used
Neuron AI loads the aliases before its permission checks (`src/Access/CoreAliases.php`).
