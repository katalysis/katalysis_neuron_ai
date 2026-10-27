<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\File\File;

/**
 * Absolute URLs for files a tool result points at (BF-12; B-TRU-9), so the model never adds a host to a path or an
 * ID. Found in BF-11's browser check 4: a reply gave https://your-domain/application/files/… for an image the tool
 * returned only as a path.
 *
 * The URL is Concrete's own (File\Version::getURL()), built from the site's canonical URL; over HTTP with none set,
 * Concrete uses the request's host. Only on the CLI of a site with no canonical URL is there no host, and then the
 * URL is null and the result carries NOTE.
 *
 * katalysis_pro can't depend on this package, so its people tools have the same few lines privately, with the same note.
 */
final class AbsoluteUrl
{
    public const NOTE = 'No absolute image URL: this site has no canonical URL set (Dashboard > System & Settings > SEO & Statistics > URLs and Redirection). Give a site-relative path as it is and don\'t add a host.';

    /**
     * The file's absolute URL, or null when there is no such file or no host to build the URL with.
     */
    public static function forFile(?int $fID): ?string
    {
        if ($fID === null || $fID <= 0) {
            return null;
        }
        $file = File::getByID($fID);
        $version = $file ? $file->getApprovedVersion() : null;
        $url = $version ? (string) $version->getURL() : '';

        return self::isAbsolute($url) ? $url : null;
    }

    public static function isAbsolute(string $url): bool
    {
        return preg_match('~^https?://[^/]+~i', $url) === 1;
    }

    /**
     * Adds NOTE to a result when a file that exists has no URL.
     *
     * @param array<string, mixed> $result
     * @param array<array-key, array{0: ?int, 1: ?string}> $pairs each file ID with the URL it got
     *
     * @return array<string, mixed>
     */
    public static function withNote(array $result, array $pairs): array
    {
        foreach ($pairs as [$fID, $url]) {
            if ($fID !== null && $fID > 0 && $url === null && File::getByID($fID)) {
                $result['imageUrlNote'] = self::NOTE;
                break;
            }
        }

        return $result;
    }
}
