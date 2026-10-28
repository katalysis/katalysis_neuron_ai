<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3.5-1 (B-ACC-5): no tool elevates to the super user or skips Concrete's permission check "because it's the
 * assistant". Every use in src/ of the ways code does that (acting as another user, a super-user shortcut, a list
 * that ignores permissions) must be listed here with the reason it isn't one. A new use fails this test until it is
 * reviewed. src/Toolkits/ is the unregistered legacy copy, removed in P3.3-3, and is not scanned.
 */
final class NoElevationTest extends TestCase
{
    private const PATTERNS = [
        'isSuperUser(', 'setCustomRequestUser', 'USER_SUPER_ID', 'loginByUserID', 'ignorePermissions', '->sudo', 'new User(1)',
    ];

    /** "relative path: pattern" => why it is not an elevation. */
    private const ALLOWED = [
        'src/Access/NeuronPermissions.php: isSuperUser(' => 'the chat access check: when the task key is missing only the super user may use the assistant, which restricts',
    ];

    public function testEveryElevationPatternInSrcIsListedWithAReason(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr((string) $file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || str_starts_with($path, 'src/Toolkits/')) {
                continue;
            }
            $code = (string) file_get_contents((string) $file->getPathname());
            foreach (self::PATTERNS as $pattern) {
                if (str_contains($code, $pattern)) {
                    $found[] = "{$path}: {$pattern}";
                }
            }
        }
        sort($found);
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);

        $this->assertSame($allowed, $found, 'A use of an elevation pattern in src/ is not reviewed (add it to ALLOWED with the reason it '
            . 'is not an elevation, or remove it), or an ALLOWED entry is no longer there.');
    }
}
