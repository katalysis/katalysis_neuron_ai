<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

/**
 * Another process holds the fixture lock (FixtureLock). The message says what holds it and since when.
 */
final class FixtureLockHeld extends \RuntimeException
{
}
