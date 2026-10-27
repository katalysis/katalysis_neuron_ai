<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\File\File;
use Concrete\Core\Support\Facade\Application;

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
     * The site's canonical URL (Concrete's url/canonical), or null when it has no host (the CLI with none set).
     */
    public static function siteBase(): ?string
    {
        $base = (string) Application::getFacadeApplication()->make('url/canonical');

        return self::isAbsolute($base) ? $base : null;
    }

    /**
     * HTML with every site-relative src, href and srcset URL (one leading "/") made absolute against $base (P3.3-1).
     * Concrete's Html\Image gives an img a relative src beside an absolute srcset. Only attribute values inside tags
     * change; with no absolute $base the HTML is returned as it is.
     */
    public static function inHtml(string $html, ?string $base): string
    {
        $base = rtrim((string) $base, '/');
        if (!self::isAbsolute($base)) {
            return $html;
        }

        return (string) preg_replace_callback('~<[a-z][^>]*>~i', static function (array $tag) use ($base): string {
            return (string) preg_replace_callback(
                '~(\s(src|href|srcset)\s*=\s*)(["\'])(.*?)\3~is',
                static function (array $m) use ($base): string {
                    // A srcset holds several URLs, each first or after a comma.
                    $pattern = strtolower($m[2]) === 'srcset' ? '~(^|,\s*)/(?!/)~' : '~^()/(?!/)~';
                    $value = (string) preg_replace($pattern, '$1' . $base . '/', $m[4]);

                    return $m[1] . $m[3] . $value . $m[3];
                },
                $tag[0]
            );
        }, $html);
    }

    /**
     * Whether a tag in the HTML has a site-relative src, href or srcset URL.
     */
    public static function hasRelativeUrls(string $html): bool
    {
        return preg_match('~<[a-z][^>]*\s(?:src|href|srcset)\s*=\s*["\'](?:[^"\']*,\s*)?/(?!/)~i', $html) === 1;
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
