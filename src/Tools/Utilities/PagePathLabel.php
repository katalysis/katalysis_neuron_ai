<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Support\Facade\Application;

/**
 * A page's real path for the block lists, never a guessed one (P3.3-1, BF-12). They read the path from PagePaths and,
 * when it had no row, made one up from the version's handle or name ("/{pageHandle}"). The home page has no row: its
 * path is "/". Any other page without one has no path, and is named by its ID.
 */
final class PagePathLabel
{
    /**
     * The stored canonical path, "/" for the home page, or null.
     */
    public static function of(int $cID, ?string $storedPath): ?string
    {
        if ($storedPath !== null && $storedPath !== '') {
            return $storedPath;
        }

        $homeID = (int) Application::getFacadeApplication()->make('site')->getSite()->getSiteHomePageID();

        return $cID === $homeID ? '/' : null;
    }

    /**
     * The path, or "page ID n (no path)".
     */
    public static function describe(int $cID, ?string $storedPath): string
    {
        return self::of($cID, $storedPath) ?? "page ID {$cID} (no path)";
    }
}
