<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\ToolKind;
use PHPUnit\Framework\TestCase;

/**
 * P3.6-1: a call the user denied (or that was rejected as stale, or by a new message) never ran. Neuron puts plain text
 * in its place ("TOOL NOT EXECUTED. …", ToolNode::stampRejectionResult()), which is not a success, for the injection
 * notice's "then writes" and for the scenario checks.
 */
final class ToolKindRejectionTest extends TestCase
{
    public function testARejectedCallIsNotASuccess(): void
    {
        $this->assertFalse(ToolKind::succeeded(
            "TOOL NOT EXECUTED. The user rejected this action. User instruction: The user chose Deny.. Do not attempt this tool again. Follow the user's instruction or reconsider your plan."
        ));
        $this->assertFalse(ToolKind::succeeded('  TOOL NOT EXECUTED. The user rejected this action.'));
        // Only at the start: a result that quotes the phrase is still the tool's own.
        $this->assertTrue(ToolKind::succeeded('{"ok":true,"data":{"text":"TOOL NOT EXECUTED"}}'));
    }
}
