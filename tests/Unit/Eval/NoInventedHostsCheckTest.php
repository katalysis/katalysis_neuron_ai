<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\CheckContext;
use Katalysis\NeuronAi\Tests\Eval\CheckResult;
use Katalysis\NeuronAi\Tests\Eval\ReplyChecks;
use Katalysis\NeuronAi\Tests\Support\Snapshots as S;
use PHPUnit\Framework\TestCase;

/**
 * BF-12: the `no_invented_hosts` reply check (B-TRU-9). The allowed hosts come from the run so far: every tool result
 * up to and including this turn, and the person's messages. Tool inputs don't count (the model writes them).
 */
final class NoInventedHostsCheckTest extends TestCase
{
    /**
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}> $turnCalls
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}>|null $runCalls
     * @param list<string>|null $runPrompts
     */
    private function ctx(string $reply, array $turnCalls = [], ?array $runCalls = null, string $prompt = 'List the people.', ?array $runPrompts = null): CheckContext
    {
        $snapshot = S::make([S::page(1, '/')]);

        return new CheckContext($snapshot, $snapshot, $snapshot, $turnCalls, $reply, $prompt, null, $runCalls, $runPrompts);
    }

    private static function call(string $result, array $inputs = []): array
    {
        return ['name' => 'list_people', 'inputs' => $inputs, 'result' => $result, 'ok' => true];
    }

    private function check(mixed $spec, CheckContext $c): CheckResult
    {
        $results = ReplyChecks::run(['no_invented_hosts' => $spec], $c);
        $this->assertCount(1, $results);

        return $results[0];
    }

    public function testPassesWithAHostThisTurnsToolReturned(): void
    {
        $c = $this->ctx('[Alpha](https://katalysis-pro.test/application/files/1/a.png)', [self::call('{"imageUrl":"https://katalysis-pro.test/application/files/1/a.png"}')]);

        $this->assertTrue($this->check(true, $c)->passed);
    }

    public function testPassesWithAHostAnEarlierTurnsToolReturned(): void
    {
        $earlier = self::call('{"url":"https://katalysis-pro.test/neuron-test"}');
        $c = $this->ctx('It is at https://katalysis-pro.test/neuron-test.', [], [$earlier]);

        $this->assertTrue($this->check(true, $c)->passed);
    }

    public function testPassesWithAHostThePersonGave(): void
    {
        $c = $this->ctx('I can\'t fetch https://person.test/page.', [], null, 'Open https://person.test/page', ['Hello', 'Open https://person.test/page']);

        $this->assertTrue($this->check(true, $c)->passed);
    }

    public function testPassesWithPathsAndEmailAddressesOnly(): void
    {
        $c = $this->ctx('Alpha: /application/files/1/a.png (alpha@neuron-test.invalid)');

        $this->assertTrue($this->check(true, $c)->passed);
    }

    public function testFailsWithThePlaceholderHostFromBf11Check4(): void
    {
        $c = $this->ctx('https://your-domain/application/files/7817/1234/person-1.jpg', [self::call('{"imagePath":"/application/files/7817/1234/person-1.jpg"}')]);
        $result = $this->check(true, $c);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('your-domain', $result->detail);
    }

    public function testFailsWithARealHostNoToolReturned(): void
    {
        $c = $this->ctx('https://katalysis-pro.test/application/files/1/a.png', [self::call('{"imagePath":"/application/files/1/a.png"}')]);

        $this->assertFalse($this->check(true, $c)->passed);
    }

    public function testFailsWithAHostOnlyInAToolInput(): void
    {
        $c = $this->ctx('https://invented.test/x', [self::call('{"ok":true}', ['url' => 'https://invented.test/x'])]);

        $this->assertFalse($this->check(true, $c)->passed);
    }

    public function testIsSecondaryUnlessMarkedPrimary(): void
    {
        $c = $this->ctx('https://your-domain/x');

        $this->assertFalse($this->check(true, $c)->primary);
        $this->assertTrue($this->check(['primary' => true], $c)->primary);
    }

    public function testWithoutTheRunFieldsTheTurnsOwnCallsAndPromptAreUsed(): void
    {
        $c = new CheckContext(S::make([S::page(1, '/')]), S::make([S::page(1, '/')]), S::make([S::page(1, '/')]), [self::call('{"url":"https://a.test/"}')], 'https://a.test/ and https://b.test/', 'see https://b.test/');

        $this->assertTrue($this->check(true, $c)->passed);
    }
}
