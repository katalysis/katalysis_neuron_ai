<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Chat\ChatTableMigration;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * P3.9-1 (plans/P3.9-1.md): the chat table under Doctrine. The entity has no chatHistory (unused since P2.5) and keeps
 * the three indexes the old SQL had; upgrade() drops the legacy column, once, because Concrete's schema update never
 * drops a column. The migration is tried on a scratch table the test makes and removes, never on the real one.
 */
final class ChatTableTest extends ConcreteTestCase
{
    private const SCRATCH = 'neuron_test_p391_chats';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement('DROP TABLE IF EXISTS ' . self::SCRATCH);
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    public function testTheEntityHasNoChatHistoryAndKeepsTheThreeIndexes(): void
    {
        $metadata = $this->app()->make(EntityManagerInterface::class)->getClassMetadata(Chat::class);

        $this->assertFalse($metadata->hasField('chatHistory'));
        $indexes = $metadata->table['indexes'] ?? [];
        $this->assertSame(['sessionId'], $indexes['idx_session']['columns'] ?? null);
        $this->assertSame(['createdBy'], $indexes['idx_created_by']['columns'] ?? null);
        $this->assertSame(['createdDate'], $indexes['idx_created_date']['columns'] ?? null);
    }

    public function testTheMigrationDropsTheLegacyColumnOnceAndKeepsTheRows(): void
    {
        $db = $this->db();
        $db->executeStatement('CREATE TABLE ' . self::SCRATCH . ' (id INT AUTO_INCREMENT PRIMARY KEY, sessionId VARCHAR(255) NULL, chatHistory LONGTEXT NULL)');
        $db->executeStatement('INSERT INTO ' . self::SCRATCH . " (sessionId, chatHistory) VALUES ('s1', '[]')");

        $this->assertTrue(ChatTableMigration::dropLegacyChatHistory($db, self::SCRATCH));
        $columns = array_map('strtolower', array_column($db->fetchAllAssociative('SHOW COLUMNS FROM ' . self::SCRATCH), 'Field'));
        $this->assertNotContains('chathistory', $columns);
        $this->assertSame('s1', $db->fetchOne('SELECT sessionId FROM ' . self::SCRATCH));

        $this->assertFalse(ChatTableMigration::dropLegacyChatHistory($db, self::SCRATCH), 'a second run does nothing');
    }
}
