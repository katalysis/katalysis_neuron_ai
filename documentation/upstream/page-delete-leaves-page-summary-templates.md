# Draft issue for Concrete CMS: `Page::delete()` leaves `PageSummaryTemplates` rows behind

Status: **draft, not filed.** Glyn decides whether to file it (BF-2, 21 October 2026).
Found by: the Neuron AI BF-2 investigation (documentation/plans/BF-2.md §12), when a throwaway-page check left rows behind.

## Version
Concrete CMS 9.5.5, MySQL, PHP 8.4.

## Where
`concrete/src/Page/Page.php`, `Page::delete()`. Approving a page version (`Version::approve()`) refreshes the page's summary templates, which writes rows to `PageSummaryTemplates` (the Doctrine entity `Concrete\Core\Entity\Page\Summary\PageTemplate`, keyed by `cID`). `Page::delete()` removes the page's other rows but not these.

## What happens
After deleting a page whose version was approved at least once after it was created, its `PageSummaryTemplates` rows remain, pointing at a `cID` that no longer exists in `Pages` or `Collections`.

## Steps to reproduce
As a script (`php concrete/bin/concrete c5:exec repro.php`), as a user who can add pages:

```php
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Support\Facade\Application;

$db = Application::getFacadeApplication()->make('database')->connection();
$count = fn (int $cID) => (int) $db->fetchOne('SELECT COUNT(*) FROM PageSummaryTemplates WHERE cID = ?', [$cID]);

$t = PageTemplate::getByHandle('full');
$page = Page::getByPath('/some-parent')->add(PageType::getByHandle('page'), ['cName' => 'Repro', 'pTemplateID' => $t->getPageTemplateID()], $t);
$cID = (int) $page->getCollectionID();
echo 'after add: ' . $count($cID) . PHP_EOL;                              // 0

Page::getByID($cID, 'RECENT')->getVersionToModify()->getVersionObject()->approve();
echo 'after approving a second version: ' . $count($cID) . PHP_EOL;       // 1

Page::getByID($cID)->delete();
echo 'after Page::delete(): ' . $count($cID) . PHP_EOL;                   // still 1; no row for the cID in Pages
```

Checked on 9.5.5: 0, 1, 1.

## Expected
`Page::delete()` removes the page's `PageSummaryTemplates` rows, as it does for the page's other per-page rows.

## Suggested fix
In `Page::delete()`, delete the page's summary-template entities (or `DELETE FROM PageSummaryTemplates WHERE cID = ?`) alongside the other per-page cleanup.

## A lesser point, probably not a bug
Deleting a **stack's** page with `Page::delete()` leaves its `Stacks` row (and, for a global area, the `Areas` rows with `arIsGlobal = 1` on the pages that show it). `Stack::delete()` removes both, so callers should use it. Found because a throwaway check used `Page::delete()` on stacks. It may be worth a guard or a note in `Page::delete()`, since the generic page API doesn't stop it.
