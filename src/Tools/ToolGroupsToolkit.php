<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\User\User;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * get_tool_group (P3.2-2, plans/P3.2-2.md §2): each request offers only some tools (ToolGroups); this one, always
 * offered, adds a group for the rest of the request. GroupedChatNode reads the call from the conversation and offers
 * the group from the model's next step; the tool itself changes nothing.
 */
class ToolGroupsToolkit extends AbstractToolkit
{
    /**
     * @param (\Closure(string): list<string>)|null $toolsIn the user's tools in a group (the agent's registry); null
     *     leaves them out of the result
     */
    public function __construct(private readonly ?\Closure $toolsIn = null)
    {
    }

    public function provide(): array
    {
        return [$this->makeGetToolGroupTool()];
    }

    protected function makeGetToolGroupTool(): Tool
    {
        $groups = [];
        foreach (ToolGroups::ORDER as $group) {
            $groups[] = "{$group} ({$this->describe($group)})";
        }

        $tool = new CallableTool(
            ToolGroups::GET_TOOL_GROUP,
            'Only some of your tools are offered on each request. When the task needs a tool you don\'t have, call this '
            . 'to add its group, and the group\'s tools are offered from your next step in this request. Groups: '
            . implode('; ', $groups) . '. Ask for a group before telling the user something can\'t be done.'
        );

        $tool->addProperty(new ToolProperty(
            'group',
            PropertyType::STRING,
            'The group to add',
            true,
            ToolGroups::ORDER
        ));

        $tool->setHandler(function (?string $group = null): array {
            try {
                if (!(new User())->isRegistered()) {
                    throw new \RuntimeException('Authentication required: Please log in to use this tool.');
                }
                if (!in_array($group, ToolGroups::ORDER, true)) {
                    throw new \InvalidArgumentException('Unknown group "' . (string) $group . '". Groups: ' . implode(', ', ToolGroups::ORDER) . '.');
                }

                $data = [
                    'group' => $group,
                    'for' => $this->describe($group),
                    'note' => "The {$group} tools are offered from your next step in this request: call the one you need.",
                ];
                // The full run showed the model asking again for a group it had when the tool it wanted doesn't
                // exist; the list tells it there is none.
                if ($this->toolsIn !== null) {
                    $data['tools'] = ($this->toolsIn)($group);
                    $data['note'] = "These are all the {$group} tools you can use, offered from your next step in this request. "
                        . 'If none of them does what the task needs, there is no tool for it: tell the user, and don\'t ask for the group again.';
                }

                return ['ok' => true, 'data' => $data];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        });

        return $tool;
    }

    private function describe(string $group): string
    {
        return ToolGroups::DESCRIPTIONS[$group] ?? $group;
    }
}
