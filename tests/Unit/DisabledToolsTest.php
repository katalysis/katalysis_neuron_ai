<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\DisabledTools;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

/**
 * A disabled tool must never reach the agent's final tool list. No Concrete,
 * no database.
 */
final class DisabledToolsTest extends TestCase
{
    /** @var array<int, array{string, string}> */
    private array $log = [];

    private function logger(): callable
    {
        return function (string $level, string $message): void {
            $this->log[] = [$level, $message];
        };
    }

    private static function kit(array $names): ToolkitInterface
    {
        return new class ($names) extends AbstractToolkit {
            public function __construct(private array $names)
            {
            }

            public function provide(): array
            {
                return array_map(static fn (string $n): ToolInterface => new Tool($n, 'd'), $this->names);
            }
        };
    }

    /**
     * @param array<int, mixed> $items
     * @return string[]
     */
    private static function names(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                $names[] = $tool->getName();
            }
        }
        sort($names);

        return $names;
    }

    public function testTheListHoldsTheToolsDisabledForSafetyEachWithAReason(): void
    {
        // P1.6 (people deletes), P1.7 (file delete, user attribute write, publishing),
        // P1.8 (EPRA sync writes to the live version) and P1.13 (everything else that changes the live site outside a draft).
        $this->assertSame(
            [
                'add_person',
                'approve_page_version',
                'delete_all_people',
                'delete_attribute_set',
                'delete_file',
                'delete_page',
                'delete_person',
                'delete_topic',
                'move_file_to_folder',
                'move_page',
                'set_user_attribute_value',
                'sync_multi_links_to_epra_resources_list',
                'update_file_select_attribute_options',
                'update_page_select_attribute_options',
                'update_person',
                'update_person_specialisms',
                'update_person_topics',
                'update_user_select_attribute_options',
            ],
            DisabledTools::names()
        );
        foreach (DisabledTools::all() as $name => $reason) {
            $this->assertMatchesRegularExpression('/P3\.[456]/', $reason, $name);
        }
    }

    public function testEveryToolDisabledInP113SaysWhenItComesBack(): void
    {
        $reasons = DisabledTools::all();
        foreach (['delete_page', 'move_page'] as $name) {
            $this->assertStringContainsString('P3.6', $reasons[$name], $name);
            $this->assertStringContainsString('only behind approval', $reasons[$name], $name);
        }
        $this->assertStringContainsString('P3.5', $reasons['move_file_to_folder']);
    }

    public function testEveryToolOnTheRealListIsRemovedFromToolkitsAndSingleTools(): void
    {
        $items = [
            self::kit(['list_people', 'delete_person', 'delete_all_people']),
            new Tool('delete_person', 'single'),
            new Tool('keep_me', 'single'),
        ];

        $result = DisabledTools::apply($items, $this->logger());
        $names = self::names($result);

        $this->assertSame(['keep_me', 'list_people'], $names);
        foreach (DisabledTools::names() as $disabled) {
            $this->assertNotContains($disabled, $names);
        }
    }

    public function testEachRemovedToolIsLogged(): void
    {
        DisabledTools::apply([self::kit(['delete_person', 'delete_all_people', 'ok'])], $this->logger());

        $this->assertCount(2, $this->log);
        $messages = implode("\n", array_column($this->log, 1));
        $this->assertStringContainsString('"delete_person"', $messages);
        $this->assertStringContainsString('"delete_all_people"', $messages);
        $this->assertStringContainsString('P3.5', $messages);
    }

    public function testAToolkitWithNothingDisabledIsReturnedUntouchedAndNothingIsLogged(): void
    {
        $kit = self::kit(['a', 'b']);

        $this->assertSame([$kit], DisabledTools::apply([$kit], $this->logger()));
        $this->assertSame([], $this->log);
    }

    public function testACustomListCanBeUsedAndTheCheckCanFail(): void
    {
        $result = DisabledTools::apply([self::kit(['a', 'b'])], $this->logger(), ['a' => 'test reason']);

        $this->assertSame(['b'], self::names($result));
    }
}
