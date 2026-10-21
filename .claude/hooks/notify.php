<?php

/**
 * Claude Code Notification and Stop hook (WP-1): a desktop notification when Claude is
 * waiting for you (a permission prompt, or idle for input) or has finished a turn.
 *
 * Run as: php "${CLAUDE_PROJECT_DIR}/.claude/hooks/notify.php" (see .claude/settings.json).
 * Uses osascript (part of macOS); if that isn't there, it rings the terminal bell.
 * Set NEURON_NOTIFY=off (e.g. in .claude/settings.local.json "env") to turn it off.
 * It never blocks anything: it always exits 0.
 */

declare(strict_types=1);

if (getenv('NEURON_NOTIFY') === 'off') {
    exit(0);
}

$data = json_decode((string) stream_get_contents(STDIN), true);
$data = is_array($data) ? $data : [];
$folder = basename((string) ($data['cwd'] ?? getcwd()));

if (($data['hook_event_name'] ?? '') === 'Notification') {
    $title = (string) ($data['title'] ?? 'Claude Code is waiting');
    $message = (string) ($data['message'] ?? 'Claude Code needs your input');
} else {
    $title = 'Claude Code finished';
    $message = "The turn in {$folder} has finished";
}

$quote = static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
$script = 'display notification ' . $quote($message) . ' with title ' . $quote($title) . ' subtitle ' . $quote($folder);

$process = is_executable('/usr/bin/osascript')
    ? @proc_open(['/usr/bin/osascript', '-e', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes)
    : false;
if (is_resource($process)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
} else {
    fwrite(STDERR, "\x07");
}

exit(0);
