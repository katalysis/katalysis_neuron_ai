<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Eval\ScenarioSetup;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;

/**
 * BF-10: a scenario's `setup: hand_edit` step makes the edit edit mode would (BF-7's browser check 6): a new,
 * unpublished version, with the edited Content block copied under a new ID, and the published version unchanged.
 * Throwaway pages; no provider calls.
 */
final class HandEditSetupTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    public function testAHandEditLeavesAnUnpublishedVersionWithTheBlockCopied(): void
    {
        $page = $this->pages->create('Hand edit', ["<h2>Keep</h2>\r\n\r\n<h3>Old Heading</h3>\r\n", '<p>Other block.</p>']);
        $cID = (int) $page->getCollectionID();
        [$b1, $b2] = $this->pages->blockIDs($cID);

        (new ScenarioSetup())->apply([['hand_edit' => ['page' => (string) $page->getCollectionPath(), 'find' => 'Old Heading', 'replace' => 'New Heading']]]);

        $versions = $this->db()->fetchAllAssociative('SELECT cvID, cvIsApproved FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]);
        $this->assertSame([1, 0], array_map(static fn (array $v): int => (int) $v['cvIsApproved'], $versions), 'one new, unpublished version');
        [$n1, $n2] = $this->pages->blockIDs($cID);
        $this->assertNotSame($b1, $n1, 'the edited block was copied under a new ID');
        $this->assertSame($b2, $n2, 'the other block is shared');
        $content = fn (int $bID): string => (string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$bID]);
        $this->assertSame("<h2>Keep</h2>\r\n\r\n<h3>New Heading</h3>\r\n", $content($n1), 'only the text found changed; CR LF kept');
        $this->assertSame("<h2>Keep</h2>\r\n\r\n<h3>Old Heading</h3>\r\n", $content($b1), 'the published version is unchanged');
    }

    public function testAHandEditOfTextThatIsNotThereFails(): void
    {
        $page = $this->pages->create('Hand edit missing');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no Content block');
        (new ScenarioSetup())->apply([['hand_edit' => ['page' => (string) $page->getCollectionPath(), 'find' => 'Not there', 'replace' => 'x']]]);
    }
}
