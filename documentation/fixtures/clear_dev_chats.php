<?php
/**
 * Deletes every stored chat on the dev site (P2.5). Dev chats are disposable: there is no v3 chat compatibility or
 * rollback (decision, Glyn, P2.5). Used once when chat moved to Neuron 4's message store, and again if the upgrade is
 * ever rolled back (DELIVERY_PLAN Phase 2 Rollback).
 *
 * It deletes the rows of KatalysisNeuronAiChats; their messages in KatalysisNeuronAiChatMessages go with them (the
 * foreign key cascades). Nothing else is touched.
 *
 * Run it from the package folder with Concrete's own runner. Options go after "--":
 *
 *   php ../../concrete/bin/concrete c5:exec documentation/fixtures/clear_dev_chats.php
 *       Dry run (the default). Changes nothing. Prints how many chats and messages there are, and their dates.
 *
 *   php ../../concrete/bin/concrete c5:exec documentation/fixtures/clear_dev_chats.php -- --apply
 *       Deletes them, in one transaction, and prints the counts afterwards. Take a backup first:
 *       documentation/fixtures/backup_dev_db.sh <task-id>
 *
 * DEV ONLY: refuses to run unless the site's canonical URL is a .test, .localhost or localhost host.
 */

use Concrete\Core\Support\Facade\Application;

return (function () use ($args, $output): int {
    $app = Application::getFacadeApplication();
    $db = $app->make(\Concrete\Core\Database\Connection\Connection::class);

    $unknown = array_values(array_filter($args, static fn ($arg): bool => $arg !== '--apply'));
    if ($unknown !== []) {
        $output->writeln('<error>Unknown option(s): ' . implode(' ', $unknown) . '. Supported: --apply (put it after "--").</error>');

        return 2;
    }
    $apply = in_array('--apply', $args, true);

    // Dev-only guard (same rule as seed_test_content.php), before anything is read.
    $canonical = (string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url');
    $host = strtolower((string) parse_url($canonical, PHP_URL_HOST));
    $isDevHost = $host !== '' && ($host === 'localhost' || substr($host, -5) === '.test' || substr($host, -10) === '.localhost');
    if (!$isDevHost) {
        $output->writeln('<error>Refusing to run: the canonical URL "' . $canonical . '" is not a .test/.localhost/localhost dev host.</error>');

        return 1;
    }

    $hasMessages = (bool) $db->fetchOne(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'KatalysisNeuronAiChatMessages'"
    );
    $report = function (string $when) use ($db, $output, $hasMessages): void {
        $chats = $db->fetchAssociative('SELECT COUNT(*) AS n, MIN(createdDate) AS oldest, MAX(updatedDate) AS newest FROM KatalysisNeuronAiChats');
        $messages = $hasMessages ? (int) $db->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChatMessages') : 0;
        $output->writeln(sprintf(
            '%s: %d chats (created from %s, last updated %s), %d message rows%s.',
            $when,
            (int) $chats['n'],
            $chats['oldest'] ?? '-',
            $chats['newest'] ?? '-',
            $messages,
            $hasMessages ? '' : ' (no KatalysisNeuronAiChatMessages table yet: run c5:package:update first)'
        ));
    };

    if (!$apply) {
        $output->writeln('DRY RUN. Nothing is changed.');
        $report('Now');
        $output->writeln('To delete them, after a backup: php ../../concrete/bin/concrete c5:exec documentation/fixtures/clear_dev_chats.php -- --apply');

        return 0;
    }

    $report('Before');
    $db->beginTransaction();
    try {
        $deleted = $db->executeStatement('DELETE FROM KatalysisNeuronAiChats');
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $output->writeln('<error>Failed, rolled back, nothing was deleted: ' . $e->getMessage() . '</error>');

        return 1;
    }
    $output->writeln("APPLIED. {$deleted} chats deleted (their messages cascade).");
    $report('After');

    return 0;
})();
