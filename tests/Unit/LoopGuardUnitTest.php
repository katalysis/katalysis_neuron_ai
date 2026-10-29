<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\Approval\LoopGuard;
use PHPUnit\Framework\TestCase;

/**
 * P3.9-3: the loop guard's rules, without Concrete. LoopGuardTest runs them through real turns.
 */
final class LoopGuardUnitTest extends TestCase
{
    public function testOnlyTheUsersDenyStopsATool(): void
    {
        $this->assertSame(['update_page' => true], LoopGuard::afterDecision('update_page', ApprovalService::DENY_REASON, []));
        $this->assertSame(['update_page' => true], LoopGuard::afterDecision('update_page', 'The user chose Deny and said: not now', []));
        $this->assertSame([], LoopGuard::afterDecision('update_page', ApprovalService::STALE_REASON, []), 'stale');
        $this->assertSame([], LoopGuard::afterDecision('update_page', ApprovalService::NEW_MESSAGE_REASON, []), 'a new message');
        $this->assertSame([], LoopGuard::afterDecision('update_page', ApprovalService::REFUSED_PREFIX . 'Permission denied', []), 'its own checks');
    }

    public function testTheSameInputsInAnyOrderAreTheSameCall(): void
    {
        $failed = LoopGuard::afterResult('update_page', ['pageID' => 5, 'name' => 'A', 'attributes' => ['b' => 1, 'a' => 2]], 'Error executing tool: no', []);

        $this->assertNotNull(LoopGuard::refusal('update_page', ['name' => 'A', 'attributes' => ['a' => 2, 'b' => 1], 'pageID' => 5], [], $failed));
        $this->assertNull(LoopGuard::refusal('update_page', ['pageID' => 5, 'name' => 'B'], [], $failed), 'one alternative is allowed');
    }

    public function testASuccessIsNotAFailureAndAnErrorMarkIs(): void
    {
        $this->assertSame([], LoopGuard::afterResult('get_page', ['pageID' => 1], '{"id":1}', []));
        $this->assertCount(1, LoopGuard::afterResult('get_block_data', ['blockID' => 9], '❌ Error: Block 9 not found', [])['get_block_data']);
    }

    public function testTwoFailuresStopTheToolAndADenyStopsItWhateverItsInputs(): void
    {
        $failed = LoopGuard::afterResult('get_page', ['pageID' => 1], 'Error executing tool: x', []);
        $failed = LoopGuard::afterResult('get_page', ['pageID' => 2], 'Error executing tool: x', $failed);

        $this->assertStringContainsString('has failed twice', (string) LoopGuard::refusal('get_page', ['pageID' => 3], [], $failed));
        $this->assertStringContainsString('The user declined update_page', (string) LoopGuard::refusal('update_page', ['pageID' => 7], ['update_page' => true], []));
        $this->assertNull(LoopGuard::refusal('create_page', ['parentID' => 1], ['update_page' => true], []), 'other tools are not stopped');
    }
}
