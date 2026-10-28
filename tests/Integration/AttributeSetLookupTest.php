<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Attribute\Key\FileKey;
use Concrete\Core\Attribute\Key\UserKey;
use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-15: the page, user and file attribute list and detail tools name each attribute's set. They called
 * getAttributeSet(), which Concrete 9.5.5's attribute keys don't have (Call to undefined method
 * PageKey::getAttributeSet(); found in P3.2-1's scenario 35 run), so every call failed. A key's sets come from
 * getAttributeSets(). Read-only; no AI provider call.
 */
final class AttributeSetLookupTest extends ConcreteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, class-string}>
     */
    public static function categories(): array
    {
        return [
            'page' => ['list_page_attributes', 'get_page_attribute_details', CollectionKey::class],
            'user' => ['list_user_attributes', 'get_user_attribute_details', UserKey::class],
            'file' => ['list_file_attributes', 'get_file_attribute_details', FileKey::class],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $tool, array $inputs): array
    {
        $output = ToolByName::run($tool, $inputs);
        $this->assertTrue(ToolKind::succeeded($output), "{$tool}: {$output}");
        $data = json_decode($output, true);
        $this->assertIsArray($data, $output);

        return $data;
    }

    /** The name of the key's first set, as Concrete itself has it, or null. */
    private static function setNameOf(object $ak): ?string
    {
        $sets = $ak->getAttributeSets();

        return $sets === [] ? null : (string) reset($sets)->getAttributeSetName();
    }

    #[DataProvider('categories')]
    public function testTheListNamesEachAttributesSet(string $list, string $details, string $keyClass): void
    {
        $keys = $keyClass::getList();
        if ($keys === []) {
            $this->markTestSkipped("No {$keyClass} keys on this site.");
        }

        $attributes = $this->call($list, [])['attributes'];

        $this->assertCount(count($keys), $attributes);
        foreach ($keys as $ak) {
            $row = current(array_filter($attributes, static fn (array $a): bool => $a['handle'] === $ak->getAttributeKeyHandle()));
            $this->assertIsArray($row, $ak->getAttributeKeyHandle() . ' is not listed');
            $this->assertSame(self::setNameOf($ak), $row['set'], $ak->getAttributeKeyHandle());
        }
    }

    #[DataProvider('categories')]
    public function testTheDetailsNameTheAttributesSet(string $list, string $details, string $keyClass): void
    {
        $keys = $keyClass::getList();
        if ($keys === []) {
            $this->markTestSkipped("No {$keyClass} keys on this site.");
        }
        // A key in a set if there is one, so the set's handle and name are checked too.
        $inSet = array_values(array_filter($keys, static fn ($ak): bool => $ak->getAttributeSets() !== []));
        $ak = $inSet[0] ?? $keys[0];

        $result = $this->call($details, ['handle' => $ak->getAttributeKeyHandle()]);

        $sets = $ak->getAttributeSets();
        if ($sets === []) {
            $this->assertNull($result['set']);
        } else {
            $set = reset($sets);
            $this->assertSame(['handle' => $set->getAttributeSetHandle(), 'name' => $set->getAttributeSetName()], $result['set']);
        }
    }
}
