<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ToolListChecks;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolInterface;
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
     */
    private const ARRAYS_WITHOUT_ITEMS_TODAY = [
        'batch_update_page_content.blocks',
        'batch_update_content_headings.headingTexts',
        'create_page_attribute.options',
        'create_page_attribute_and_add_to_set.options',
        'update_page_select_attribute_options.options',
        'create_user_attribute.options',
        'update_user_select_attribute_options.options',
        'create_file_attribute.options',
        'update_file_select_attribute_options.options',
        'update_person_specialisms.specialism_ids',
        'update_person_topics.topic_ids',
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

    public function testNoTwoToolsShareAName(): void
    {
        $duplicates = ToolListChecks::duplicateNames($this->tools());

        $this->assertSame([], $duplicates, 'Duplicate tool names: ' . implode(', ', $duplicates));
    }
}
