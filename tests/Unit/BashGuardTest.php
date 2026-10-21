<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PreToolUse guard for Claude Code's Bash tool (WP-1): .claude/hooks/guard-bash.php.
 *
 * The deny rules in .claude/settings.json match command prefixes and wildcards only, so the
 * guard is what catches the bypass forms: git global options before the subcommand, refspecs
 * that name a protected branch, flags after the arguments, and SQL inside -e or a heredoc.
 * It is not part of the package; it is loaded from the repo's .claude folder.
 */
final class BashGuardTest extends TestCase
{
    private const GUARD = __DIR__ . '/../../.claude/hooks/guard-bash.php';

    public static function setUpBeforeClass(): void
    {
        require_once self::GUARD;
    }

    /** @return array<string, array{string}> */
    public static function blockedCommands(): array
    {
        return [
            // Force pushes, in every position and spelling.
            'force first' => ['git push --force origin task/x'],
            'force last' => ['git push origin task/x --force'],
            'short f' => ['git push -f origin task/x'],
            'short f in a cluster' => ['git push -uf origin task/x'],
            'force with lease' => ['git push --force-with-lease origin task/x'],
            'force with lease value' => ['git push --force-with-lease=task/x:abc123 origin task/x'],
            'force if includes' => ['git push --force-if-includes origin task/x'],
            'plus refspec' => ['git push origin +task/x'],
            'plus main' => ['git push origin +main'],
            'plus refspec with destination' => ['git push origin +HEAD:task/x'],
            // Pushes to the protected branches.
            'main' => ['git push origin main'],
            'development' => ['git push origin development'],
            'HEAD to main' => ['git push origin HEAD:main'],
            'branch to full ref' => ['git push origin task/x:refs/heads/development'],
            'full ref to main' => ['git push origin refs/heads/task/x:refs/heads/main'],
            'all branches' => ['git push --all origin'],
            'mirror' => ['git push --mirror origin'],
            'upstream flag to main' => ['git push -u origin main'],
            // Refspecs the WP-1a allow rules (`git push origin task/*`, `spike/*`) would match.
            'task branch to main' => ['git push origin task/x:main'],
            'task branch to development' => ['git push origin task/x:development'],
            'spike branch to full main ref' => ['git push origin spike/x:refs/heads/main'],
            'spike branch to development' => ['git push origin spike/x:development'],
            'upstream task branch to main' => ['git push -u origin task/x:main'],
            'set-upstream spike branch to development' => ['git push --set-upstream origin spike/x:development'],
            'task branch then main' => ['git push origin task/x main'],
            'spike branch then development' => ['git push origin spike/x development'],
            'task branch then colon delete' => ['git push origin task/x :main'],
            // Deleting them.
            'colon delete' => ['git push origin :development'],
            'delete flag first' => ['git push --delete origin main'],
            'delete flag after remote' => ['git push origin --delete development'],
            'short delete' => ['git push -d origin main'],
            // git global options before the subcommand.
            'git -c then push' => ['git -c push.default=current push origin main'],
            'git -c then force' => ['git -c color.ui=never push --force origin task/x'],
            'git -C then push' => ['git -C ../other push origin main'],
            'git --git-dir then push' => ['git --git-dir=.git push origin development'],
            'git --no-pager then push' => ['git --no-pager push origin HEAD:main'],
            'full path git' => ['/usr/local/bin/git push origin main'],
            'command git' => ['command git push origin main'],
            'env prefix' => ['GIT_TRACE=1 git push origin main'],
            // Chained and nested.
            'after cd' => ['cd /tmp && git push origin main'],
            'after semicolon' => ['true; git push --force origin task/x'],
            'in a subshell' => ['(git push origin development)'],
            'in command substitution' => ['echo $(git push origin main)'],
            // Hooks bypass.
            'no-verify push' => ['git push --no-verify origin task/x'],
            'no-verify commit' => ['git commit --no-verify -m "x"'],
            'no-verify after message' => ['git commit -m "x" --no-verify'],
            'no-verify merge' => ['git merge --no-verify task/x'],
            'no-verify after git -c' => ['git -c user.name=x commit --no-verify -m x'],
            'no-verify anywhere' => ['FLAGS=--no-verify; git commit $FLAGS -m x'],
            'commit -n' => ['git commit -n -m "x"'],
            'commit -n in a cluster' => ['git commit -anm "x"'],
            'hooksPath via -c' => ['git -c core.hooksPath=/dev/null commit -m x'],
            'hooksPath via -c, any case' => ['git -c CORE.HOOKSPATH=/dev/null push origin task/x'],
            'config hooksPath' => ['git config core.hooksPath /dev/null'],
            'config unset hooksPath' => ['git config --unset core.hooksPath'],
            'config local hookspath' => ['git config --local core.hookspath x'],
            'config set hooksPath' => ['git config set core.hooksPath /dev/null'],
            'config add hooksPath' => ['git config --add core.hooksPath x'],
            'hooksPath via -c before a config read' => ['git -c core.hooksPath=/dev/null config core.hooksPath'],
            // The integration branches (WP-1b): PRs only, like development.
            'integration branch' => ['git push origin integration/neuron4'],
            'task branch to integration' => ['git push origin task/x:integration/neuron4'],
            'task branch to full integration ref' => ['git push origin task/x:refs/heads/integration/neuron4'],
            'delete integration' => ['git push origin --delete integration/neuron4'],
            'colon delete integration' => ['git push origin :integration/neuron4'],
            'plus integration' => ['git push origin +integration/neuron4'],
            // Destructive SQL, in any case and any wrapper.
            'drop database in -e' => ['mysql -uroot -e "DROP DATABASE prodb"'],
            'drop table lower case' => ["mysql prodb -e 'drop table Pages'"],
            'drop schema' => ['mysql -e "Drop Schema prodb"'],
            'drop in a heredoc' => ["mysql prodb <<'SQL'\nSELECT 1;\nDROP TABLE Pages;\nSQL"],
            'drop in a heredoc after other lines' => ["mysql -h127.0.0.1 -P3307 -uroot prodb <<EOF\nUSE prodb;\n  drop   table  if exists Logs;\nEOF"],
            'truncate table' => ['mysql prodb -e "TRUNCATE TABLE Logs"'],
            'truncate bare' => ['mysql prodb -e "truncate Logs"'],
            'drop in php' => ['php -r \'$db->executeStatement("DROP TABLE Pages");\''],
            'mysqladmin' => ['mysqladmin -uroot drop prodb'],
            // GitHub settings and merges.
            'pr merge' => ['gh pr merge 27 --squash'],
            'repo edit' => ['gh repo edit --visibility public'],
            'repo delete' => ['gh repo delete katalysis/katalysis_neuron_ai --yes'],
            'api PUT' => ['gh api -X PUT repos/katalysis/katalysis_neuron_ai/branches/main/protection'],
            'api method DELETE' => ['gh api --method DELETE repos/katalysis/katalysis_neuron_ai/git/refs/heads/development'],
            'api method attached' => ['gh api --method=PATCH repos/katalysis/katalysis_neuron_ai'],
            'api field means POST' => ['gh api repos/katalysis/katalysis_neuron_ai/pulls/27/merge -f merge_method=squash'],
            'api input means POST' => ['gh api repos/katalysis/katalysis_neuron_ai/pulls --input body.json'],
            // Secrets.
            'database config' => ['cat ../../application/config/database.php'],
            'API keys' => ['grep key ../../application/config/generated_overrides/katalysis.php'],
            'dotenv' => ['cat .env'],
            'dotenv variant' => ['source ./.env.local'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function allowedCommands(): array
    {
        return [
            'task branch' => ['git push origin task/WP-1-agent-tooling'],
            'task branch with upstream' => ['git push -u origin task/WP-1-agent-tooling'],
            'task branch named like main' => ['git push origin task/maintenance-fix'],
            'task branch named like development' => ['git push origin task/development-notes'],
            'HEAD to a task branch' => ['git push origin HEAD:task/x'],
            'log main' => ['git log --oneline main'],
            'diff development' => ['git diff development...HEAD'],
            'fetch main' => ['git fetch origin main'],
            'checkout main' => ['git checkout main'],
            'commit message with a dash n' => ['git commit -m "-n is not a flag here"'],
            'commit message about dropdowns' => ['git commit -m "Fix dropdown menu"'],
            'select dropdown' => ["mysql prodb -e \"SELECT cID FROM Pages WHERE cHandle = 'dropdown'\""],
            'pr view' => ['gh pr view 27'],
            'pr create' => ['gh pr create --base development --title x --body-file body.md'],
            'api read' => ['gh api repos/katalysis/katalysis_neuron_ai/pulls'],
            'api explicit GET' => ['gh api -X GET repos/katalysis/katalysis_neuron_ai'],
            'phpunit' => ['php tools/phpunit/vendor/bin/phpunit --exclude-group slow'],
            'docs' => ['cat documentation/SETUP.md'],
            'environment word' => ['grep -rn environment src'],
            // Reading core.hooksPath matches the deny rule, which blocks only setting it (WP-1b).
            'config read hooksPath' => ['git config core.hooksPath'],
            'config get hooksPath' => ['git config --get core.hooksPath'],
            'config get subcommand hooksPath' => ['git config get core.hooksPath'],
            'task branch named like integration' => ['git push origin task/integration-notes'],
            'fetch integration' => ['git fetch origin integration/neuron4'],
            'switch to integration' => ['git switch integration/neuron4'],
            'curl the dev site' => ['curl -sI https://katalysis-pro.test/'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function askedCommands(): array
    {
        return [
            // The scenario runner costs money, however it is written (WP-1b).
            'from the package folder' => ['php ../../concrete/bin/concrete c5:exec tests/Eval/run_scenarios.php -- --scenario=10'],
            'from httpdocs' => ['php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php'],
            'cd prefix' => ['cd ../.. && php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php -- --repeat=3'],
            'absolute path' => ['/usr/local/bin/php /Users/x/Herd/site/httpdocs/concrete/bin/concrete c5:exec /Users/x/Herd/site/httpdocs/packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php'],
            'heredoc' => ["sh <<'EOF'\nphp ../../concrete/bin/concrete c5:exec tests/Eval/run_scenarios.php\nEOF"],
            'quoted in pieces' => ['php ../../concrete/bin/concrete c5:exec tests/Eval/run_"scen"arios.php'],
            'escaped' => ['php ../../concrete/bin/concrete c5:exec tests/Eval/run\_scenarios.php'],
            // curl anywhere but the dev site, or sending or saving data (WP-1b).
            'curl another host' => ['curl https://example.com/'],
            'curl a host that starts like the dev site' => ['curl https://katalysis-pro.test.example.com/'],
            'curl userinfo trick' => ['curl https://katalysis-pro.test@example.com/'],
            'curl a second URL' => ['curl https://katalysis-pro.test/ https://example.com/'],
            'curl post' => ['curl -d @notes.txt https://katalysis-pro.test/'],
            'curl upload' => ['curl -T notes.txt https://katalysis-pro.test/'],
            'curl output to a file' => ['curl -o page.html https://katalysis-pro.test/'],
            'curl config file' => ['curl -K other.cfg https://katalysis-pro.test/'],
            'curl variable' => ['curl $URL'],
        ];
    }

    #[DataProvider('askedCommands')]
    public function testAsks(string $command): void
    {
        $this->assertNull(\NeuronBashGuard::check($command, static fn (): string => 'task/x'), $command);
        $this->assertNotNull(\NeuronBashGuard::askReason($command), $command);
    }

    /** @return array<string, array{string}> */
    public static function curlCommandsNotAsked(): array
    {
        return [
            'head' => ['curl -sI https://katalysis-pro.test/'],
            'http with a path' => ['curl -s http://katalysis-pro.test/index.php/dashboard?x=1'],
            'no scheme' => ['curl katalysis-pro.test'],
            'status code only' => ["curl -s -o /dev/null -w '%{http_code}' https://katalysis-pro.test/"],
            'header and timeout' => ['curl -sSL -k -H "Accept: application/json" --max-time 10 https://katalysis-pro.test/ccm/api/v1/system/info'],
            'piped' => ['curl -s https://katalysis-pro.test/ | grep -c title'],
        ];
    }

    #[DataProvider('curlCommandsNotAsked')]
    public function testDoesNotAskForCurlToTheDevSite(string $command): void
    {
        $this->assertNull(\NeuronBashGuard::askReason($command), $command);
    }

    public function testPlainPushIsBlockedOnAnIntegrationBranch(): void
    {
        $current = static fn (): string => 'integration/neuron4';
        $this->assertNotNull(\NeuronBashGuard::check('git push', $current));
        $this->assertNotNull(\NeuronBashGuard::check('git push origin HEAD', $current));
    }

    public function testHookAsksWithJsonForTheScenarioRunner(): void
    {
        [$code, , $stdout] = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => 'cd ../.. && php concrete/bin/concrete c5:exec packages/katalysis_neuron_ai/tests/Eval/run_scenarios.php'], 'cwd' => sys_get_temp_dir()]);
        $this->assertSame(0, $code);
        $output = json_decode($stdout, true);
        $this->assertSame('PreToolUse', $output['hookSpecificOutput']['hookEventName'] ?? null);
        $this->assertSame('ask', $output['hookSpecificOutput']['permissionDecision'] ?? null);
        $this->assertStringContainsString('scenario', $output['hookSpecificOutput']['permissionDecisionReason'] ?? '');
    }

    #[DataProvider('blockedCommands')]
    public function testBlocks(string $command): void
    {
        $this->assertNotNull(\NeuronBashGuard::check($command, static fn (): string => 'task/x'), $command);
    }

    #[DataProvider('allowedCommands')]
    public function testAllows(string $command): void
    {
        $this->assertNull(\NeuronBashGuard::check($command, static fn (): string => 'task/x'), $command);
    }

    public function testPlainPushIsBlockedOnAProtectedBranch(): void
    {
        foreach (['main', 'development'] as $branch) {
            $current = static fn (): string => $branch;
            $this->assertNotNull(\NeuronBashGuard::check('git push', $current), $branch);
            $this->assertNotNull(\NeuronBashGuard::check('git push origin', $current), $branch);
            $this->assertNotNull(\NeuronBashGuard::check('git push origin HEAD', $current), $branch);
            $this->assertNotNull(\NeuronBashGuard::check('git push -u origin @', $current), $branch);
        }
    }

    public function testPlainPushIsAllowedOnATaskBranch(): void
    {
        $current = static fn (): string => 'task/WP-1-agent-tooling';
        $this->assertNull(\NeuronBashGuard::check('git push', $current));
        $this->assertNull(\NeuronBashGuard::check('git push origin HEAD', $current));
    }

    public function testTheReasonNamesWhatWasBlocked(): void
    {
        $reason = \NeuronBashGuard::check('git push --force origin task/x', static fn (): string => 'task/x');
        $this->assertStringContainsStringIgnoringCase('force', (string) $reason);
    }

    public function testHookExitsTwoWithTheReasonOnStderrWhenBlocked(): void
    {
        [$code, $stderr] = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => 'git push origin +main'], 'cwd' => sys_get_temp_dir()]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('guard', $stderr);
    }

    public function testHookExitsZeroWhenAllowed(): void
    {
        [$code, , $stdout] = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => 'git status'], 'cwd' => sys_get_temp_dir()]);
        $this->assertSame(0, $code);
        $this->assertSame('', $stdout);
    }

    public function testHookIgnoresOtherTools(): void
    {
        [$code] = $this->runHook(['tool_name' => 'Read', 'tool_input' => ['file_path' => 'README.md']]);
        $this->assertSame(0, $code);
    }

    public function testHookBlocksInputItCannotRead(): void
    {
        [$code] = $this->runHook('not json');
        $this->assertSame(2, $code);
    }

    /**
     * @param array<string, mixed>|string $input
     * @return array{int, string, string}
     */
    private function runHook(array|string $input): array
    {
        $process = proc_open(
            [PHP_BINARY, self::GUARD],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], is_string($input) ? $input : (string) json_encode($input));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stderr, $stdout];
    }
}
