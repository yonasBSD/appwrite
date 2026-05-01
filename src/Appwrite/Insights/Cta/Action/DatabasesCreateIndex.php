<?php

namespace Appwrite\Insights\Cta\Action;

use Appwrite\Extend\Exception;
use Appwrite\Insights\Cta\Action;
use Utopia\Database\Database;
use Utopia\Database\Document;

class DatabasesCreateIndex implements Action
{
    public function getName(): string
    {
        return INSIGHT_CTA_ACTION_DATABASES_CREATE_INDEX;
    }

    public function getRequiredScope(): string
    {
        return 'databases.write';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function validate(array $params): void
    {
        foreach (['databaseId', 'collectionId', 'key', 'type', 'attributes'] as $required) {
            if (!isset($params[$required])) {
                throw new Exception(
                    Exception::INSIGHT_CTA_VALIDATION_FAILED,
                    'Missing required param "' . $required . '" for action "' . $this->getName() . '".'
                );
            }
        }

        if (!\is_array($params['attributes']) || $params['attributes'] === []) {
            throw new Exception(
                Exception::INSIGHT_CTA_VALIDATION_FAILED,
                'Param "attributes" must be a non-empty array of attribute keys.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(array $params, Document $insight, Document $project, Database $dbForProject): Document
    {
        // Placeholder. Cloud's dedicated-database adapter plugs in the real implementation
        // when the bespoke `dedicatedDatabaseIndexSuggestions` collection is migrated to
        // the generic `insights` collection.
        throw new Exception(
            Exception::GENERAL_NOT_IMPLEMENTED,
            'CTA action "' . $this->getName() . '" is not implemented in this build.'
        );
    }
}
