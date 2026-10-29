<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Database\Connection\Connection;

/**
 * P3.9-1: what the package's upgrade() does to the chat table that Doctrine's schema update can't. Concrete updates a
 * package's tables in safe mode, which never drops a column, so the legacy `chatHistory` (v3's chat blob, unused since
 * P2.5: the messages are rows in KatalysisNeuronAiChatMessages) is dropped here. Idempotent: it does nothing when the
 * column is gone.
 */
final class ChatTableMigration
{
    public const TABLE = 'KatalysisNeuronAiChats';

    /**
     * @return bool whether the column was there and is now dropped
     */
    public static function dropLegacyChatHistory(Connection $db, string $table = self::TABLE): bool
    {
        $columns = array_map(static fn (string $c): string => strtolower($c), array_keys($db->getSchemaManager()->listTableColumns($table)));
        if (!in_array('chathistory', $columns, true)) {
            return false;
        }
        $db->executeStatement('ALTER TABLE ' . $db->quoteIdentifier($table) . ' DROP COLUMN `chatHistory`');

        return true;
    }
}
