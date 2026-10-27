<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * The scenario list in BEHAVIOUR_SPEC section 9 (WP-3). Adding a scenario adds a row there (tests/Scenarios/README.md,
 * "Adding a scenario", step 1), so ScenarioRunnerSupportTest compares the files with it instead of a fixed count.
 */
final class SpecScenarioTable
{
    /**
     * @return list<int> the scenario numbers in the section 9 table, sorted
     *
     * @throws \InvalidArgumentException when the spec has no section 9
     */
    public static function numbers(string $specMarkdown): array
    {
        if (preg_match('/^## 9\.[^\n]*\n(.*?)(?=^## |\z)/ms', $specMarkdown, $section) !== 1) {
            throw new \InvalidArgumentException('BEHAVIOUR_SPEC has no section 9 ("## 9. ...").');
        }

        preg_match_all('/^\| (\d+) \|/m', $section[1], $matches);
        $numbers = array_map('intval', $matches[1]);
        sort($numbers);

        return $numbers;
    }

    /**
     * The number a scenario ID starts with ("05-h3-to-h2" is 5), or null.
     */
    public static function numberOf(string $id): ?int
    {
        return preg_match('/^(\d+)-/', $id, $m) === 1 ? (int) $m[1] : null;
    }
}
