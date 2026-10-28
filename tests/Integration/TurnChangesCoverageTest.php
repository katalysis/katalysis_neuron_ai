<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Chat\TurnChanges;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P3.6-1 (plans/P3.6-1.md §4): every write tool the agent offers is recorded by TurnChanges when it succeeds, so the
 * turn's "Changed: …" line lists every change, and "Nothing was changed" is only said when it is true. The superuser
 * is offered every tool, external ones included.
 */
final class TurnChangesCoverageTest extends ConcreteTestCase
{
    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * @return list<string> the names of the write tools the superuser is offered
     */
    private function offeredWriteTools(): array
    {
        ThrowawayPages::actAs('superuser');
        $agent = new ConcreteCmsAgent();
        $names = [];
        foreach ((new \ReflectionMethod($agent, 'tools'))->invoke($agent) as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                if ($tool instanceof ToolInterface && ToolKind::isWrite($tool->getName())) {
                    $names[] = $tool->getName();
                }
            }
        }

        return $names;
    }

    public function testEverySuccessfulWriteIsRecordedWithOrWithoutAPageDraft(): void
    {
        $tools = $this->offeredWriteTools();
        $this->assertGreaterThan(20, count($tools), 'the write tools are found');

        $unrecorded = [];
        foreach ($tools as $name) {
            foreach ([['ok' => true, 'data' => []], ['ok' => true, 'versionState' => ['status' => 'unpublished', 'pageID' => 1, 'versionID' => 2]]] as $result) {
                $changes = new TurnChanges();
                $call = new ToolCall($name, 'call_' . $name, ['fileID' => 7]);
                $call->setResult((string) json_encode($result));
                $changes(new ToolCalled($call));
                if ($changes->all() === [] && $changes->others() === []) {
                    $unrecorded[] = $name;
                }
                $this->assertStringStartsWith('Changed: ', (string) $changes->line(), $name);
            }
        }

        $this->assertSame([], array_values(array_unique($unrecorded)), 'every write tool is recorded');
    }

    public function testAFailedWriteIsListedAsNotDoneAndAReadOnlyTurnHasNoLine(): void
    {
        $changes = new TurnChanges();
        $read = new ToolCall('get_page', 'call_read', ['pageID' => 1]);
        $read->setResult('{"ok":true}');
        $changes(new ToolCalled($read));
        $this->assertNull($changes->line(), 'a turn that wrote nothing has no line');

        $failed = new ToolCall('set_file_attribute_value', 'call_failed', ['fileID' => 7]);
        $failed->setResult('{"ok":false,"error":"No."}');
        $changes(new ToolCalled($failed));
        $this->assertSame('Nothing was changed. Not done: set_file_attribute_value on 7 (failed).', $changes->line());
    }

    public function testAWriteThatIsNotAPageDraftIsNamedByWhatItChanged(): void
    {
        $changes = new TurnChanges();
        $call = new ToolCall('set_file_attribute_value', 'call_file', ['fileID' => 7, 'handle' => 'title']);
        $call->setResult('{"ok":true}');
        $changes(new ToolCalled($call));

        $this->assertSame([['kind' => 'file', 'object' => 'file 7', 'tools' => ['set_file_attribute_value']]], $changes->others());
        $this->assertSame('Changed: file 7.', $changes->line());
    }
}
