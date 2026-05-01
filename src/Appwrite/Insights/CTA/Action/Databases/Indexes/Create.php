<?php

namespace Appwrite\Insights\CTA\Action\Databases\Indexes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Insights\CTA\Action as CTAAction;
use Appwrite\Insights\Validator\CTA\Databases\Index\Create as IndexCreateParams;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Create as IndexCreate;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

class Create extends IndexCreate implements CTAAction
{
    public static function getName(): string
    {
        return INSIGHT_CTA_ACTION_DATABASES_INDEXES_CREATE;
    }

    protected function getResponseModel(): string
    {
        return Response::MODEL_INDEX;
    }

    public function __construct()
    {
        // Skip the parent HTTP route registration — this CTA handler is invoked
        // directly through the insights CTA dispatcher, not via Utopia routing.
    }

    public function execute(
        array $params,
        Document $insight,
        Document $project,
        Database $dbForProject,
        callable $getDatabasesDB,
        EventDatabase $queueForDatabase,
        Event $queueForEvents,
        Authorization $authorization,
    ): Document {
        $validator = new IndexCreateParams();
        if (!$validator->isValid($params)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $validator->getDescription());
        }

        return $this->createIndex(
            (string) $params['databaseId'],
            (string) $params['collectionId'],
            (string) $params['key'],
            (string) $params['type'],
            $params['attributes'],
            $params['orders'] ?? [],
            $params['lengths'] ?? [],
            $dbForProject,
            $getDatabasesDB,
            $queueForDatabase,
            $queueForEvents,
            $authorization,
        );
    }
}
