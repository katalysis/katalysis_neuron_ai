<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ToolListChecks;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * The registry test that CLAUDE.md rule 4 relies on for mechanical tool
 * conversions (P2.3). It also guards the P1.6 name-collision rule.
 *
 * Read-only. It builds the agent's tool list through the agent's own
 * getTools(), flattens the toolkits, and never calls the AI provider or uses
 * an API key. Building the list needs no page or user context.
 *
 * The duplicate-name and array-items checks live in Support\ToolListChecks,
 * which tests/Unit/ToolListChecksTest.php proves can fail.
 */
final class ToolRegistryTest extends ConcreteTestCase
{
    /** @var ToolInterface[]|null */
    private ?array $tools = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // So that the real katalysis_pro toolkit class can be loaded (P1.13).
        ConcreteBoot::boot()->setupPackageAutoloaders();
    }

    /**
     * @return ToolInterface[]
     */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $agent = new ConcreteCmsAgent();
        $tools = [];
        foreach ($agent->getTools() as $item) {
            if ($item instanceof ToolkitInterface) {
                foreach ($item->tools() as $tool) {
                    $tools[] = $tool;
                }
            } elseif ($item instanceof ToolInterface) {
                $tools[] = $item;
            }
        }

        return $this->tools = $tools;
    }

    public function testAgentOffersTools(): void
    {
        $this->assertNotEmpty($this->tools());
        foreach ($this->tools() as $tool) {
            $this->assertInstanceOf(ToolInterface::class, $tool);
        }
    }

    public function testEveryToolHasANameAndADescription(): void
    {
        $problems = [];
        foreach ($this->tools() as $i => $tool) {
            $name = trim($tool->getName());
            $label = $name !== '' ? $name : sprintf('#%d (%s)', $i, $tool::class);

            if ($name === '') {
                $problems[] = "{$label}: empty name";
            } elseif (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) !== 1) {
                // The model providers only accept this pattern for function names.
                $problems[] = "{$label}: name is not 1-64 characters of letters, digits, _ or -";
            }

            if (trim((string) $tool->getDescription()) === '') {
                $problems[] = "{$label}: empty description";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function testEveryToolHasAValidInputSchema(): void
    {
        $validTypes = array_map(static fn (PropertyType $t): string => $t->value, PropertyType::cases());
        $problems = [];

        foreach ($this->tools() as $tool) {
            $label = $tool->getName() !== '' ? $tool->getName() : $tool::class;
            $seen = [];

            foreach ($tool->getProperties() as $property) {
                if (!$property instanceof ToolPropertyInterface) {
                    $problems[] = "{$label}: a property is not a ToolPropertyInterface";
                    continue;
                }

                $propertyName = trim($property->getName());
                if ($propertyName === '') {
                    $problems[] = "{$label}: a property has an empty name";
                    continue;
                }
                if (isset($seen[$propertyName])) {
                    $problems[] = "{$label}: property \"{$propertyName}\" is defined twice";
                }
                $seen[$propertyName] = true;

                $schema = $property->getJsonSchema();
                $type = $schema['type'] ?? null;
                if (!is_string($type) || !in_array($type, $validTypes, true)) {
                    $problems[] = sprintf('%s: property "%s" has an invalid type', $label, $propertyName);
                }
                if (isset($schema['enum']) && (!is_array($schema['enum']) || $schema['enum'] === [])) {
                    $problems[] = "{$label}: property \"{$propertyName}\" has an empty enum";
                }
            }

            foreach ($tool->getRequiredProperties() as $required) {
                if (!isset($seen[$required])) {
                    $problems[] = "{$label}: required property \"{$required}\" is not defined";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Array properties declared as a bare PropertyType::ARRAY have no "items"
     * in their JSON schema, which some providers reject. These existing
     * properties are known and listed here (found in P1.3); to be fixed when the
     * tools are converted (P2.3/P3). Any other array property must declare its
     * items. Remove an entry once it is fixed; do not add new ones.
     * (P1.13: the five entries of tools that are now disabled were removed, since the agent no longer offers them.
     * If one of those tools is re-enabled, its array property must declare items first.)
     */
    private const ARRAYS_WITHOUT_ITEMS_TODAY = [
        'batch_update_page_content.blocks',
        'batch_update_content_headings.headingTexts',
        'create_page_attribute.options',
        'create_page_attribute_and_add_to_set.options',
        'create_user_attribute.options',
        'create_file_attribute.options',
    ];

    public function testArrayPropertiesDeclareItems(): void
    {
        $missing = ToolListChecks::arraysWithoutItems($this->tools());

        $expected = self::ARRAYS_WITHOUT_ITEMS_TODAY;
        sort($expected);

        $this->assertSame(
            $expected,
            $missing,
            'Array properties without "items" differ from the known list. New ones must declare items; fixed ones must be removed from ARRAYS_WITHOUT_ITEMS_TODAY.'
        );
    }

    public function testExternalToolkitsAreLoadedAndWinNameClashes(): void
    {
        // The PHPUnit boot doesn't run package on_start(), so katalysis_pro's own registration
        // isn't present here. Register a synthetic external toolkit the same way it does, run
        // the agent's real tools() path, and restore the registry afterwards.
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $original = $property->getValue();

        try {
            $property->setValue(null, []);
            ExternalToolkitRegistry::registerToolkitFactory('p16_test', static fn (): array => [
                new class () extends AbstractToolkit {
                    public function provide(): array
                    {
                        return [
                            new Tool('list_people', 'external list_people'),
                            new Tool('p16_external_only', 'only in the external toolkit'),
                            new Tool('delete_person', 'must be disabled'),
                        ];
                    }
                },
            ]);

            $tools = [];
            foreach ((new ConcreteCmsAgent())->getTools() as $item) {
                foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                    $tools[] = $tool;
                }
            }
        } finally {
            $property->setValue(null, $original);
        }

        $this->assertSame([], ToolListChecks::duplicateNames($tools));

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        $this->assertArrayHasKey('p16_external_only', $byName, 'External toolkit tools are not loaded.');
        $this->assertSame('external list_people', $byName['list_people']->getDescription(), 'The external tool must win the clash.');
        $this->assertArrayNotHasKey('delete_person', $byName, 'A disabled tool from an external toolkit was offered.');
        $this->assertArrayHasKey('get_person_details', $byName, 'The rest of the core PeopleToolkit must survive.');
    }

    public function testDisabledToolsAreNotOffered(): void
    {
        $names = array_map(static fn (ToolInterface $t): string => $t->getName(), $this->tools());

        $this->assertSame([], array_values(array_intersect($names, \Katalysis\NeuronAi\Tools\DisabledTools::names())));
    }

    public function testNoTwoToolsShareAName(): void
    {
        $duplicates = ToolListChecks::duplicateNames($this->tools());

        $this->assertSame([], $duplicates, 'Duplicate tool names: ' . implode(', ', $duplicates));
    }

    /** Tools P1.13 disabled because they change the live site outside a draft. Spelled out so that removing one from DisabledTools fails here. */
    private const LIVE_CHANGING_TOOLS = [
        'delete_page', 'move_page', 'move_file_to_folder',
        'update_page_select_attribute_options', 'update_user_select_attribute_options', 'update_file_select_attribute_options',
        'delete_topic', 'delete_attribute_set', 'update_person_specialisms', 'update_person_topics',
        'update_person', 'add_person',
    ];

    /** Defined by katalysis_pro's toolkit, not by this package. */
    private const KATALYSIS_PRO_TOOLS = ['add_person', 'update_person', 'delete_person', 'delete_all_people'];

    private const KATALYSIS_PRO_TOOLKIT = '\\Concrete\\Package\\KatalysisPro\\Src\\KatalysisPro\\Ai\\KatalysisProEntitiesToolkit';

    public function testTheLiveChangingToolsOfP113AreNotOffered(): void
    {
        $names = array_map(static fn (ToolInterface $t): string => $t->getName(), $this->tools());

        $this->assertSame([], array_values(array_intersect($names, self::LIVE_CHANGING_TOOLS)));
        $this->assertSame([], array_values(array_diff(self::LIVE_CHANGING_TOOLS, \Katalysis\NeuronAi\Tools\DisabledTools::names())), 'Each one is in DisabledTools');
    }

    public function testEveryDisabledToolIsDefinedByAToolkitTheAgentLoads(): void
    {
        // A stale entry or a typo would disable nothing and look safe. The toolkits are the ones ConcreteCmsAgent::tools() builds.
        $kits = [
            new \Katalysis\NeuronAi\Tools\PagesToolkit(), new \Katalysis\NeuronAi\Tools\BlocksToolkit(),
            new \Katalysis\NeuronAi\Tools\FilesToolkit(), new \Katalysis\NeuronAi\Tools\UsersToolkit(),
            new \Katalysis\NeuronAi\Tools\PageAttributesToolkit(), new \Katalysis\NeuronAi\Tools\UserAttributesToolkit(),
            new \Katalysis\NeuronAi\Tools\FileAttributesToolkit(), new \Katalysis\NeuronAi\Tools\AttributeSetsToolkit(),
            new \Katalysis\NeuronAi\Tools\TopicsToolkit(), new \Katalysis\NeuronAi\Tools\PeopleToolkit(),
            new \Katalysis\NeuronAi\Tools\VersionsToolkit(),
        ];
        $defined = [];
        foreach ($kits as $kit) {
            foreach ($kit->tools() as $tool) {
                $defined[$tool->getName()] = true;
            }
        }

        $undefined = array_values(array_filter(
            \Katalysis\NeuronAi\Tools\DisabledTools::names(),
            static fn (string $name): bool => !isset($defined[$name]) && !in_array($name, self::KATALYSIS_PRO_TOOLS, true)
        ));
        $this->assertSame([], $undefined, 'Disabled names that no toolkit defines: ' . implode(', ', $undefined));

        $toolkit = self::KATALYSIS_PRO_TOOLKIT;
        if (class_exists($toolkit)) {
            $proDefined = array_map(static fn (ToolInterface $t): string => $t->getName(), (new $toolkit())->tools());
            $this->assertSame([], array_values(array_diff(self::KATALYSIS_PRO_TOOLS, $proDefined)), 'katalysis_pro no longer defines a tool listed as its own');
        }
    }

    public function testDisabledToolsOfTheRealKatalysisProToolkitAreNotOffered(): void
    {
        $toolkit = self::KATALYSIS_PRO_TOOLKIT;
        if (!class_exists($toolkit)) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }

        // The PHPUnit boot doesn't run katalysis_pro's on_start(), so register its toolkit the way its controller does.
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $original = $property->getValue();

        try {
            $property->setValue(null, []);
            ExternalToolkitRegistry::registerToolkitFactory('p113_katalysis_pro', static fn (): array => [new $toolkit()]);
            $names = [];
            foreach ((new ConcreteCmsAgent())->getTools() as $item) {
                foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                    $names[] = $tool->getName();
                }
            }
        } finally {
            $property->setValue(null, $original);
        }

        $this->assertContains('list_places', $names, 'The rest of the katalysis_pro toolkit is offered.');
        $this->assertContains('list_people', $names);
        $this->assertSame([], array_values(array_intersect($names, self::KATALYSIS_PRO_TOOLS)), 'A disabled katalysis_pro tool was offered.');
    }
}
