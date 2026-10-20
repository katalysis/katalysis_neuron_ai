<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\ScenarioLoader;
use Katalysis\NeuronAi\Tests\Eval\ScenarioSetup;
use PHPUnit\Framework\TestCase;

/**
 * Parsing and validating a scenario's `setup:` list (plans/P1.11.md 2.6). Applying it is tested in
 * tests/Integration/ScenarioSetupTest.php.
 */
final class ScenarioSetupValidationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function person(array $over = []): array
    {
        return array_merge(['name' => 'Test Person Injected', 'email' => 'injected@neuron-test.invalid', 'biography' => 'Hello', 'active' => true], $over);
    }

    public function testAValidSetupPasses(): void
    {
        $this->assertSame([], ScenarioSetup::validate([['create_person' => $this->person()]]));
        $this->assertSame([], ScenarioSetup::validate([['create_person' => ['name' => 'Test Person X', 'email' => 'x@neuron-test.invalid']]]));
    }

    public function testAnUnknownStepTypeIsRefused(): void
    {
        $errors = implode("\n", ScenarioSetup::validate([['delete_page' => ['path' => '/']]]));

        $this->assertStringContainsString('unknown step type "delete_page"', $errors);
    }

    public function testAPersonThatTheResetWouldNotRemoveIsRefused(): void
    {
        $this->assertStringContainsString('name must start with', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['name' => 'Jane Smith'])]])));
        $this->assertStringContainsString('name must start with', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['name' => 'Test Person '])]])));
        $this->assertStringContainsString('email must end with', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['email' => 'jane@example.com'])]])));
        $this->assertStringContainsString('email must end with', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['email' => '@neuron-test.invalid'])]])));
        $this->assertStringContainsString('name must start with', implode("\n", ScenarioSetup::validate([['create_person' => ['email' => 'a@neuron-test.invalid']]])));
    }

    public function testUnknownKeysAndWrongShapesAreRefused(): void
    {
        $this->assertStringContainsString('unknown key "phone"', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['phone' => '1'])]])));
        $this->assertStringContainsString('active must be true or false', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['active' => 'yes'])]])));
        $this->assertStringContainsString('biography must be a string', implode("\n", ScenarioSetup::validate([['create_person' => $this->person(['biography' => ['x']])]])));
        $this->assertNotSame([], ScenarioSetup::validate([]));
        $this->assertNotSame([], ScenarioSetup::validate('create_person'));
        $this->assertNotSame([], ScenarioSetup::validate(['create_person' => $this->person()]), 'a mapping is not a list of steps');
        $this->assertNotSame([], ScenarioSetup::validate([['create_person' => $this->person(), 'extra' => 1]]));
    }

    public function testTheLoaderValidatesSetupAndKnowsTheNoticeChecks(): void
    {
        $base = ['id' => '01-x', 'title' => 'T', 'user' => 'neuron_editor', 'current_page' => '/neuron-test', 'turns' => [['say' => 'hello']]];

        $ok = $base + ['setup' => [['create_person' => $this->person()]], 'expect' => ['tools' => ['notice_shown' => true, 'notice_absent' => true]]];
        $this->assertSame([], ScenarioLoader::validate($ok, '01-x'));

        $bad = $base + ['setup' => [['create_person' => $this->person(['name' => 'Someone Real'])]]];
        $this->assertStringContainsString('name must start with', implode("\n", ScenarioLoader::validate($bad, '01-x')));
    }
}
