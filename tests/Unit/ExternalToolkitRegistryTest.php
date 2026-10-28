<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Extension\Tier;
use Katalysis\NeuronAi\Extension\ToolDefinition;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Katalysis\NeuronAi\Tools\FilteredToolkit;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

/**
 * P2.2 (P2.1 findings 5.4): a failing external toolkit is skipped and logged, permanently, so one package can't break
 * every chat turn. Each toolkit's tools are built once per build(), inside the try. P2.8: the same for a package's
 * ToolDefinitions (registerToolDefinitions()), plus the login check. No Concrete, no database.
 */
final class ExternalToolkitRegistryTest extends TestCase
{
    /** @var array<string, callable> */
    private array $savedFactories = [];

    /** @var list<array{string, string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $property->getValue();
        $property->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
    }

    private function logger(): \Closure
    {
        return function (string $level, string $message): void {
            $this->logged[] = [$level, $message];
        };
    }

    /**
     * A toolkit that counts how often it builds its tools.
     */
    private static function goodToolkit(\stdClass $counter): ToolkitInterface
    {
        return new class ($counter) extends AbstractToolkit {
            public function __construct(private \stdClass $counter)
            {
            }

            public function guidelines(): ?string
            {
                return 'Use get_review for reviews.';
            }

            public function provide(): array
            {
                $this->counter->builds++;

                return [new CallableTool('get_review', 'A review.', static fn (): array => ['ok' => true])];
            }
        };
    }

    private static function throwingToolkit(): ToolkitInterface
    {
        return new class () extends AbstractToolkit {
            public function provide(): array
            {
                throw new \Error('Cannot instantiate abstract class NeuronAI\Tools\Tool; key sk-proj-abcdefghijklmnop');
            }
        };
    }

    public function testAToolkitThatThrowsIsSkippedAndTheOthersAreOffered(): void
    {
        $counter = (object) ['builds' => 0];
        ExternalToolkitRegistry::registerToolkitFactory('broken_package', static fn (): array => [self::throwingToolkit()]);
        ExternalToolkitRegistry::registerToolkitFactory('good_package', static fn (): array => [self::goodToolkit($counter)]);

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertCount(1, $build->toolkits());
        $this->assertSame(['get_review'], array_map(static fn ($tool): string => $tool->getName(), $build->toolkits()[0]->tools()));
        $this->assertSame('Use get_review for reviews.', $build->toolkits()[0]->guidelines(), 'the guidelines are unchanged');

        $this->assertCount(1, $build->skipped());
        $skip = $build->skipped()[0];
        $this->assertSame('broken_package', $skip['provider']);
        $this->assertNotNull($skip['toolkit']);
        $this->assertStringContainsString('Cannot instantiate abstract class', $skip['reason']);

        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0][0]);
        $this->assertStringContainsString('"broken_package" skipped', $this->logged[0][1]);
    }

    public function testAFactoryThatThrowsIsSkipped(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('broken_factory', static function (): array {
            throw new \RuntimeException('factory failed');
        });

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertSame([], $build->toolkits());
        $this->assertSame([['provider' => 'broken_factory', 'toolkit' => null, 'reason' => 'factory failed']], $build->skipped());
        $this->assertStringContainsString('provider "broken_factory" failed', $this->logged[0][1]);
    }

    public function testTheReasonAndTheLogLineAreRedacted(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('broken_package', static fn (): array => [self::throwingToolkit()]);

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $build->skipped()[0]['reason']);
        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $this->logged[0][1]);
    }

    public function testEachToolkitBuildsItsToolsOncePerBuild(): void
    {
        $counter = (object) ['builds' => 0];
        ExternalToolkitRegistry::registerToolkitFactory('good_package', static fn (): array => [self::goodToolkit($counter)]);

        $build = ExternalToolkitRegistry::build($this->logger());
        $first = $build->toolkits()[0]->tools();
        $again = $build->toolkits()[0]->tools();

        $this->assertSame(1, $counter->builds, 'tools() of the original toolkit is called once');
        $this->assertSame($first[0], $again[0], 'the same tool objects are offered every time');

        ExternalToolkitRegistry::build($this->logger());
        $this->assertSame(2, $counter->builds, 'a new build (a new agent) builds again: nothing is kept between agents');
    }

    public function testAFactoryThatReturnsNoArrayOffersNothingAndIsNotASkip(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('odd_package', static fn () => 'not an array');

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertSame([], $build->toolkits());
        $this->assertSame([], $build->skipped());
    }

    // P2.8: the ToolDefinition contract.

    private static function definitions(\stdClass $counter): \Closure
    {
        return static function () use ($counter): array {
            $counter->builds++;

            return [
                ToolDefinition::make('get_review', 'A review.')
                    ->input('reviewID', ToolDefinition::INTEGER, 'The review', required: true)
                    ->handler(static fn (array $in): array => ['ok' => true, 'review' => $in['reviewID']]),
                ToolDefinition::make('list_reviews', 'Reviews.')->handler(static fn (array $in): array => ['a', 'b']),
            ];
        };
    }

    public function testToolDefinitionsAreOfferedAsOneToolkitWithTheirGuidelinesAndLabel(): void
    {
        $counter = (object) ['builds' => 0];
        ExternalToolkitRegistry::registerToolDefinitions('reviews_package', self::definitions($counter), 'Use get_review for one review.', 'ReviewsToolkit');

        $build = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true);

        $this->assertSame([], $build->skipped());
        $this->assertCount(1, $build->toolkits());
        $toolkit = $build->toolkits()[0];
        $this->assertInstanceOf(FilteredToolkit::class, $toolkit);
        $this->assertSame(['get_review', 'list_reviews'], array_map(static fn ($tool): string => $tool->getName(), $toolkit->tools()));
        $this->assertContainsOnlyInstancesOf(CallableTool::class, $toolkit->tools());
        $this->assertSame('Use get_review for one review.', $toolkit->guidelines(), 'the guidelines are offered as they are');
        $this->assertSame('ReviewsToolkit', $toolkit->guidelinesHeading(), 'the label heads the guidelines');

        $toolkit->tools();
        $this->assertSame(1, $counter->builds, 'the factory runs once per build');
    }

    public function testTheLabelDefaultsToTheKey(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('reviews_package', self::definitions((object) ['builds' => 0]));

        $toolkit = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->toolkits()[0];

        $this->assertSame('reviews_package', $toolkit->guidelinesHeading());
        $this->assertNull($toolkit->guidelines());
    }

    public function testTheFirstRegistrationForAKeyWinsAcrossBothKinds(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('pkg', self::definitions((object) ['builds' => 0]), null, 'First');
        ExternalToolkitRegistry::registerToolkitFactory('pkg', static fn (): array => [self::throwingToolkit()]);
        ExternalToolkitRegistry::registerToolDefinitions('pkg', static fn (): array => [], null, 'Third');

        $build = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true);

        $this->assertSame([], $build->skipped());
        $this->assertSame('First', $build->toolkits()[0]->guidelinesHeading());
    }

    public function testToolkitsAndDefinitionsKeepTheOrderTheyWereRegisteredIn(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('first', self::definitions((object) ['builds' => 0]), 'g', 'First');
        ExternalToolkitRegistry::registerToolkitFactory('second', static fn (): array => [self::goodToolkit((object) ['builds' => 0])]);

        $kits = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->toolkits();

        $this->assertSame(['get_review', 'list_reviews'], array_map(static fn ($t): string => $t->getName(), $kits[0]->tools()));
        $this->assertSame('Use get_review for reviews.', $kits[1]->guidelines());
    }

    public function testADefinitionsFactoryThatThrowsIsSkippedRedactedAndLogged(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('broken_defs', static function (): array {
            throw new \RuntimeException('no database; key sk-proj-abcdefghijklmnop');
        }, null, 'BrokenToolkit');
        ExternalToolkitRegistry::registerToolkitFactory('good_package', static fn (): array => [self::goodToolkit((object) ['builds' => 0])]);

        $build = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true);

        $this->assertCount(1, $build->toolkits(), 'the other package is still offered');
        $this->assertCount(1, $build->skipped());
        $this->assertSame('broken_defs', $build->skipped()[0]['provider']);
        $this->assertSame('BrokenToolkit', $build->skipped()[0]['toolkit']);
        $this->assertStringContainsString('no database', $build->skipped()[0]['reason']);
        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $build->skipped()[0]['reason']);
        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $this->logged[0][1]);
        $this->assertSame('warning', $this->logged[0][0]);
    }

    /**
     * @return array<string, array{\Closure(): mixed, string}>
     */
    public static function notDefinitions(): array
    {
        return [
            'a Neuron tool' => [static fn (): array => [new CallableTool('get_x', 'x')], CallableTool::class],
            'a string' => [static fn (): array => ['get_x'], 'string'],
            'not a list' => [static fn (): string => 'get_x', 'list of ToolDefinitions'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notDefinitions')]
    public function testAFactoryThatReturnsAnythingButToolDefinitionsIsSkipped(\Closure $factory, string $named): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('odd_defs', $factory);

        $build = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true);

        $this->assertSame([], $build->toolkits());
        $this->assertCount(1, $build->skipped());
        $this->assertStringContainsString($named, $build->skipped()[0]['reason']);
    }

    /**
     * P3.5-2 (B-SEC-1, from P2.8): katalysis_pro's guarded() returns a caught exception's raw message as
     * {ok: false, error}, which never passed SecretRedactor. The core's wrapper redacts every external result.
     */
    public function testAnExternalToolsErrorResultIsRedacted(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('leaky_package', static fn (): array => [
            ToolDefinition::make('get_leak', 'Leaks.')->handler(static fn (array $in): array => [
                'ok' => false,
                'error' => 'HTTP 401: Incorrect API key provided: sk-proj-abcdefghijklmnop',
                'nested' => ['detail' => 'Bearer abcdefghijklmnopqrstuvwxyz'],
            ]),
        ]);
        $tool = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->toolkits()[0]->tools()[0];

        $tool->setInputs([]);
        $tool->execute();
        $result = (string) $tool->getResult();

        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $result);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $result);
        $this->assertStringContainsString('[redacted]', $result);
        $this->assertStringContainsString('Incorrect API key provided', $result, 'the rest of the message stays');
    }

    public function testAnExternalToolsExceptionIsRedacted(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('throwing_package', static fn (): array => [
            ToolDefinition::make('get_throw', 'Throws.')->handler(static function (array $in): array {
                throw new \RuntimeException('provider said: sk-proj-abcdefghijklmnop is invalid');
            }),
        ]);
        $tool = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->toolkits()[0]->tools()[0];

        $tool->setInputs([]);
        try {
            $tool->execute();
            $this->fail('the exception was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $e->getMessage());
            $this->assertStringContainsString('is invalid', $e->getMessage());
        }
    }

    public function testADefinitionToolRunsOnlyForARegisteredUser(): void
    {
        $registered = false;
        ExternalToolkitRegistry::registerToolDefinitions('reviews_package', self::definitions((object) ['builds' => 0]));
        $tool = ExternalToolkitRegistry::build($this->logger(), static function () use (&$registered): bool {
            return $registered;
        })->toolkits()[0]->tools()[0];

        $tool->setInputs(['reviewID' => 4]);
        try {
            $tool->execute();
            $this->fail('a guest ran the tool');
        } catch (\RuntimeException $e) {
            $this->assertSame('Authentication required: Please log in to use this tool.', $e->getMessage());
        }

        $registered = true;
        $tool->execute();
        $this->assertSame('{"ok":true,"review":4}', $tool->getResult());
    }

    // P3.2-1 (B-ACC-8): every external tool's tier, declared or by default.

    public function testADeclaredTierIsRecorded(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('tiered', static fn (): array => [
            ToolDefinition::make('create_review_type', 'Defines a review type.')->tier(Tier::CONFIGURE)->writes()
                ->handler(static fn (array $in): array => []),
            ToolDefinition::make('update_review', 'Edits a review.')->tier(Tier::CONTENT)->writes()
                ->handler(static fn (array $in): array => []),
        ]);

        $tiers = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->tiers();

        $this->assertSame(['create_review_type' => Tier::CONFIGURE, 'update_review' => Tier::CONTENT], $tiers);
    }

    public function testAnUndeclaredWriteNeedsTierThreeAndAnUndeclaredReadIsTierOne(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('untiered', static fn (): array => [
            ToolDefinition::make('get_review', 'A review.')->handler(static fn (array $in): array => []),
            ToolDefinition::make('add_review', 'Adds a review.')->writes()->handler(static fn (array $in): array => []),
            // Not marked writes(), but its name says it writes: treated as a write.
            ToolDefinition::make('delete_review', 'Deletes a review.')->handler(static fn (array $in): array => []),
        ]);

        $tiers = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->tiers();

        $this->assertSame(Tier::CONTENT, $tiers['get_review']);
        $this->assertSame(Tier::GENERATE, $tiers['add_review']);
        $this->assertSame(Tier::GENERATE, $tiers['delete_review']);
    }

    public function testAnOlderToolkitsToolsGetTheSameDefaults(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('older', static fn (): array => [new class () extends AbstractToolkit {
            public function provide(): array
            {
                return [new CallableTool('get_review', 'A review.'), new CallableTool('remove_review', 'Removes one.')];
            }
        }]);

        $tiers = ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->tiers();

        $this->assertSame(['get_review' => Tier::CONTENT, 'remove_review' => Tier::GENERATE], $tiers);
    }

    public function testASkippedProviderRecordsNoTiers(): void
    {
        ExternalToolkitRegistry::registerToolDefinitions('broken_defs', static function (): array {
            throw new \RuntimeException('no database');
        });

        $this->assertSame([], ExternalToolkitRegistry::build($this->logger(), static fn (): bool => true)->tiers());
    }
}
