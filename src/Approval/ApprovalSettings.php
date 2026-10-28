<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Concrete\Core\Support\Facade\Config;

/**
 * The approval's settings (P3.6-2). One named setting so far: how many blocks one change on a page may touch before
 * its card warns about the number (B-CON-4, BEHAVIOUR_SPEC §10 open question 1). The card always lists every block.
 */
final class ApprovalSettings
{
    public const BLOCK_LIMIT_KEY = 'katalysis.ai.approval.block_limit';
    public const DEFAULT_BLOCK_LIMIT = 10;

    public static function blockLimit(): int
    {
        $limit = Config::get(self::BLOCK_LIMIT_KEY, self::DEFAULT_BLOCK_LIMIT);

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : self::DEFAULT_BLOCK_LIMIT;
    }

    /** The warning a change gets when it touches more blocks than the limit, or null. */
    public static function blockWarning(int $count): ?string
    {
        $limit = self::blockLimit();

        return $count > $limit ? "This changes {$count} blocks, more than {$limit}." : null;
    }
}
