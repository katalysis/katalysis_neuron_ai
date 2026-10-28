<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\Approval\ChangePreview;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P3.6-2 (Glyn, approving the plan): gating must not rest on tool names alone. ApprovalService gates every tool
 * ToolKind::isWrite() calls a write; this test holds that against the hand-kept classification
 * (tests/Support/tool-classification.txt, ToolClassificationTest): every tool classified as a write there and offered
 * at any tier (the super user is offered every tier's tools) waits for approval and has a preview of its own, and
 * isWrite() agrees with the classification on every tool in it.
 */
final class ApprovalRegistryTest extends ConcreteTestCase
{
    private const CLASSIFICATION = __DIR__ . '/../Support/tool-classification.txt';

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    /** @return array<string, string> tool => "read" or "write" */
    private static function classification(): array
    {
        $kinds = [];
        foreach (file(self::CLASSIFICATION, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^(read|write)\s+(\S+)$/', trim($line), $m) === 1) {
                $kinds[$m[2]] = $m[1];
            }
        }

        return $kinds;
    }

    /** @return array<string, ToolInterface> the tools the super user is offered, by name */
    private function offered(): array
    {
        ThrowawayPages::actAs('superuser');
        $agent = new ConcreteCmsAgent();
        $tools = [];
        foreach ((new \ReflectionMethod($agent, 'tools'))->invoke($agent) as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                if ($tool instanceof ToolInterface) {
                    $tools[$tool->getName()] = $tool;
                }
            }
        }

        return $tools;
    }

    public function testIsWriteAgreesWithTheClassificationOnEveryTool(): void
    {
        $kinds = self::classification();
        $this->assertGreaterThan(50, count($kinds), 'the classification is read');

        $disagree = [];
        foreach ($kinds as $tool => $kind) {
            if (ToolKind::isWrite($tool) !== ($kind === 'write')) {
                $disagree[] = "{$tool} (classified {$kind}, isWrite() says " . (ToolKind::isWrite($tool) ? 'write' : 'read') . ')';
            }
        }

        $this->assertSame([], $disagree, 'isWrite() and tool-classification.txt disagree');
    }

    public function testEveryOfferedWriteIsGatedAndHasItsOwnPreview(): void
    {
        $kinds = self::classification();
        $offered = $this->offered();

        $problems = [];
        foreach ($offered as $name => $tool) {
            if (($kinds[$name] ?? null) !== 'write') {
                continue;
            }
            if ($tool->requiresApproval() !== ApprovalService::reason($name)) {
                $problems[] = "{$name}: not gated";
            }
            if (!ChangePreview::hasPreview($name)) {
                $problems[] = "{$name}: no preview of its own";
            }
        }
        $writes = array_filter($kinds, static fn (string $k): bool => $k === 'write');
        $this->assertNotSame([], array_intersect_key($writes, $offered), 'writes are offered');

        $this->assertSame([], $problems);
    }

    public function testAReadIsNeverGated(): void
    {
        $kinds = self::classification();
        foreach ($this->offered() as $name => $tool) {
            if (($kinds[$name] ?? null) === 'read') {
                $this->assertFalse($tool->requiresApproval(), $name);
            }
        }
    }
}
