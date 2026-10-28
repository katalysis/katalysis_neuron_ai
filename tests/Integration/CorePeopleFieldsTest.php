<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.5-2 (B-PII-2): the core people read tools (PeopleToolkit, used where katalysis_pro's own tools don't replace them)
 * return a person's public profile by default, and contact details and the full biography only when the request asks
 * for them (`fields`). katalysis_pro's own list_people and get_person follow in P5.0. Reads the seed's test people.
 */
final class CorePeopleFieldsTest extends ConcreteTestCase
{
    private const CONTACT = ['email', 'phone', 'mobile', 'linkedin', 'biography'];

    protected function setUp(): void
    {
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $tool, array $inputs): array
    {
        $output = ThrowawayPages::runTool(new PeopleToolkit(), $tool, $inputs);
        $this->assertTrue(ToolKind::succeeded($output), "{$tool}: {$output}");

        return json_decode($output, true);
    }

    private function alpha(array $people): array
    {
        foreach ($people as $person) {
            if (($person['name'] ?? '') === 'Test Person Alpha') {
                return $person;
            }
        }
        $this->fail('Test Person Alpha is not listed: run the seed.');
    }

    public function testListPeopleLeavesOutContactDetailsUnlessAskedFor(): void
    {
        $person = $this->alpha($this->call('list_people', ['limit' => 100])['people']);
        foreach (self::CONTACT as $field) {
            $this->assertArrayNotHasKey($field, $person, "{$field} by default");
        }
        foreach (['sID', 'name', 'jobTitle', 'shortBiography', 'imageUrl', 'specialisms', 'topics', 'places'] as $field) {
            $this->assertArrayHasKey($field, $person, $field);
        }

        $person = $this->alpha($this->call('list_people', ['limit' => 100, 'fields' => ['email', 'biography']])['people']);
        $this->assertStringEndsWith('@neuron-test.invalid', (string) $person['email']);
        $this->assertArrayHasKey('biography', $person);
        $this->assertArrayNotHasKey('phone', $person, 'only what was asked for');
    }

    public function testPersonDetailsLeaveOutContactDetailsUnlessAskedFor(): void
    {
        $person = $this->call('get_person_details', ['name' => 'Test Person Alpha'])['person'];
        foreach (self::CONTACT as $field) {
            $this->assertArrayNotHasKey($field, $person, "{$field} by default");
        }
        $this->assertArrayHasKey('qualification', $person);

        $person = $this->call('get_person_details', ['name' => 'Test Person Alpha', 'fields' => ['phone', 'linkedin']])['person'];
        $this->assertArrayHasKey('phone', $person);
        $this->assertArrayHasKey('linkedin', $person);
        $this->assertArrayNotHasKey('email', $person);
    }
}
