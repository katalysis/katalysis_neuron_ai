<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Chat\ChatRetention;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use PHPUnit\Framework\TestCase;

/**
 * P3.9-2 (plans/P3.9-2.md, B-PII-5): 90 days by default, 1 to 3650 on the settings page, aged by last activity; the
 * scheduled purge never takes a chat active in the last 10 minutes; the chat endpoint's fallback runs once a day.
 */
final class ChatRetentionTest extends TestCase
{
    public function testTheDefaultIs90DaysAndAnUnsetOrBadValueGetsIt(): void
    {
        $this->assertSame(90, ChatRetention::days(null));
        $this->assertSame(90, ChatRetention::days(''));
        $this->assertSame(90, ChatRetention::days('nonsense'));
        $this->assertSame(30, ChatRetention::days('30'));
    }

    public function testTheSettingsPageAcceptsOnly1To3650(): void
    {
        $this->assertSame([], ApiKeySettings::refusals(['chat_retention_days' => '90']));
        $this->assertSame([], ApiKeySettings::refusals(['chat_retention_days' => '']), 'blank is the default');
        foreach (['0', '3651', '-5', '30.5', 'forever'] as $bad) {
            $this->assertCount(1, ApiKeySettings::refusals(['chat_retention_days' => $bad]), $bad);
        }
    }

    public function testTheCutoffIsTheRetentionBeforeNowAndNeverWithinTenMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 12:00:00');

        $this->assertEquals(new \DateTimeImmutable('2026-08-01 12:00:00'), ChatRetention::cutoff(90, $now));
        $this->assertEquals(new \DateTimeImmutable('2026-10-29 12:00:00'), ChatRetention::cutoff(1, $now));
        $this->assertEquals(new \DateTimeImmutable('2026-10-30 11:50:00'), ChatRetention::cutoff(0, $now), 'the scheduled purge keeps the last 10 minutes');
    }

    public function testThePackageIsAtLeast360AndInstallsThePurgeTask(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controller.php');

        $this->assertSame(1, preg_match('/\$pkgVersion = \'([\d.]+)\'/', $source, $m));
        $this->assertTrue(version_compare($m[1], '3.6.0', '>='), 'upgrade adds idx_updated_date and the task');
        $this->assertStringContainsString('PurgeChatsTaskController', $source);
        $this->assertStringContainsString('installTask', $source);
    }

    public function testTheFallbackIsDueOnceADay(): void
    {
        $now = 1_800_000_000;

        $this->assertTrue(ChatRetention::fallbackDue(null, $now), 'never run');
        $this->assertFalse(ChatRetention::fallbackDue($now - 3600, $now));
        $this->assertTrue(ChatRetention::fallbackDue($now - 86400, $now));
    }
}
