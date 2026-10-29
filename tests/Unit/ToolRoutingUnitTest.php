<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Agent\GroupRouter;
use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\ToolGroups;
use Katalysis\NeuronAi\Agent\GroupedChatNode;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\ToolTiers;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

/**
 * P3.2-2 (plans/P3.2-2.md): the tool groups and the word-rule router, without Concrete. Every request offers the
 * "always" tools and the groups the router picks, at most 25 tools, in one fixed order (Glyn: a stable request prefix
 * for prompt caching).
 */
final class ToolRoutingUnitTest extends TestCase
{
    public function testNoToolIsInTwoGroupsAndTheOrderIsFixed(): void
    {
        $seen = [];
        foreach (ToolGroups::ALWAYS as $tool) {
            $seen[$tool] = 'always';
        }
        foreach (ToolGroups::ORDER as $group) {
            foreach (ToolGroups::GROUPS[$group] ?? [] as $tool) {
                $this->assertArrayNotHasKey($tool, $seen, "{$tool} is in {$group} and " . ($seen[$tool] ?? ''));
                $seen[$tool] = $group;
            }
        }
        $this->assertSame(ToolGroups::ORDER, [...array_keys(ToolGroups::GROUPS), 'external'], 'GROUPS is written in the fixed order, external last');
        $this->assertSame(ToolGroups::ORDER, array_keys(ToolGroups::DESCRIPTIONS), 'every group is described for get_tool_group');
    }

    public function testEveryCoreToolIsInAGroupAndOnlyCorePeopleToolsJoinExternal(): void
    {
        $external = array_values(array_filter(array_keys(ToolTiers::all()), static fn (string $t): bool => ToolGroups::groupOf($t) === 'external'));
        sort($external);

        // Core PeopleToolkit's tools go with katalysis_pro's until P3.10 removes them (plans/P3.2-2.md §1).
        $this->assertSame(['get_people_image_file_ids', 'get_person_details', 'list_people', 'update_person_specialisms', 'update_person_topics'], $external);
        foreach ([...ToolGroups::ALWAYS, ...array_merge(...array_values(ToolGroups::GROUPS))] as $tool) {
            $this->assertArrayHasKey($tool, ToolTiers::all(), "{$tool} is listed in a group but is no core tool");
        }
    }

    public function testTheOfferIsAlwaysFirstThenTheGroupsInTheFixedOrderWhateverTheRegistryOrder(): void
    {
        $registry = self::tools(['list_reviews', 'search_users', 'get_file', 'list_pages', 'get_page', 'update_block_in_page_area', 'get_tool_group', 'add_block_to_page_area', 'get_review']);

        $offered = ToolGroups::offer($registry, ['external', 'users', 'content']);

        $this->assertSame(
            ['get_page', 'get_tool_group', 'add_block_to_page_area', 'update_block_in_page_area', 'search_users', 'list_reviews', 'get_review'],
            array_map(static fn (ToolInterface $t): string => $t->getName(), $offered)
        );
    }

    public function testTheNodeRoutesOnTheUsersMessageWithoutThePageTagAndAddsTheGroupsAskedFor(): void
    {
        $registry = self::tools(['get_page', 'get_tool_group', 'add_block_to_page_area', 'list_pages', 'list_topic_trees', 'list_users']);
        $tag = '[Current page for this message: Topics, /topics, ID 1. Earlier messages may refer to other pages.]' . "\n\n";
        $messages = [new UserMessage($tag . 'Hello')];

        $names = static fn (array $tools): array => array_map(static fn (ToolInterface $t): string => $t->getName(), $tools);
        $this->assertSame(['get_page', 'get_tool_group', 'add_block_to_page_area', 'list_pages'], $names(GroupedChatNode::offered($registry, $messages)), 'the tag\'s "Topics" is not the user\'s word');

        $messages[] = new ToolCallMessage(null, [new ToolCall('get_tool_group', 'c1', ['group' => 'topics'])]);
        $messages[] = new ToolResultMessage([new ToolCall('get_tool_group', 'c1', ['group' => 'topics'])]);
        $this->assertContains('list_topic_trees', $names(GroupedChatNode::offered($registry, $messages)), 'offered from the next step');

        // The next user message starts again from its own words.
        $messages[] = new AssistantMessage('Done.');
        $messages[] = new UserMessage('Thanks');
        $this->assertNotContains('list_topic_trees', $names(GroupedChatNode::offered($registry, $messages)));
    }

    public function testEveryGroupFitsWithTheAlwaysToolsAndThePickStaysUnderTheCap(): void
    {
        // Offered sizes: the disabled tools are never offered. External: katalysis_pro's 7 and core People's 2.
        $sizes = ['always' => count(ToolGroups::ALWAYS), 'external' => 9];
        foreach (ToolGroups::GROUPS as $group => $tools) {
            $sizes[$group] = count(array_diff($tools, DisabledTools::names()));
        }
        foreach (ToolGroups::ORDER as $a) {
            $picked = GroupRouter::fit([$a, 'content', 'pages'], $sizes);
            $total = $sizes['always'] + array_sum(array_map(static fn (string $g): int => $sizes[$g], $picked));
            $this->assertLessThanOrEqual(ToolGroups::MAX_TOOLS, $total, "{$a}: " . implode(', ', $picked));
            $this->assertSame($a, $picked[0] ?? null, 'the best group always fits');
        }
    }

    /**
     * @return array<string, array{string, ?string, list<string>}>
     */
    public static function messageProvider(): array
    {
        return [
            'headings' => ['Change all the h3 headings on this page to h2.', null, ['content']],
            'a page move' => ['Move the page /neuron-test/move-me under /neuron-test/move-target.', null, ['pages']],
            'the meta title' => ['Set the meta title of this page to Spring.', null, ['attributes']],
            'a file' => ['Delete the file called report.pdf.', null, ['files']],
            'people' => ['List the people on the site.', null, ['external']],
            'a topic' => ['Create a topic called Events.', null, ['topics']],
            'a user' => ['Which user accounts are there?', null, ['users']],
            'quoted text is a value, not the request' => ["Set the meta title of this page to 'Headings Test Title'.", null, ['attributes']],
            'bios' => ['Who is on the site, and what do their bios say?', null, ['external']],
            'a follow-up keeps the last turn\'s groups' => ['and are there others?', 'List the pages directly under /neuron-test.', ['pages']],
        ];
    }

    /**
     * @param list<string> $expected the groups that must be picked, first the best
     *
     * @dataProvider messageProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('messageProvider')]
    public function testTheRouterPicksTheGroupsTheMessageNeeds(string $message, ?string $previous, array $expected): void
    {
        $groups = GroupRouter::route($message, $previous);

        foreach ($expected as $group) {
            $this->assertContains($group, $groups, $message);
        }
    }

    /**
     * @param list<string> $names
     * @return list<ToolInterface>
     */
    private static function tools(array $names): array
    {
        return array_map(static fn (string $name): ToolInterface => new CallableTool($name, $name), $names);
    }

    public function testQuotedTextAndApostrophesDoNotRouteButTheWordsAroundThemDo(): void
    {
        $this->assertNotContains('external', GroupRouter::route("Change the notice on this page to say 'The office is closed on Sundays.'", null));
        $this->assertNotContains('external', GroupRouter::route("Change the company on the testimonial to \u{201C}Library Friends\u{201D}", null), 'a testimonial is a core block');
        $this->assertContains('files', GroupRouter::route("Don't keep the file called 'Report'.", null), 'an apostrophe is not a quote');
    }

    public function testAMessageWithNoClueGetsContentAndPages(): void
    {
        $this->assertSame(['content', 'pages'], GroupRouter::route('Hello', null));
    }

    public function testTheGroupsComeOutInTheFixedOrderWhateverTheirScore(): void
    {
        // Attributes scores higher than content here; the offer is still in the fixed order.
        $groups = GroupRouter::route('Set the meta description attribute and the meta title attribute, and fix the heading.', null);

        $this->assertSame(array_values(array_intersect(ToolGroups::ORDER, $groups)), $groups);
    }
}
