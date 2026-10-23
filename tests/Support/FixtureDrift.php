<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * WP-2: has a fixture page changed since the seed last ran? A browser check, a page-properties save or a test that
 * didn't clean up can leave /neuron-test different from what the seed made, and a test that reads it then fails on a
 * confusing assertion (BF-4: an `exclude_nav` line in PromptBaselineTest). ConcreteTestCase checks before every
 * integration test class and fails with "fixture drift: run the seed reset", naming the pages.
 *
 * After every successful run the seed records a fingerprint of each page at /neuron-test and below in one Config row
 * (katalysis_neuron_ai / fixture / pages). A page's fingerprint covers every row of its versions, the blocks in them,
 * their content-block text and their attribute values, so a new version or a change in place both show. Only the
 * seed writes the row; --reset-only removes it.
 *
 * differences() is a pure function of two fingerprints, so it is tested without Concrete.
 */
final class FixtureDrift
{
    public const ROOT = '/neuron-test';

    private const NAMESPACE = 'katalysis_neuron_ai';
    private const GROUP = 'fixture';
    private const ITEM = 'pages';

    /**
     * @return array<string, array{cID: int, versions: int, newest: int, newestApproved: bool, hash: string}> path => page
     */
    public static function fingerprint(Connection $db): array
    {
        $pages = [];
        foreach ($db->fetchAllAssociative(
            'SELECT pp.cID, pp.cPath FROM PagePaths pp JOIN Pages p ON p.cID = pp.cID
             WHERE pp.ppIsCanonical = 1 AND (pp.cPath = ? OR pp.cPath LIKE ?) ORDER BY pp.cPath',
            [self::ROOT, self::ROOT . '/%']
        ) as $row) {
            $cID = (int) $row['cID'];
            $versions = $db->fetchAllAssociative('SELECT * FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]);
            $blocks = $db->fetchAllAssociative(
                'SELECT * FROM CollectionVersionBlocks WHERE cID = ? ORDER BY cvID, arHandle, cbDisplayOrder, bID',
                [$cID]
            );
            $text = $db->fetchAllAssociative(
                'SELECT c.bID, c.content FROM btContentLocal c
                 WHERE c.bID IN (SELECT bID FROM CollectionVersionBlocks WHERE cID = ?) ORDER BY c.bID',
                [$cID]
            );
            $attributes = $db->fetchAllAssociative(
                'SELECT * FROM CollectionAttributeValues WHERE cID = ? ORDER BY cvID, akID',
                [$cID]
            );
            $newest = $versions === [] ? [] : $versions[array_key_last($versions)];

            $pages[(string) $row['cPath']] = [
                'cID' => $cID,
                'versions' => count($versions),
                'newest' => (int) ($newest['cvID'] ?? 0),
                'newestApproved' => (int) ($newest['cvIsApproved'] ?? 0) === 1,
                'hash' => sha1((string) json_encode([$versions, $blocks, $text, $attributes])),
            ];
        }

        return $pages;
    }

    public static function record(Connection $db): void
    {
        self::forget($db);
        $db->insert('Config', [
            'configNamespace' => self::NAMESPACE,
            'configGroup' => self::GROUP,
            'configItem' => self::ITEM,
            'configValue' => (string) json_encode(self::fingerprint($db)),
        ]);
    }

    public static function forget(Connection $db): void
    {
        $db->delete('Config', ['configNamespace' => self::NAMESPACE, 'configGroup' => self::GROUP, 'configItem' => self::ITEM]);
    }

    /**
     * @return array<string, array<string, mixed>>|null null when the seed has recorded nothing
     */
    public static function recorded(Connection $db): ?array
    {
        $json = $db->fetchOne(
            'SELECT configValue FROM Config WHERE configNamespace = ? AND configGroup = ? AND configItem = ?',
            [self::NAMESPACE, self::GROUP, self::ITEM]
        );
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * The message to fail with, or null when every fixture page is as the seed left it.
     */
    public static function problem(Connection $db): ?string
    {
        $recorded = self::recorded($db);
        if ($recorded === null) {
            return self::message(['the seed has recorded no fingerprint of the fixture pages (it records one since WP-2)']);
        }
        $differences = self::differences($recorded, self::fingerprint($db));

        return $differences === [] ? null : self::message($differences);
    }

    /**
     * @param array<string, array<string, mixed>> $recorded what the seed left
     * @param array<string, array<string, mixed>> $now
     *
     * @return list<string> one line per changed page, naming it
     */
    public static function differences(array $recorded, array $now): array
    {
        $lines = [];
        $paths = array_unique(array_merge(array_keys($recorded), array_keys($now)));
        sort($paths);

        foreach ($paths as $path) {
            $was = $recorded[$path] ?? null;
            $is = $now[$path] ?? null;
            if ($was === null) {
                $lines[] = "{$path} (page ID {$is['cID']}) was added";
            } elseif ($is === null) {
                $lines[] = "{$path} (page ID {$was['cID']}) is gone";
            } elseif ((int) $was['cID'] !== (int) $is['cID']) {
                $lines[] = "{$path} is a different page (page ID {$is['cID']}, the seed made {$was['cID']})";
            } elseif ((int) $was['versions'] !== (int) $is['versions'] || (int) $was['newest'] !== (int) $is['newest']) {
                $lines[] = sprintf(
                    '%s (page ID %d) has %d version(s), newest %d (%s); the seed left %d, newest %d',
                    $path,
                    $is['cID'],
                    $is['versions'],
                    $is['newest'],
                    $is['newestApproved'] ? 'approved' : 'not approved',
                    $was['versions'],
                    $was['newest']
                );
            } elseif ((string) $was['hash'] !== (string) $is['hash']) {
                $lines[] = "{$path} (page ID {$is['cID']}) was changed in place (a version, block, text or attribute row)";
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $differences
     */
    public static function message(array $differences): string
    {
        return 'fixture drift: run the seed reset. ' . self::ROOT . ' is not as the seed left it:' . "\n  "
            . implode("\n  ", $differences)
            . "\nFrom httpdocs/: php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/seed_test_content.php -- --reset"
            . ' (back up the dev database first if the change matters: documentation/fixtures/backup_dev_db.sh).';
    }
}
