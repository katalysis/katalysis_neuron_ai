<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\DatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/neuron-dbguard-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/production', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*.php') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir . '/production');
        rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function write(string $relative, array $connection): void
    {
        file_put_contents(
            $this->dir . '/' . $relative,
            '<?php return ' . var_export(['connections' => ['concrete' => $connection]], true) . ';'
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function localConnections(): iterable
    {
        yield '127.0.0.1' => [['server' => '127.0.0.1']];
        yield 'localhost' => [['server' => 'localhost']];
        yield 'localhost, any case' => [['server' => 'LocalHost']];
        yield 'ipv6 loopback' => [['server' => '::1']];
        yield 'socket' => [['unix_socket' => '/tmp/mysql.sock']];
        yield 'socket with a remote server set' => [['server' => 'db.example.com', 'unix_socket' => '/tmp/mysql.sock']];
    }

    /**
     * @param array<string, mixed> $connection
     */
    #[DataProvider('localConnections')]
    public function testLocalConnectionsAreAccepted(array $connection): void
    {
        $this->assertNull(DatabaseGuard::findProblemInConfig(['connections' => ['concrete' => $connection]]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function nonLocalConnections(): iterable
    {
        yield 'remote host' => [['server' => 'db.example.com']];
        yield 'remote ip' => [['server' => '10.0.0.5']];
        yield 'lookalike' => [['server' => 'localhost.example.com']];
        yield 'no server and no socket' => [['database' => 'x']];
        yield 'empty server' => [['server' => '']];
    }

    /**
     * @param array<string, mixed> $connection
     */
    #[DataProvider('nonLocalConnections')]
    public function testNonLocalConnectionsAreRefused(array $connection): void
    {
        $this->assertNotNull(DatabaseGuard::findProblemInConfig(['connections' => ['concrete' => $connection]]));
    }

    public function testASecondRemoteConnectionIsRefused(): void
    {
        $problem = DatabaseGuard::findProblemInConfig(['connections' => [
            'concrete' => ['server' => '127.0.0.1'],
            'reports' => ['server' => 'reports.example.com'],
        ]]);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('reports', $problem);
    }

    public function testALocalConfigFilePasses(): void
    {
        $this->write('database.php', ['server' => '127.0.0.1']);

        $this->assertNull(DatabaseGuard::findProblem($this->dir));
    }

    public function testARemoteConfigFileIsRefused(): void
    {
        $this->write('database.php', ['server' => 'db.example.com']);

        $this->assertStringContainsString('db.example.com', (string) DatabaseGuard::findProblem($this->dir));
    }

    public function testAnEnvironmentFolderOverrideIsAlsoChecked(): void
    {
        $this->write('database.php', ['server' => '127.0.0.1']);
        $this->write('production/database.php', ['server' => 'prod-db.example.com']);

        $problem = (string) DatabaseGuard::findProblem($this->dir);

        $this->assertStringContainsString('production', $problem);
        $this->assertStringContainsString('prod-db.example.com', $problem);
    }

    public function testAMissingConfigFileIsRefused(): void
    {
        $this->assertNotNull(DatabaseGuard::findProblem($this->dir));
    }
}
