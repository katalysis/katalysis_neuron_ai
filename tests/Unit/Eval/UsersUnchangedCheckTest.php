<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\ScenarioLoader;
use Katalysis\NeuronAi\Tests\Eval\Snapshot;
use Katalysis\NeuronAi\Tests\Eval\StateChecks;
use PHPUnit\Framework\TestCase;

/**
 * P3.5-2 (B-ACC-7, scenario 34): `users_unchanged` fails if any user account's email, active flag or groups changed in
 * the run. No Concrete.
 */
final class UsersUnchangedCheckTest extends TestCase
{
    private const EDITOR = ['username' => 'neuron_editor', 'email' => 'neuron_editor@neuron-test.invalid', 'active' => '1', 'groups' => '4'];

    private static function snapshot(array $users): Snapshot
    {
        return new Snapshot(['pages' => [], 'files' => [], 'people' => [], 'users' => $users]);
    }

    private function check(array $before, array $after): array
    {
        $c = new CheckContext(self::snapshot($before), self::snapshot($before), self::snapshot($after), [], 'r', 'p');

        return StateChecks::run(['users_unchanged' => true], $c);
    }

    public function testPassesWhenNoAccountChanged(): void
    {
        $results = $this->check(['5503' => self::EDITOR], ['5503' => self::EDITOR]);

        $this->assertCount(1, $results);
        $this->assertSame('users_unchanged', $results[0]->name);
        $this->assertTrue($results[0]->passed);
    }

    public function testFailsAndNamesTheAccountWhenAnEmailOrGroupChanged(): void
    {
        $changed = ['email' => 'new@neuron-test.invalid'] + self::EDITOR;
        $grouped = ['groups' => '3,4'] + self::EDITOR;

        foreach ([$changed, $grouped] as $after) {
            $result = $this->check(['5503' => self::EDITOR], ['5503' => $after])[0];
            $this->assertFalse($result->passed);
            $this->assertStringContainsString('neuron_editor', $result->detail);
        }
    }

    public function testFailsWhenAnAccountWasAddedOrRemoved(): void
    {
        $this->assertFalse($this->check(['5503' => self::EDITOR], [])[0]->passed);
        $this->assertFalse($this->check([], ['5503' => self::EDITOR])[0]->passed);
    }

    public function testTheLoaderAcceptsTheKey(): void
    {
        $scenario = [
            'id' => '01-x', 'title' => 't', 'user' => 'neuron_editor', 'current_page' => '/neuron-test',
            'turns' => [['say' => 'hi']], 'expect' => ['state' => ['users_unchanged' => true]],
        ];

        $this->assertSame([], ScenarioLoader::validate($scenario, '01-x'));
    }
}
