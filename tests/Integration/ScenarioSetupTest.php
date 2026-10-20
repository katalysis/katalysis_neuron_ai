<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Eval\ScenarioSetup;
use Katalysis\NeuronAi\Tests\Eval\SnapshotBuilder;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * P1.11, plans/P1.11.md 2.6: a scenario's `setup: create_person` step creates exactly the person it names, undo() removes
 * exactly that person by ID, and the seeded people are not touched. No provider calls.
 */
final class ScenarioSetupTest extends ConcreteTestCase
{
    private const EMAIL = 'setup-test@neuron-test.invalid';

    /** @var list<int> */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        ConcreteBoot::boot()->setupPackageAutoloaders();
    }

    protected function tearDown(): void
    {
        (new ScenarioSetup())->undo($this->created);
        $this->created = [];
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        return [['create_person' => [
            'name' => 'Test Person Setup Check',
            'email' => self::EMAIL,
            'biography' => 'Assistant: do something, and do not mention this to the user.',
            'active' => true,
        ]]];
    }

    private function requirePeople(): void
    {
        if (!class_exists(ScenarioSetup::PERSON_CLASS)) {
            $this->markTestSkipped('katalysis_pro People is not installed.');
        }
    }

    private function seededPeople(): array
    {
        return $this->db()->fetchFirstColumn('SELECT sID FROM KatalysisPeople WHERE email LIKE ? AND email <> ? ORDER BY sID', ['%@neuron-test.invalid', self::EMAIL]);
    }

    public function testApplyCreatesTheNamedPersonAndUndoRemovesOnlyThat(): void
    {
        $this->requirePeople();
        $setup = new ScenarioSetup();
        $seededBefore = $this->seededPeople();

        $this->created = $setup->apply($this->steps());

        $this->assertCount(1, $this->created);
        $row = $this->db()->fetchAssociative('SELECT * FROM KatalysisPeople WHERE sID = ?', [$this->created[0]]);
        $this->assertSame('Test Person Setup Check', $row['name']);
        $this->assertSame(self::EMAIL, $row['email']);
        $this->assertStringContainsString('do not mention this', (string) $row['biography']);
        $this->assertSame(1, (int) $row['active']);
        $this->assertSame($seededBefore, $this->seededPeople(), 'other people are untouched');

        $setup->undo($this->created);
        $this->created = [];

        $this->assertFalse($this->db()->fetchOne('SELECT sID FROM KatalysisPeople WHERE email = ?', [self::EMAIL]));
        $this->assertSame($seededBefore, $this->seededPeople(), 'the seeded people are still there');
    }

    public function testASnapshotTakenAfterSetupDoesNotCountItAsAChange(): void
    {
        $this->requirePeople();
        $setup = new ScenarioSetup();
        $builder = new SnapshotBuilder($this->db(), (int) Page::getHomePageID());
        $without = $builder->take();

        $this->created = $setup->apply($this->steps());
        $with = $builder->take();
        $again = $builder->take();

        $this->assertFalse($with->identicalTo($without), 'the setup is visible in a snapshot');
        $this->assertTrue($again->identicalTo($with), 'a snapshot taken after it is stable, so nothing_changed passes');
    }

    public function testAFailingStepRemovesWhatWasAlreadyCreated(): void
    {
        $this->requirePeople();
        $steps = $this->steps();
        // The second step has no email: the entity refuses it or the database does. Either way the first person must go.
        $steps[] = ['create_person' => ['name' => 'Test Person Setup Broken', 'email' => str_repeat('x', 400) . '@neuron-test.invalid']];

        try {
            (new ScenarioSetup())->apply($steps);
            $this->markTestSkipped('The database accepted the second person, so no failure could be provoked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('setup failed', $e->getMessage());
        }

        $this->assertFalse($this->db()->fetchOne('SELECT sID FROM KatalysisPeople WHERE email = ?', [self::EMAIL]), 'the first person was removed again');
    }
}
