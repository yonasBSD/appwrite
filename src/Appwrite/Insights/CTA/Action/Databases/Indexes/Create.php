<?php

namespace Appwrite\Insights\CTA\Action\Databases\Indexes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Insights\CTA\Action;
use Appwrite\Insights\Validator\CTAParams\DatabasesCreateIndex as DatabasesCreateIndexParams;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Index as IndexValidator;

class Create extends Action
{
    public static function getName(): string
    {
        return INSIGHT_CTA_ACTION_DATABASES_INDEXES_CREATE;
    }

    public function __construct()
    {
        $this
            ->desc('Create a database index from an insight CTA.')
            ->label('scope', 'collections.write')
            ->param('params', [], new DatabasesCreateIndexParams(), 'CTA params describing the index to create.')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function action(
        array $params,
        Document $insight,
        Document $project,
        Database $dbForProject,
        callable $getDatabasesDB,
        EventDatabase $queueForDatabase,
        Event $queueForEvents,
        Authorization $authorization
    ): Document {
        $databaseId = (string) $params['databaseId'];
        $collectionId = (string) $params['collectionId'];
        $key = (string) $params['key'];
        $type = (string) $params['type'];
        $attributes = $params['attributes'];
        $orders = $params['orders'] ?? [];
        $lengths = $params['lengths'] ?? [];

        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND, params: [$collectionId]);
        }

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$collection->getSequence()]),
            Query::equal('databaseInternalId', [$db->getSequence()]),
        ], 61);

        $dbForDatabases = $getDatabasesDB($db);

        if ($count >= $dbForDatabases->getLimitForIndexes()) {
            throw new Exception(Exception::INDEX_LIMIT_EXCEEDED, params: [$collectionId]);
        }

        $oldAttributes = \array_map(
            fn ($a) => $a->getArrayCopy(),
            $collection->getAttribute('attributes')
        );

        foreach ([
            ['$id', Database::VAR_STRING, true, Database::LENGTH_KEY],
            ['$createdAt', Database::VAR_DATETIME, false, 0],
            ['$updatedAt', Database::VAR_DATETIME, false, 0],
        ] as [$attributeKey, $attributeType, $required, $size]) {
            $oldAttributes[] = [
                'key' => $attributeKey,
                'type' => $attributeType,
                'status' => 'available',
                'required' => $required,
                'array' => false,
                'default' => null,
                'size' => $size,
                'signed' => $attributeType === Database::VAR_DATETIME ? false : true,
            ];
        }

        if ($dbForDatabases->getAdapter()->getSupportForAttributes()) {
            foreach ($attributes as $i => $attribute) {
                $attributeIndex = \array_search($attribute, \array_column($oldAttributes, 'key'));

                if ($attributeIndex === false) {
                    throw new Exception(Exception::ATTRIBUTE_UNKNOWN, params: [$attribute]);
                }

                $attributeStatus = $oldAttributes[$attributeIndex]['status'];
                $attributeType = $oldAttributes[$attributeIndex]['type'];
                $attributeArray = $oldAttributes[$attributeIndex]['array'] ?? false;

                if ($attributeType === Database::VAR_RELATIONSHIP) {
                    throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, 'Cannot create an index for a relationship attribute: ' . $oldAttributes[$attributeIndex]['key']);
                }

                if ($attributeStatus !== 'available') {
                    throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE, params: [$oldAttributes[$attributeIndex]['key']]);
                }

                if (empty($lengths[$i])) {
                    $lengths[$i] = null;
                }

                if ($attributeArray === true) {
                    throw new Exception(Exception::INDEX_INVALID, 'Creating indexes on array attributes is not currently supported.');
                }
            }
        }

        $index = new Document([
            '$id' => ID::custom($db->getSequence() . '_' . $collection->getSequence() . '_' . $key),
            'key' => $key,
            'status' => 'processing',
            'databaseInternalId' => $db->getSequence(),
            'databaseId' => $databaseId,
            'collectionInternalId' => $collection->getSequence(),
            'collectionId' => $collectionId,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $validator = new IndexValidator(
            $collection->getAttribute('attributes'),
            $collection->getAttribute('indexes'),
            $dbForDatabases->getAdapter()->getMaxIndexLength(),
            $dbForDatabases->getAdapter()->getInternalIndexesKeys(),
            $dbForDatabases->getAdapter()->getSupportForIndexArray(),
            $dbForDatabases->getAdapter()->getSupportForSpatialIndexNull(),
            $dbForDatabases->getAdapter()->getSupportForSpatialIndexOrder(),
            $dbForDatabases->getAdapter()->getSupportForVectors(),
            $dbForDatabases->getAdapter()->getSupportForAttributes(),
            $dbForDatabases->getAdapter()->getSupportForMultipleFulltextIndexes(),
            $dbForDatabases->getAdapter()->getSupportForIdenticalIndexes(),
            $dbForDatabases->getAdapter()->getSupportForObjectIndexes(),
            $dbForDatabases->getAdapter()->getSupportForTrigramIndex(),
            $dbForDatabases->getAdapter()->getSupportForSpatialAttributes(),
            $dbForDatabases->getAdapter()->getSupportForIndex(),
            $dbForDatabases->getAdapter()->getSupportForUniqueIndex(),
            $dbForDatabases->getAdapter()->getSupportForFulltextIndex(),
            $dbForDatabases->getAdapter()->getSupportForTTLIndexes(),
            $dbForDatabases->getAdapter()->getSupportForObject()
        );

        if (!$validator->isValid($index)) {
            throw new Exception(Exception::INDEX_INVALID, $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception(Exception::INDEX_ALREADY_EXISTS, params: [$key]);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db)
            ->setCollection($collection)
            ->setDocument($index);

        $queueForEvents
            ->setContext('database', $db)
            ->setContext('collection', $collection)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId());

        return $index;
    }
}
