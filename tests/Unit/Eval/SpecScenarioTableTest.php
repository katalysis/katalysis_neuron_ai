<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\SpecScenarioTable;
use PHPUnit\Framework\TestCase;

/**
 * WP-3: the scenario numbers in BEHAVIOUR_SPEC section 9, which ScenarioRunnerSupportTest compares with the scenario
 * files instead of a fixed count (BF-12 had to change 24 to 25).
 */
final class SpecScenarioTableTest extends TestCase
{
    private const SPEC = <<<'MD'
        ## 8. Something else

        | # | Not a scenario |
        |---|---|
        | 7 | ignored: another section |

        ## 9. Mapping the P1.2 scenarios to the rules

        The scenarios are in `tests/Scenarios/`.

        | # | Scenario | Tier | Rules checked |
        |---|---|---|---|
        | 1 | "What page am I on?" | any | B-CTX-1 |
        | 2 | "How many content blocks?" | 1 | B-TRU-4 |
        | 25 | People with photo links | 1 | B-TRU-9 |

        **Rules no scenario checks yet:**
        - B-ACC-1: not a scenario.

        ## 10. Decisions

        | # | Decision |
        |---|---|
        | 3 | ignored: another section |
        MD;

    public function testTheNumbersAreTheRowsOfSectionNineOnly(): void
    {
        $this->assertSame([1, 2, 25], SpecScenarioTable::numbers(self::SPEC));
    }

    public function testAFileIdGivesItsNumber(): void
    {
        $this->assertSame(5, SpecScenarioTable::numberOf('05-h3-to-h2'));
        $this->assertSame(25, SpecScenarioTable::numberOf('25-people-photo-links'));
        $this->assertNull(SpecScenarioTable::numberOf('people'));
    }

    public function testNoSectionNineIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SpecScenarioTable::numbers("## 8. Other\n\n| 1 | x |\n");
    }
}
