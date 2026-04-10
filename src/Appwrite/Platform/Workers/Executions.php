<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution as ExecutionMessage;
use Exception;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Executions extends Action
{
    /**
     * Keep skipping execution upserts for this internal project.
     * The HTTP execution flow applies the same exclusion separately.
     */
    private const string EXCLUDED_PROJECT_ID = '6862e6a6000cce69f9da';

    public static function getName(): string
    {
        return 'executions';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Executions worker')
            ->groups(['executions'])
            ->inject('message')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Database $dbForProject,
    ): void {
        $executionMessage = ExecutionMessage::fromArray($message->getPayload() ?? []);
        $execution = $executionMessage->execution;

        if ($execution->isEmpty()) {
            throw new Exception('Missing execution');
        }

        $project = $executionMessage->project;
        if ($project->getId() !== self::EXCLUDED_PROJECT_ID) {
            $dbForProject->upsertDocument('executions', $execution);
        }
    }
}
