<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Chat\TurnChanges;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Tools\ToolCall;

/**
 * P3.6-2 (plans/P3.6-2.md §3; BF-3 plan §9): a call that fails after writing part of its change keeps the draft
 * (DraftCleanup), and the turn's "Changed" line lists it instead of saying nothing was changed. Throwaway pages; no AI
 * provider call.
 */
final class KeptDraftChangesLineTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        DraftCleanup::takeKept();
        $this->pages->restore();
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    public function testAPartlyWrittenDraftAFailedCallKeptIsListed(): void
    {
        $page = $this->pages->create('Kept draft');
        $cID = (int) $page->getCollectionID();
        $changes = new TurnChanges();

        // A call that makes a draft, writes to it, then fails: DraftCleanup keeps the draft and says so.
        try {
            DraftCleanup::run(static function () use ($page): void {
                PageDraft::of($page)->update(['cDescription' => 'Half written']);
                throw new \RuntimeException('Simulated failure after the write.');
            });
            $this->fail('the call fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('was kept', $e->getMessage());
        }

        $call = new ToolCall('update_page', 'call_kept', ['pageID' => $cID]);
        $call->setResult('Error executing tool: ' . $e->getMessage());
        $changes(new ToolCalled($call));

        $line = (string) $changes->line();
        $this->assertStringStartsWith('Kept, partly written: Kept draft', $line);
        $this->assertStringContainsString(', unpublished version 2.', $line);
        $this->assertStringContainsString('Not done: update_page on ' . $cID . ' (failed).', $line);
        $this->assertStringNotContainsString('Nothing was changed', $line);
    }

    public function testAKeptDraftFromBeforeTheTurnIsNotThisTurns(): void
    {
        $page = $this->pages->create('Kept draft earlier');
        try {
            DraftCleanup::run(static function () use ($page): void {
                PageDraft::of($page)->update(['cDescription' => 'Half written']);
                throw new \RuntimeException('Earlier failure.');
            });
        } catch (\RuntimeException) {
        }

        $changes = new TurnChanges();
        $changes->reset();
        $call = new ToolCall('update_page', 'call_other', ['pageID' => 1]);
        $call->setResult('Error executing tool: no.');
        $changes(new ToolCalled($call));

        $this->assertSame('Nothing was changed. Not done: update_page on 1 (failed).', $changes->line());
    }
}
