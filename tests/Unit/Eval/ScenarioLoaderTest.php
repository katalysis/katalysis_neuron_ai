<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\ScenarioLoader;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the scenario loader: validation against the README's vocabulary, and filtering.
 * Reading the real YAML files is tested in tests/Integration/ScenarioFilesTest.php.
 */
final class ScenarioLoaderTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function scenario(array $over = []): array
    {
        return array_merge([
            'id' => '01-x',
            'title' => 'T',
            'user' => 'neuron_editor',
            'current_page' => '/neuron-test',
            'turns' => [['say' => 'hello']],
        ], $over);
    }

    public function testAMinimalScenarioIsValid(): void
    {
        $this->assertSame([], ScenarioLoader::validate($this->scenario(), '01-x'));
    }

    public function testAFullScenarioIsValid(): void
    {
        $data = $this->scenario([
            'tier' => 1, 'rules' => ['B-X-1'], 'known_to_fail_today' => 'because', 'rubric' => 'r',
            'turns' => [['say' => 'a', 'approval' => 'approve', 'current_page' => '/x', 'expect' => ['tools' => ['called_any' => ['a']]]]],
            'expect' => [
                'state' => [
                    'nothing_changed' => true,
                    'blocks' => [['page' => '/p', 'area' => 'Column 1', 'unchanged' => true]],
                    'changed_pages' => ['exactly' => [['children_of' => '/p']]],
                ],
                'tools' => ['no_write_tool_succeeded' => true],
                'reply' => ['matches_all' => ['primary' => true, 'patterns' => ['a/b']], 'not_matches' => ['x'], 'shorter_than_prompt' => true],
            ],
        ]);

        $this->assertSame([], ScenarioLoader::validate($data, '01-x'));
    }

    public function testATypoInACheckNameIsReported(): void
    {
        $errors = ScenarioLoader::validate($this->scenario(['expect' => ['state' => ['nothing_chnged' => true]]]), '01-x');

        $this->assertStringContainsString('unknown check "nothing_chnged"', implode("\n", $errors));
    }

    public function testUnknownKeysAtEveryLevelAreReported(): void
    {
        $this->assertStringContainsString('unknown key "colour"', implode("\n", ScenarioLoader::validate($this->scenario(['colour' => 'red']), '01-x')));

        $text = implode("\n", ScenarioLoader::validate($this->scenario([
            'turns' => [['say' => 'a', 'wait' => 3, 'expect' => ['mind' => [], 'tools' => ['called' => ['a']], 'reply' => ['contains' => ['a']]]]],
            'expect' => ['state' => ['blocks' => [['page' => '/p', 'fnord' => 1]]]],
        ]), '01-x'));

        foreach (['unknown key "wait"', 'unknown expect group "mind"', 'unknown check "called"', 'unknown check "contains"', 'unknown check "fnord"'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function testRequiredKeysIdAndUserAreChecked(): void
    {
        $this->assertStringContainsString('missing key "turns"', implode("\n", ScenarioLoader::validate(['id' => 'a', 'title' => 't', 'user' => 'neuron_editor', 'current_page' => '/'], 'a')));
        $this->assertStringContainsString('does not match the file name', implode("\n", ScenarioLoader::validate($this->scenario(['id' => '02-y']), '01-x')));
        $this->assertStringContainsString('user must be one of', implode("\n", ScenarioLoader::validate($this->scenario(['user' => 'admin']), '01-x')));
        $this->assertStringContainsString('current_page must be a path', implode("\n", ScenarioLoader::validate($this->scenario(['current_page' => 'neuron-test']), '01-x')));
        $this->assertStringContainsString('say must be a non-empty string', implode("\n", ScenarioLoader::validate($this->scenario(['turns' => [['say' => '']]]), '01-x')));
        $this->assertStringContainsString('approval must be', implode("\n", ScenarioLoader::validate($this->scenario(['turns' => [['say' => 'a', 'approval' => 'maybe']]]), '01-x')));
        $this->assertNotSame([], ScenarioLoader::validate('not a mapping', '01-x'));
    }

    public function testAnInvalidRegularExpressionIsReported(): void
    {
        $errors = ScenarioLoader::validate($this->scenario(['expect' => ['reply' => ['matches_all' => ['(unclosed']]]]), '01-x');

        $this->assertStringContainsString('not a valid regular expression', implode("\n", $errors));
    }

    public function testFilterSelectsByIdOrNumber(): void
    {
        $all = ['01-a' => ['id' => '01-a'], '05-h3' => ['id' => '05-h3'], '15-fail' => ['id' => '15-fail']];

        $this->assertSame(['01-a', '05-h3', '15-fail'], array_keys(ScenarioLoader::filter($all, null)));
        $this->assertSame(['05-h3'], array_keys(ScenarioLoader::filter($all, '05')));
        $this->assertSame(['05-h3'], array_keys(ScenarioLoader::filter($all, '05-h3')));
        $this->assertSame(['01-a', '15-fail'], array_keys(ScenarioLoader::filter($all, '01,15')));
        $this->assertSame([], ScenarioLoader::filter($all, '5'), 'a number must match in full');
        $this->assertSame([], ScenarioLoader::filter($all, '99'));
    }
}
