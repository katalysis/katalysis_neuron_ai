<?php
/**
 * One-off clean-up of the rows that tests left in the dev database before P1.12 (see documentation/plans/P1.12.md).
 *
 *   - permission rows of deleted test pages (Page::delete() does not remove AreaPermissionAssignments), with the
 *     accesses only those rows used
 *   - permission entities of deleted users (UserInfo::delete() does not remove them)
 *   - sign-up notifications whose sign-up is gone, with their alerts
 *
 * Rows are chosen by criteria (tests/Support/LeftoverCleanup.php), never by a list of IDs. It does NOT touch the orphan
 * blocks, Logs, the live fixture page, live notifications or any access that something else still uses.
 *
 * Run it from httpdocs/ with Concrete's own runner. Options after "--" are passed to this script:
 *
 *   php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/cleanup_test_leftovers.php
 *       Dry run (the default). Changes nothing. Prints what it would delete and why, and writes the summary file.
 *
 *   php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/documentation/fixtures/cleanup_test_leftovers.php \
 *       -- --apply --expect=<summary file of the dry run> --dump=<fresh mysqldump file>
 *       Deletes. Needs both options: the summary the dry run wrote, and a dump taken after it ("Dump completed" on its
 *       last line, newer than the summary). Inside ONE transaction it selects again and rolls back, deleting nothing,
 *       if the result differs from the summary. After the commit it selects once more and reports anything left.
 *
 *   --summary=<file>   where the dry run writes the summary (default: neuron_ai_cleanup_summary.json in the temp dir)
 *
 * Sequence: dry run, mysqldump, --apply straight after, a second dry run that must find nothing.
 *
 * DEV ONLY: refuses to run unless the site's canonical URL is a .test, .localhost or localhost host.
 */

use Concrete\Core\Support\Facade\Application;

require_once dirname(__DIR__, 2) . '/tests/Support/LeftoverCleanup.php';

use Katalysis\NeuronAi\Tests\Support\LeftoverCleanup;

$cleanupStatus = (function () use ($args, $output) {
    $app = Application::getFacadeApplication();
    $db = $app->make('database')->connection();

    $options = [];
    $unknown = [];
    foreach ($args as $arg) {
        if ($arg === '--apply') {
            $options['apply'] = true;
        } elseif (preg_match('/^--(expect|dump|summary)=(.+)$/', (string) $arg, $m) === 1) {
            $options[$m[1]] = $m[2];
        } else {
            $unknown[] = $arg;
        }
    }
    if ($unknown !== []) {
        $output->writeln('<error>Unknown option(s): ' . implode(' ', $unknown) . '. Supported: --apply, --expect=, --dump=, --summary= (put them after "--").</error>');

        return 2;
    }
    $apply = isset($options['apply']);

    // Dev-only guard (same rule as seed_test_content.php).
    $canonical = (string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url');
    $host = strtolower((string) parse_url($canonical, PHP_URL_HOST));
    $isDevHost = $host !== '' && ($host === 'localhost' || substr($host, -5) === '.test' || substr($host, -10) === '.localhost');
    if (!$isDevHost) {
        $output->writeln('<error>Refusing to run: the canonical URL "' . $canonical . '" is not a .test/.localhost/localhost dev host.</error>');

        return 1;
    }

    $summaryFile = $options['summary'] ?? (sys_get_temp_dir() . '/neuron_ai_cleanup_summary.json');
    $cleanup = new LeftoverCleanup($db);

    $report = function (array $selection) use ($output): void {
        $s = $selection['selected'];
        $e = $selection['evidence'];
        $output->writeln('Live pages outside /neuron-test go up to cID ' . $e['liveMaxPageID'] . '; the area permission keys number ' . $e['areaPermissionKeys'] . '.');
        $output->writeln(sprintf('1. Dead-page permission rows: %d pages, %d AreaPermissionAssignments rows', count($s['pages']), $s['assignmentRows']));
        $output->writeln(sprintf(
            '   accesses only they use: %d (with %d PermissionAccessList and %d access-list child rows)',
            count($s['accesses']),
            $s['accessListRows'],
            $s['accessChildRows']
        ));
        foreach ($e['pagesNotSelected'] as $cID => $reason) {
            $output->writeln("   NOT selected, page {$cID}: {$reason}");
        }
        foreach ($e['accessesKept'] as $paID => $reason) {
            $output->writeln("   KEPT access {$paID}: {$reason}");
        }
        $output->writeln(sprintf('2. User entities with no user: %d', count($s['userEntities'])));
        foreach ($e['userEntitiesKept'] as $peID => $reason) {
            $output->writeln("   KEPT entity {$peID}: {$reason}");
        }
        $output->writeln(sprintf('3. Dangling sign-up notifications: %d, with %d alerts', count($s['notifications']), $s['alertRows']));
        foreach ($e['notificationsKept'] as $nID => $reason) {
            $output->writeln("   KEPT notification {$nID}: {$reason}");
        }
        $output->writeln('   Live sign-up notifications (not touched): ' . $e['liveSignupNotifications']);
        foreach ($e['otherNotificationTypes'] as $type => $count) {
            $output->writeln("   Other notifications (reported, not touched): {$type} x {$count}");
        }
    };

    // ---- Dry run ------------------------------------------------------------------------------------------------
    if (!$apply) {
        $selection = $cleanup->select();
        $output->writeln('DRY RUN. Nothing is changed.');
        $report($selection);
        $summary = LeftoverCleanup::summary($selection);
        if (LeftoverCleanup::isEmpty($selection)) {
            $output->writeln('Nothing to clean up.');
        }
        file_put_contents($summaryFile, json_encode(['summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $output->writeln('Summary written to ' . $summaryFile);

        return 0;
    }

    // ---- Apply --------------------------------------------------------------------------------------------------
    $expectFile = $options['expect'] ?? null;
    $dumpFile = $options['dump'] ?? null;
    if ($expectFile === null || $dumpFile === null) {
        $output->writeln('<error>--apply needs --expect=<summary file of the dry run> and --dump=<fresh dump file>. Nothing was changed.</error>');

        return 2;
    }
    if (!is_file($expectFile)) {
        $output->writeln("<error>The summary file {$expectFile} does not exist. Run the dry run first. Nothing was changed.</error>");

        return 2;
    }
    if (!is_file($dumpFile) || filemtime($dumpFile) < filemtime($expectFile)) {
        $output->writeln("<error>The dump {$dumpFile} does not exist or is older than the summary {$expectFile}. Take a fresh dump after the dry run. Nothing was changed.</error>");

        return 2;
    }
    $tail = (string) file_get_contents($dumpFile, false, null, max(0, (int) filesize($dumpFile) - 300));
    if (strpos($tail, 'Dump completed') === false) {
        $output->writeln("<error>The dump {$dumpFile} does not end with \"Dump completed\": it may be incomplete. Nothing was changed.</error>");

        return 2;
    }
    $expected = json_decode((string) file_get_contents($expectFile), true);
    if (!is_array($expected) || !isset($expected['summary'])) {
        $output->writeln("<error>The summary file {$expectFile} is not a summary written by this script. Nothing was changed.</error>");

        return 2;
    }

    $db->beginTransaction();
    try {
        $selection = $cleanup->select();
        $now = LeftoverCleanup::summary($selection);
        if ($now != $expected['summary']) {
            $db->rollBack();
            $output->writeln('<error>The numbers differ from the dry run. ABORTED, nothing was deleted. Run the dry run again.</error>');
            $output->writeln('Dry run: ' . json_encode($expected['summary']['counts'] ?? null) . ' rows ' . ($expected['summary']['assignmentRows'] ?? '?'));
            $output->writeln('Now:     ' . json_encode($now['counts']) . ' rows ' . $now['assignmentRows']);

            return 1;
        }
        $deleted = $cleanup->apply($selection);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $output->writeln('<error>Failed, rolled back, nothing was deleted: ' . $e->getMessage() . '</error>');

        return 1;
    }

    $output->writeln('APPLIED. Rows deleted:');
    foreach ($deleted as $table => $count) {
        $output->writeln(sprintf('  %-42s %d', $table, $count));
    }

    $left = $cleanup->select();
    if (!LeftoverCleanup::isEmpty($left)) {
        $output->writeln('<error>The re-check still finds rows to clean up:</error>');
        $report($left);

        return 1;
    }
    $output->writeln('Re-check: nothing left to clean up.');

    return 0;
})();

// Permissions are cached: after a real change clear the cache, as Dashboard > Optimization > Clear Cache does.
if ($cleanupStatus === 0 && in_array('--apply', $args, true)) {
    $cleanupApp = Application::getFacadeApplication();
    $clearCache = new \Concrete\Core\Cache\Command\ClearCacheCommand();
    $clearCache->setLogCacheClear(true);
    $cleanupApp->executeCommand($clearCache);
    $cleanupApp->make('config')->save('concrete.cache.last_cleared', time());
    $output->writeln('Concrete cache cleared.');
}

return $cleanupStatus;
