<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * P3.6-2: thrown by PageDraft::of() during an approval check's dry run (CallCheck): the call passed every check that
 * comes before its draft, and would now write. Nothing has been written.
 */
final class DryRunReached extends \RuntimeException
{
}
