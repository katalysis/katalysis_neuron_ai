<?php

/**
 * Claude Code PreToolUse hook for the Bash tool (WP-1).
 *
 * Run as: php "${CLAUDE_PROJECT_DIR}/.claude/hooks/guard-bash.php" (see .claude/settings.json).
 * Reads the hook input (JSON) on stdin. Exit 2 blocks the command and sends the reason on
 * stderr to Claude. Exit 0 with a permissionDecision of "ask" on stdout prompts the person,
 * even when an allow rule matches (WP-1b). Exit 0 with no output lets the normal permission
 * rules decide. It never approves, so it cannot weaken the rules.
 *
 * It catches what the deny rules' prefix and wildcard matching can't: git global options
 * before the subcommand, refspecs that name a protected branch, flags after the arguments,
 * the current branch on a plain "git push", and SQL inside -e or a heredoc.
 *
 * It does NOT fail safe. If PHP is missing or this script dies with an error, Claude Code
 * treats that as a non-blocking hook error and the command goes on to the permission rules.
 * The deny rules in .claude/settings.json are the backstop (CLAUDE.md, Working agreement).
 * Input it can't read is blocked.
 *
 * Needs only php and git. Tested by tests/Unit/BashGuardTest.php.
 */

declare(strict_types=1);

final class NeuronBashGuard
{
    private const PROTECTED_BRANCHES = ['main', 'development'];

    /** Branches under this prefix take changes only through PRs too (WP-1b). */
    private const PROTECTED_PREFIX = 'integration/';

    /** The only host curl may reach without a prompt (WP-1b). */
    private const CURL_HOST = 'katalysis-pro.test';

    /** curl flags that only change how the reply is shown. */
    private const CURL_FLAGS = ['--silent', '--show-error', '--head', '--include', '--location', '--insecure', '--fail', '--compressed', '--verbose'];

    /** curl flags whose value is harmless. */
    private const CURL_FLAGS_WITH_VALUE = ['-w', '--write-out', '-H', '--header', '-m', '--max-time', '--connect-timeout', '-A', '--user-agent'];

    /** Words that can come before a command without changing what runs. */
    private const COMMAND_PREFIXES = ['command', 'exec', 'env', 'sudo', 'time', 'nice', 'nohup', 'xargs'];

    /**
     * Returns why the command is blocked, or null if the guard has no objection.
     *
     * @param callable(): ?string $currentBranch the checked-out branch, asked for only when a push names none
     */
    public static function check(string $command, callable $currentBranch): ?string
    {
        $reason = self::checkText($command);
        if ($reason !== null) {
            return $reason;
        }
        foreach (self::segments($command) as $tokens) {
            $reason = self::checkGit($tokens, $currentBranch) ?? self::checkGh($tokens);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Returns why the person should be asked before the command runs, or null. Used only for
     * commands check() lets through.
     */
    public static function askReason(string $command): ?string
    {
        // Strip quotes and backslashes so run_"scen"arios and run\_scenarios are caught too.
        if (str_contains(strtolower(str_replace(['"', "'", '\\'], '', $command)), 'run_scenarios')) {
            return 'it runs the scenario runner, which calls the AI provider and costs money';
        }
        foreach (self::segments($command) as $tokens) {
            $reason = self::checkCurl($tokens) ?? self::checkPushElsewhere($tokens);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * A push run in another repository (git -C <path>, --git-dir, --work-tree) asks (P2.8): a bare push there goes to
     * that repository's current branch, which the guard can't see (it looks up the branch of the hook's cwd).
     *
     * @param list<array{string, bool}> $tokens
     */
    private static function checkPushElsewhere(array $tokens): ?string
    {
        $i = self::commandIndex($tokens, 'git');
        if ($i === null) {
            return null;
        }

        $n = count($tokens);
        $elsewhere = false;
        $i++;
        while ($i < $n && str_starts_with($tokens[$i][0], '-') && !$tokens[$i][1]) {
            $name = explode('=', $tokens[$i][0], 2)[0];
            if (in_array($name, ['-C', '--git-dir', '--work-tree'], true)) {
                $elsewhere = true;
            }
            if (in_array($tokens[$i][0], ['-c', '-C', '--git-dir', '--work-tree', '--namespace', '--config-env'], true)) {
                $i++;
            }
            $i++;
        }

        return $elsewhere && ($tokens[$i][0] ?? '') === 'push'
            ? 'it pushes from another repository (git -C, --git-dir or --work-tree), whose branch the guard cannot check'
            : null;
    }

    public static function main(string $input): int
    {
        $data = json_decode($input, true);
        if (!is_array($data)) {
            fwrite(STDERR, "Blocked by the project guard (.claude/hooks/guard-bash.php): it could not read the hook input.\n");

            return 2;
        }
        if (($data['tool_name'] ?? null) !== 'Bash') {
            return 0;
        }
        $command = $data['tool_input']['command'] ?? null;
        if (!is_string($command)) {
            fwrite(STDERR, "Blocked by the project guard (.claude/hooks/guard-bash.php): the hook input has no command.\n");

            return 2;
        }
        $cwd = is_string($data['cwd'] ?? null) ? $data['cwd'] : (string) getcwd();

        $reason = self::check($command, static fn (): ?string => self::gitBranch($cwd));
        if ($reason === null) {
            $ask = self::askReason($command);
            if ($ask !== null) {
                echo json_encode(['hookSpecificOutput' => [
                    'hookEventName' => 'PreToolUse',
                    'permissionDecision' => 'ask',
                    'permissionDecisionReason' => "Project guard (.claude/hooks/guard-bash.php): {$ask}.",
                ]]), "\n";
            }

            return 0;
        }
        fwrite(STDERR, "Blocked by the project guard (.claude/hooks/guard-bash.php): {$reason}. See CLAUDE.md, Working agreement.\n");

        return 2;
    }

    /** Checks on the raw text, so they also see quoted strings, -e arguments and heredoc bodies. */
    private static function checkText(string $command): ?string
    {
        if (preg_match('/--no-verify\b/', $command)) {
            return 'it skips the git hooks (--no-verify)';
        }
        if (preg_match('/\bdrop\s+(database|schema|table)\b/i', $command)) {
            return 'it drops a database, schema or table';
        }
        if (preg_match('/\btruncate\s+(table\s+)?[`\w]/i', $command)) {
            return 'it truncates a table';
        }
        if (preg_match('/\bmysqladmin\b/', $command)) {
            return 'mysqladmin can drop databases';
        }
        if (preg_match('/database\.php\b|generated_overrides/', $command)) {
            return 'it reads the Concrete config that holds the database password and API keys';
        }
        if (preg_match('/(^|[\s\'"=\/<>])\.env(\.[\w.-]+)?(?=$|[\s\'";|&)])/', $command)) {
            return 'it reads a .env file';
        }

        return null;
    }

    /**
     * @param list<array{string, bool}> $tokens
     * @param callable(): ?string $currentBranch
     */
    private static function checkGit(array $tokens, callable $currentBranch): ?string
    {
        $i = self::commandIndex($tokens, 'git');
        if ($i === null) {
            return null;
        }

        // Global options come before the subcommand: git -c x=y -C dir --git-dir=... push.
        $n = count($tokens);
        $i++;
        while ($i < $n && str_starts_with($tokens[$i][0], '-') && !$tokens[$i][1]) {
            if (in_array($tokens[$i][0], ['-c', '-C', '--git-dir', '--work-tree', '--namespace', '--config-env'], true)) {
                $i++;
            }
            $i++;
        }
        $subcommand = $tokens[$i][0] ?? '';
        $args = array_slice($tokens, $i + 1);

        // Reading it (git config [--get] core.hooksPath) is fine; anything else that names it is not.
        $readsHooksPath = $subcommand === 'config' && self::isConfigRead($args);
        foreach ($tokens as $j => [$value]) {
            if (stripos($value, 'core.hookspath') !== false && !($readsHooksPath && $j > $i)) {
                return 'it changes core.hooksPath, which turns off the git hooks';
            }
        }

        return match ($subcommand) {
            'push' => self::checkPush($args, $currentBranch),
            'commit' => self::checkCommit($args),
            default => null,
        };
    }

    /**
     * @param list<array{string, bool}> $args
     * @param callable(): ?string $currentBranch
     */
    private static function checkPush(array $args, callable $currentBranch): ?string
    {
        $positional = [];
        $n = count($args);
        for ($i = 0; $i < $n; $i++) {
            [$value, $quoted] = $args[$i];
            if ($quoted || !str_starts_with($value, '-') || $value === '-') {
                $positional[] = $value;
                continue;
            }
            if ($value === '--') {
                foreach (array_slice($args, $i + 1) as [$rest]) {
                    $positional[] = $rest;
                }
                break;
            }
            if (str_starts_with($value, '--')) {
                $name = explode('=', $value, 2)[0];
                if (in_array($name, ['--force', '--force-with-lease', '--force-if-includes'], true)) {
                    return 'it is a force push';
                }
                if (in_array($name, ['--all', '--mirror', '--branches'], true)) {
                    return "it pushes every branch ({$name})";
                }
                if (!str_contains($value, '=') && in_array($name, ['--push-option', '--repo', '--receive-pack', '--exec'], true)) {
                    $i++;
                }
                continue;
            }
            // A cluster of short flags, e.g. -uf.
            if (str_contains($value, 'f')) {
                return 'it is a force push (-f)';
            }
            if (str_ends_with($value, 'o')) {
                $i++;
            }
        }

        $refspecs = array_slice($positional, 1);
        if ($refspecs === []) {
            $branch = $currentBranch();
            if ($branch !== null && self::isProtected($branch)) {
                return "it pushes the current branch, {$branch}, which takes changes only through a PR";
            }

            return null;
        }
        foreach ($refspecs as $refspec) {
            if (str_starts_with($refspec, '+')) {
                return "it force-pushes the refspec {$refspec}";
            }
            $destination = str_contains($refspec, ':') ? explode(':', $refspec, 2)[1] : $refspec;
            if ($destination === 'HEAD' || $destination === '@') {
                $destination = (string) $currentBranch();
            }
            if (str_starts_with($destination, 'refs/heads/')) {
                $destination = substr($destination, strlen('refs/heads/'));
            }
            if (self::isProtected($destination)) {
                return "it pushes to or deletes {$destination}, which takes changes only through a PR";
            }
        }

        return null;
    }

    private static function isProtected(string $branch): bool
    {
        return in_array($branch, self::PROTECTED_BRANCHES, true) || str_starts_with($branch, self::PROTECTED_PREFIX);
    }

    /**
     * Whether "git config <args>" only reads one key: the key alone, or "get" and the key,
     * with no flag other than --get and the scope flags.
     *
     * @param list<array{string, bool}> $args
     */
    private static function isConfigRead(array $args): bool
    {
        $positional = [];
        foreach ($args as [$value, $quoted]) {
            if ($quoted || !str_starts_with($value, '-')) {
                $positional[] = $value;
            } elseif (!in_array($value, ['--get', '--local', '--global', '--system', '--worktree', '--show-origin', '--show-scope'], true)) {
                return false;
            }
        }
        if (($positional[0] ?? null) === 'get') {
            array_shift($positional);
        }

        return count($positional) === 1;
    }

    /**
     * Asks unless curl only reads from the dev site: every argument is a URL on CURL_HOST or
     * a flag that changes how the reply is shown, and output goes nowhere but /dev/null.
     *
     * @param list<array{string, bool}> $tokens
     */
    private static function checkCurl(array $tokens): ?string
    {
        $i = self::commandIndex($tokens, 'curl');
        if ($i === null) {
            return null;
        }
        $args = array_slice($tokens, $i + 1);
        $n = count($args);
        for ($j = 0; $j < $n; $j++) {
            [$value, $quoted] = $args[$j];
            $isFlag = !$quoted && str_starts_with($value, '-');
            if ($isFlag && (in_array($value, self::CURL_FLAGS, true) || preg_match('/^-[sSIiLkfv]+$/', $value))) {
                continue;
            }
            if ($isFlag && in_array($value, self::CURL_FLAGS_WITH_VALUE, true)) {
                $j++;
                continue;
            }
            if ($isFlag && in_array($value, ['-o', '--output'], true) && ($args[$j + 1][0] ?? '') === '/dev/null') {
                $j++;
                continue;
            }
            if (!$isFlag && preg_match('#^(https?://)?' . preg_quote(self::CURL_HOST, '#') . '(:\d+)?([/?\#].*)?$#i', $value)) {
                continue;
            }

            return 'curl may reach only ' . self::CURL_HOST . ", and only to read; it has \"{$value}\"";
        }

        return null;
    }

    /** @param list<array{string, bool}> $args */
    private static function checkCommit(array $args): ?string
    {
        $n = count($args);
        for ($i = 0; $i < $n; $i++) {
            [$value, $quoted] = $args[$i];
            if ($quoted || !preg_match('/^-[a-zA-Z]+$/', $value)) {
                continue;
            }
            // In -m, -F, -C, -c and -t the rest of the cluster (or the next word) is a value.
            $flags = preg_split('/[mFCct]/', substr($value, 1), 2)[0];
            if (str_contains($flags, 'n')) {
                return 'it skips the git hooks (git commit -n)';
            }
            if (strlen($flags) === strlen($value) - 2) {
                $i++;
            }
        }

        return null;
    }

    /** @param list<array{string, bool}> $tokens */
    private static function checkGh(array $tokens): ?string
    {
        $i = self::commandIndex($tokens, 'gh');
        if ($i === null) {
            return null;
        }
        $sub = $tokens[$i + 1][0] ?? '';
        $action = $tokens[$i + 2][0] ?? '';
        if ($sub === 'pr' && $action === 'merge') {
            return 'merging is done by a person on GitHub';
        }
        if ($sub === 'repo' && in_array($action, ['edit', 'delete', 'rename', 'archive', 'unarchive'], true)) {
            return "it changes the repository (gh repo {$action})";
        }
        if ($sub !== 'api') {
            return null;
        }

        $method = null;
        $sendsData = false;
        $args = array_slice($tokens, $i + 2);
        $n = count($args);
        for ($j = 0; $j < $n; $j++) {
            [$value, $quoted] = $args[$j];
            if ($quoted) {
                continue;
            }
            if ($value === '-X' || $value === '--method') {
                $method = $args[$j + 1][0] ?? '';
                $j++;
            } elseif (str_starts_with($value, '--method=')) {
                $method = substr($value, strlen('--method='));
            } elseif (preg_match('/^-X(\w+)$/', $value, $m)) {
                $method = $m[1];
            } elseif (preg_match('/^(-f|-F|--field|--raw-field|--input)(=|$)/', $value)) {
                $sendsData = true;
            }
        }
        if ($method !== null && strtoupper($method) !== 'GET') {
            return 'gh api with a method other than GET can change the repository';
        }
        if ($method === null && $sendsData) {
            return 'gh api with fields or --input sends a POST';
        }

        return null;
    }

    /**
     * The index of $program when it is the command in this segment (after any VAR=value
     * assignments and prefixes such as "command" or "sudo"), or null.
     *
     * @param list<array{string, bool}> $tokens
     */
    private static function commandIndex(array $tokens, string $program): ?int
    {
        foreach ($tokens as $i => [$value, $quoted]) {
            if (!$quoted && basename($value) === $program) {
                return $i;
            }
            if ($quoted || !(preg_match('/^\w+=/', $value) || in_array($value, self::COMMAND_PREFIXES, true))) {
                return null;
            }
        }

        return null;
    }

    /**
     * Splits a command line into simple commands at ; & | newlines, brackets and backticks,
     * outside quotes. Each token is [value without quotes, whether any of it was quoted].
     *
     * @return list<list<array{string, bool}>>
     */
    private static function segments(string $command): array
    {
        $segments = [];
        $tokens = [];
        $token = '';
        $quoted = false;
        $inToken = false;
        $quote = null;
        $length = strlen($command);

        $endToken = static function () use (&$tokens, &$token, &$quoted, &$inToken): void {
            if ($inToken) {
                $tokens[] = [$token, $quoted];
            }
            $token = '';
            $quoted = false;
            $inToken = false;
        };
        $endSegment = static function () use (&$segments, &$tokens, $endToken): void {
            $endToken();
            if ($tokens !== []) {
                $segments[] = $tokens;
            }
            $tokens = [];
        };

        for ($i = 0; $i < $length; $i++) {
            $c = $command[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                } elseif ($c === '\\' && $quote === '"' && $i + 1 < $length) {
                    $token .= $command[++$i];
                } else {
                    $token .= $c;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $quoted = true;
                $inToken = true;
            } elseif ($c === '\\' && $i + 1 < $length) {
                $token .= $command[++$i];
                $inToken = true;
            } elseif (str_contains(";&|\n()`", $c) || ($c === '$' && ($command[$i + 1] ?? '') === '(')) {
                $endSegment();
            } elseif ($c === ' ' || $c === "\t") {
                $endToken();
            } else {
                $token .= $c;
                $inToken = true;
            }
        }
        $endSegment();

        return $segments;
    }

    private static function gitBranch(string $cwd): ?string
    {
        $process = @proc_open(
            ['git', '-C', $cwd, 'symbolic-ref', '--short', '-q', 'HEAD'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }
        $branch = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $branch === '' ? null : $branch;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['argv'][0] ?? '')) === __FILE__) {
    exit(NeuronBashGuard::main((string) stream_get_contents(STDIN)));
}
