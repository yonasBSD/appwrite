<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Index as IndexValidator;
use Utopia\Platform\Action as UtopiaAction;

abstract class Action extends UtopiaAction
{
    /**
     * The current API context (either 'columnIndex' or 'index').
     */
    private string $context = INDEX;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    public function setHttpPath(string $path): UtopiaAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = COLUMN_INDEX;
        }
        return parent::setHttpPath($path);
    }

    /**
     * Get the current API's parent context.
     */
    final protected function getParentContext(): string
    {
        return $this->getContext() === INDEX ? ATTRIBUTES : COLUMNS;
    }

    /**
     * Get the current API context.
     */
    final protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Determine if the current action is for the Collections API.
     */
    final protected function isCollectionsAPI(): bool
    {
        return $this->getParentContext() === ATTRIBUTES;
    }

    /**
     * Get the SDK group name for the current action.
     */
    final protected function getSDKGroup(): string
    {
        return 'indexes';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    final protected function getSDKNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'tablesDB';
    }

    /**
     * Get the exception to throw when the parent is unknown.
     */
    final protected function getParentUnknownException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_UNKNOWN
            : Exception::COLUMN_UNKNOWN;
    }

    /**
     * Get the appropriate grandparent level not found exception.
     */
    final protected function getGrandParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    final protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_NOT_FOUND
            : Exception::COLUMN_INDEX_NOT_FOUND;
    }

    /**
     * Get the exception to throw when the parent type is invalid.
     */
    final protected function getParentInvalidTypeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_TYPE_INVALID
            : Exception::COLUMN_TYPE_INVALID;
    }

    /**
     * Get the exception to throw when the index type is invalid.
     */
    final protected function getInvalidTypeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_INVALID
            : Exception::COLUMN_INDEX_INVALID;
    }

    /**
     * Get the exception to throw when the resource already exists.
     */
    final protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_ALREADY_EXISTS
            : Exception::COLUMN_INDEX_ALREADY_EXISTS;
    }

    /**
     * Get the exception to throw when the resource limit is exceeded.
     */
    final protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_LIMIT_EXCEEDED
            : Exception::COLUMN_INDEX_LIMIT_EXCEEDED;
    }

    /**
     * Get the exception to throw when the parent attribute/column is not in `available` state.
     */
    final protected function getParentNotAvailableException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_AVAILABLE
            : Exception::COLUMN_NOT_AVAILABLE;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    final protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }

    /**
     * Build, validate, persist and queue a new index document for the current
     * API context. Shared by the public HTTP create-index actions and by the
     * insights CTA action that surfaces missing indexes to project members.
     *
     * @param  array<string>  $attributes
     * @param  array<string>  $orders
     * @param  array<int|null>  $lengths
     */
    final public function createIndex(
        string $databaseId,
        string $collectionId,
        string $key,
        string $type,
        array $attributes,
        array $orders,
        array $lengths,
        Database $dbForProject,
        callable $getDatabasesDB,
        EventDatabase $queueForDatabase,
        Event $queueForEvents,
        Authorization $authorization,
    ): Document {
        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception($this->getGrandParentNotFoundException(), params: [$collectionId]);
        }

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$collection->getSequence()]),
            Query::equal('databaseInternalId', [$db->getSequence()]),
        ], 61);

        $dbForDatabases = $getDatabasesDB($db);

        if ($count >= $dbForDatabases->getLimitForIndexes()) {
            throw new Exception($this->getLimitException(), params: [$collectionId]);
        }

        $oldAttributes = \array_map(
            fn ($a) => $a->getArrayCopy(),
            $collection->getAttribute('attributes')
        );

        $oldAttributes[] = [
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => Database::LENGTH_KEY,
        ];
        $oldAttributes[] = [
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0,
        ];
        $oldAttributes[] = [
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0,
        ];

        $contextType = $this->getParentContext();
        if ($dbForDatabases->getAdapter()->getSupportForAttributes()) {
            foreach ($attributes as $i => $attribute) {
                $attributeIndex = \array_search($attribute, \array_column($oldAttributes, 'key'));

                if ($attributeIndex === false) {
                    throw new Exception($this->getParentUnknownException(), params: [$attribute]);
                }

                $attributeStatus = $oldAttributes[$attributeIndex]['status'];
                $attributeType = $oldAttributes[$attributeIndex]['type'];
                $attributeArray = $oldAttributes[$attributeIndex]['array'] ?? false;

                if ($attributeType === Database::VAR_RELATIONSHIP) {
                    throw new Exception($this->getParentInvalidTypeException(), "Cannot create an index for a relationship $contextType: " . $oldAttributes[$attributeIndex]['key']);
                }

                if ($attributeStatus !== 'available') {
                    throw new Exception($this->getParentNotAvailableException(), params: [$oldAttributes[$attributeIndex]['key']]);
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
            throw new Exception($this->getInvalidTypeException(), $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException(), params: [$key]);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db);

        if ($this->isCollectionsAPI()) {
            $queueForDatabase
                ->setCollection($collection)
                ->setDocument($index);
        } else {
            $queueForDatabase
                ->setTable($collection)
                ->setRow($index);
        }

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('indexId', $index->getId())
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        return $index;
    }
}
