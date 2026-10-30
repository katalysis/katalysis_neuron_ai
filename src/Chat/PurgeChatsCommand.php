<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Foundation\Command\Command;

/**
 * P3.9-2: purge the chats older than the retention setting (PurgeChatsCommandHandler). The automated task runs it.
 */
class PurgeChatsCommand extends Command
{
}
