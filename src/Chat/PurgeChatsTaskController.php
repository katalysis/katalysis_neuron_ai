<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Runner\CommandTaskRunner;
use Concrete\Core\Command\Task\Runner\TaskRunnerInterface;
use Concrete\Core\Command\Task\TaskInterface;

/**
 * P3.9-2: the automated task "Purge old Neuron AI chats" (ChatRetention::TASK_HANDLE). Schedule it daily under
 * Dashboard → System → Automation → Tasks, or run `concrete/bin/concrete task:purge-old-neuron-a-i-chats` (Concrete names the command after the task).
 */
class PurgeChatsTaskController extends AbstractController
{
    public function getName(): string
    {
        return t('Purge old Neuron AI chats');
    }

    public function getDescription(): string
    {
        return t('Deletes Neuron AI chats older than the retention period in its settings (90 days by default), with their messages, approvals and log lines.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new CommandTaskRunner($task, new PurgeChatsCommand(), t('Old Neuron AI chats purged.'));
    }
}
