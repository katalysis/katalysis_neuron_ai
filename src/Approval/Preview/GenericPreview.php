<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

/**
 * The card for a write that has no preview of its own (an external tool, or a new one; B-ACC-8): the tool and each
 * input, shortened. The fingerprint covers the inputs only, so nothing about the site is checked again at Approve.
 * ApprovalRegistryTest fails for any offered core write tool that ends up here.
 */
final class GenericPreview
{
    public static function of(string $tool, array $inputs): array
    {
        $details = [];
        foreach ($inputs as $name => $value) {
            $details[] = $name . ': ' . PreviewSupport::short(PreviewSupport::text($value), 120);
        }

        return [
            PreviewSupport::entry('Run ' . $tool, '', '', $details, 'This tool has no preview of its own: check the inputs above.'),
            ['tool' => $tool, 'inputs' => $inputs],
        ];
    }
}
