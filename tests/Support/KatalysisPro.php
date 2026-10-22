<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use PHPUnit\Framework\AssertionFailedError;

/**
 * katalysis_pro's tools in tests (P2.8). The PHPUnit boot doesn't run package on_start(), so tests register
 * katalysis_pro the way its controller does: KatalysisProEntitiesToolkit::register().
 *
 * A mismatched pair fails, never skips (plan P2.8, D2): an installed katalysis_pro whose toolkit is the pre-P2.8 one
 * (it builds Neuron 3 tools), or whose tools are skipped, is a failure naming the reason. Only a site without
 * katalysis_pro at all skips.
 */
final class KatalysisPro
{
    public const TOOLKIT = 'Concrete\\Package\\KatalysisPro\\Src\\KatalysisPro\\Ai\\KatalysisProEntitiesToolkit';

    /** What the agent offers from katalysis_pro, in order (as in the v3 prompt capture). */
    public const OFFERED = ['list_people', 'get_person', 'resolve_file_id_from_path', 'list_places', 'get_place', 'list_reviews', 'get_review'];

    /** Defined by katalysis_pro and disabled by DisabledTools. */
    public const DISABLED = ['add_person', 'update_person', 'delete_person', 'delete_all_people'];

    public static function isInstalled(): bool
    {
        return class_exists(self::TOOLKIT);
    }

    /**
     * Registers katalysis_pro's tools in the registry as it stands (callers clear and restore the registry).
     */
    public static function register(): void
    {
        if (!method_exists(self::TOOLKIT, 'register')) {
            throw new AssertionFailedError(
                "katalysis_pro's toolkit is the pre-P2.8 one (no register(); it builds Neuron 3 tools). "
                . "Check out katalysis_pro's task/P2.8-tooldefinitions branch, or a version with it merged."
            );
        }

        (self::TOOLKIT)::register();

        $skipped = ExternalToolkitRegistry::build(static function (): void {
        }, static fn (): bool => true)->skipped();
        if ($skipped !== []) {
            throw new AssertionFailedError("katalysis_pro's tools were skipped: " . json_encode($skipped));
        }
    }
}
