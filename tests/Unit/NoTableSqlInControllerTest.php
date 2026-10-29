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

    public function testEveryModelTheInstallSavesIsOneTheSettingsPageAccepts(): void
    {
        // Found while doing P3.9-1: install saved anthropic_model 'claude-2', which P3.8-1's check refuses, so a fresh
        // install's settings form (which posts the saved value back) could never be saved.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controller.php');
        preg_match_all("/Config::save\\('katalysis\\.ai\\.(open_ai|anthropic|ollama)_model', '([^']*)'\\)/", $source, $m, PREG_SET_ORDER);

        $this->assertNotSame([], $m);
        foreach ($m as [, $field, $model]) {
            $this->assertSame([], \Katalysis\NeuronAi\Settings\ApiKeySettings::refusals([$field . '_model' => $model]), "{$field}_model '{$model}'");
        }
    }

    public function testThePackageVersionIsAtLeast350SoUpgradeRuns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controller.php');

        $this->assertSame(1, preg_match('/\$pkgVersion = \'([\d.]+)\'/', $source, $m));
        $this->assertTrue(version_compare($m[1], '3.5.0', '>='), $m[1]);
    }
}
