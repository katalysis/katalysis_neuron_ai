<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3.9-1: the chat table is Doctrine's alone (the Chat entity). The package controller ran a CREATE TABLE of its own on
 * every request, whose columns and indexes disagreed with the entity.
 */
final class NoTableSqlInControllerTest extends TestCase
{
    public function testThePackageControllerCreatesNoTableItself(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controller.php');

        $this->assertStringNotContainsStringIgnoringCase('CREATE TABLE', $source);
        $this->assertStringNotContainsString('createDatabaseTables', $source);
    }

    public function testThePackageVersionIsAtLeast350SoUpgradeRuns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controller.php');

        $this->assertSame(1, preg_match('/\$pkgVersion = \'([\d.]+)\'/', $source, $m));
        $this->assertTrue(version_compare($m[1], '3.5.0', '>='), $m[1]);
    }
}
