<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi;

use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Log;

/**
 * Which page the user is on, from what the chat panel sends (BF-1, plans/BF-1.md 3.1).
 *
 * The panel sends the page ID (Concrete's CCM_CID), the browser path, the title and the URL. Everything in it is
 * untrusted: a candidate page is used only if it exists and the current user can view it. A page the user can't view
 * is treated as unresolved and logged, and the next candidate is tried. The candidates, in order: the panel's ID, a
 * cID in the panel's URL (edit mode and index.php?cID= URLs), then the path (the panel's, its URL's, or the referrer).
 *
 * Paths are normalised before the lookup: a leading /index.php segment is stripped (dashboard URLs keep it), and "/"
 * is the current site's home page, whose stored path is empty, so a path lookup would never find it.
 */
final class PageContextResolver
{
    /** @var \Closure(string, string): void */
    private \Closure $logger;

    /**
     * @param callable(string, string): void|null $logger called as ($level, $message); defaults to the Concrete log
     */
    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger !== null
            ? \Closure::fromCallable($logger)
            : static function (string $level, string $message): void {
                Log::$level($message);
            };
    }

    /**
     * @param array<string, mixed> $payload the panel's pageContext: id, path, title, url
     * @param string|null $referrerPath the path of the request's same-site referrer, used when the panel sends no path
     * @return array{id: ?int, path: ?string, url: ?string, title: ?string} id and path are null when no page resolved
     */
    public function fromBrowser(array $payload, ?string $referrerPath = null): array
    {
        $url = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : null;
        $title = isset($payload['title']) && is_string($payload['title']) ? trim($payload['title']) : null;

        $page = null;
        foreach ([$payload['id'] ?? null, self::cidFromUrl($url)] as $id) {
            if ($page === null && is_numeric($id) && (int) $id > 0) {
                $page = $this->viewable(Page::getByID((int) $id, 'ACTIVE'), 'ID ' . (int) $id);
            }
        }

        if ($page === null) {
            $path = null;
            if (!empty($payload['path']) && is_string($payload['path'])) {
                $path = parse_url($payload['path'], PHP_URL_PATH) ?: $payload['path'];
            } elseif ($url !== null) {
                $path = parse_url($url, PHP_URL_PATH) ?: null;
            } else {
                $path = $referrerPath;
            }
            $found = self::pageForPath(is_string($path) ? $path : null);
            if ($found !== null) {
                $page = $this->viewable($found, 'path ' . self::normalizePath($path));
            }
        }

        return [
            'id' => $page !== null ? (int) $page->getCollectionID() : null,
            'path' => $page !== null ? self::pathOf($page) : null,
            'url' => $url,
            'title' => $title,
        ];
    }

    /**
     * The page at a browser path, or null. No permission check: callers check what they do with it.
     */
    public static function pageForPath(?string $path): ?Page
    {
        $path = self::normalizePath($path);
        if ($path === null) {
            return null;
        }

        if ($path === '/') {
            $homeID = (int) Application::getFacadeApplication()->make('site')->getSite()->getSiteHomePageID();
            $page = $homeID > 0 ? Page::getByID($homeID, 'ACTIVE') : null;
        } else {
            $page = Page::getByPath($path, 'ACTIVE');
        }

        return $page instanceof Page && !$page->isError() ? $page : null;
    }

    /**
     * A browser path as Concrete stores page paths: one leading slash, no repeated or trailing slashes, no query or
     * fragment, and no leading /index.php segment (only as the first segment: /foo/index.php is left alone).
     */
    public static function normalizePath(?string $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = explode('#', explode('?', trim($path), 2)[0], 2)[0];
        if ($path === '') {
            return null;
        }

        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
        $path = (string) preg_replace('#^/index\.php(?=/|$)#', '', $path);

        return $path === '' ? '/' : $path;
    }

    /**
     * The path of a same-site referrer, or null.
     */
    public static function referrerPath(?string $referrer, ?string $host): ?string
    {
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }

        $refHost = parse_url($referrer, PHP_URL_HOST);
        if ($host && $refHost && !hash_equals((string) $host, (string) $refHost)) {
            return null;
        }

        $path = parse_url($referrer, PHP_URL_PATH);

        return is_string($path) ? $path : null;
    }

    private static function cidFromUrl(?string $url): ?int
    {
        if ($url === null) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['cID']) && is_numeric($query['cID']) ? (int) $query['cID'] : null;
    }

    private static function pathOf(Page $page): string
    {
        $path = (string) $page->getCollectionPath();

        return $path === '' ? '/' : $path;
    }

    /**
     * The page if it exists and the current user can view it; otherwise null, logged when it exists.
     */
    private function viewable(mixed $page, string $what): ?Page
    {
        if (!$page instanceof Page || $page->isError()) {
            return null;
        }

        if (!(new Checker($page))->canViewPage()) {
            ($this->logger)('warning', sprintf(
                'Katalysis Neuron AI: page context from the browser (%s, page %d) is not viewable by user %s; ignored.',
                $what,
                (int) $page->getCollectionID(),
                (string) ((new \Concrete\Core\User\User())->getUserID() ?: 'guest')
            ));

            return null;
        }

        return $page;
    }
}
