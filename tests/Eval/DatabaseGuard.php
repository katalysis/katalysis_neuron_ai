<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * Refuses to run against a database that is not on this machine (P1.4).
 *
 * It reads the site's database config files as plain PHP arrays and never
 * connects. Every connection in every config file that Concrete could load
 * (application/config/database.php, environment folders such as
 * application/config/<env>/database.php, and generated_overrides) must use a
 * local host or a unix socket.
 */
final class DatabaseGuard
{
    public const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @param string $applicationConfigDir e.g. .../httpdocs/application/config
     *
     * @return string|null a message saying why the run must not go ahead, or null when every config is local
     */
    public static function findProblem(string $applicationConfigDir): ?string
    {
        $main = $applicationConfigDir . '/database.php';
        if (!is_file($main)) {
            return "Cannot find {$main}, so the database host can't be checked.";
        }

        $files = array_merge([$main], glob($applicationConfigDir . '/*/database.php') ?: []);
        foreach ($files as $file) {
            $config = (static function (string $file) {
                return include $file;
            })($file);

            if (!is_array($config)) {
                return "{$file} does not return an array, so the database host can't be checked.";
            }

            $problem = self::findProblemInConfig($config);
            if ($problem !== null) {
                return "{$file}: {$problem}";
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config the array returned by a database.php file
     */
    public static function findProblemInConfig(array $config): ?string
    {
        $connections = $config['connections'] ?? [];
        if (!is_array($connections)) {
            return 'connections is not an array.';
        }

        foreach ($connections as $name => $connection) {
            if (!is_array($connection)) {
                return "connection \"{$name}\" is not an array.";
            }

            $socket = trim((string) ($connection['unix_socket'] ?? ''));
            if ($socket !== '') {
                continue;
            }

            $server = strtolower(trim((string) ($connection['server'] ?? '')));
            if ($server === '') {
                return "connection \"{$name}\" has no server or unix_socket, so it can't be shown to be local.";
            }
            if (!in_array($server, self::LOCAL_HOSTS, true)) {
                return "connection \"{$name}\" uses the non-local host \"{$server}\".";
            }
        }

        return null;
    }
}
