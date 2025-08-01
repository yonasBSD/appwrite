<?php

use Appwrite\Auth\Auth;
use Appwrite\Detector\Detector;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Attributes;
use Appwrite\Utopia\Database\Validator\Queries\Collections;
use Appwrite\Utopia\Database\Validator\Queries\Databases;
use Appwrite\Utopia\Database\Validator\Queries\Indexes;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Index as IndexValidator;
use Utopia\Database\Validator\IndexDependency as IndexDependencyValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\IP;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * * Create attribute of varying type
 *
 * @param string $databaseId
 * @param string $collectionId
 * @param Document $attribute
 * @param Response $response
 * @param Database $dbForProject
 * @param EventDatabase $queueForDatabase
 * @param Event $queueForEvents
 * @return Document Newly created attribute document
 * @throws AuthorizationException
 * @throws Exception
 * @throws LimitException
 * @throws RestrictedException
 * @throws StructureException
 * @throws \Utopia\Database\Exception
 * @throws ConflictException
 * @throws Exception
 */
function createAttribute(string $databaseId, string $collectionId, Document $attribute, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): Document
{
    $key = $attribute->getAttribute('key');
    $type = $attribute->getAttribute('type', '');
    $size = $attribute->getAttribute('size', 0);
    $required = $attribute->getAttribute('required', true);
    $signed = $attribute->getAttribute('signed', true); // integers are signed by default
    $array = $attribute->getAttribute('array', false);
    $format = $attribute->getAttribute('format', '');
    $formatOptions = $attribute->getAttribute('formatOptions', []);
    $filters = $attribute->getAttribute('filters', []); // filters are hidden from the endpoint
    $default = $attribute->getAttribute('default');
    $options = $attribute->getAttribute('options', []);

    $database = $dbForProject->getDocument('databases', $databaseId);

    if ($database->isEmpty()) {
        throw new Exception(Exception::DATABASE_NOT_FOUND);
    }

    $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

    if ($collection->isEmpty()) {
        throw new Exception(Exception::COLLECTION_NOT_FOUND);
    }

    if (!empty($format)) {
        if (!Structure::hasFormat($format, $type)) {
            throw new Exception(Exception::ATTRIBUTE_FORMAT_UNSUPPORTED, "Format {$format} not available for {$type} attributes.");
        }
    }

    // Must throw here since dbForProject->createAttribute is performed by db worker
    if ($required && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required attribute');
    }

    if ($array && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array attributes');
    }

    if ($type === Database::VAR_RELATIONSHIP) {
        $options['side'] = Database::RELATION_SIDE_PARENT;
        $relatedCollection = $dbForProject->getDocument('database_' . $database->getSequence(), $options['relatedCollection'] ?? '');
        if ($relatedCollection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND, 'The related collection was not found.');
        }
    }

    try {
        $attribute = new Document([
            '$id' => ID::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $key),
            'key' => $key,
            'databaseInternalId' => $database->getSequence(),
            'databaseId' => $database->getId(),
            'collectionInternalId' => $collection->getSequence(),
            'collectionId' => $collectionId,
            'type' => $type,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'size' => $size,
            'required' => $required,
            'signed' => $signed,
            'default' => $default,
            'array' => $array,
            'format' => $format,
            'formatOptions' => $formatOptions,
            'filters' => $filters,
            'options' => $options,
        ]);

        $dbForProject->checkAttribute($collection, $attribute);
        $attribute = $dbForProject->createDocument('attributes', $attribute);
    } catch (DuplicateException) {
        throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
    } catch (LimitException) {
        throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
    } catch (\Throwable $e) {
        $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());
        throw $e;
    }

    $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collectionId);
    $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());

    if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
        $twoWayKey = $options['twoWayKey'];
        $options['relatedCollection'] = $collection->getId();
        $options['twoWayKey'] = $key;
        $options['side'] = Database::RELATION_SIDE_CHILD;

        try {
            try {
                $twoWayAttribute = new Document([
                    '$id' => ID::custom($database->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $twoWayKey),
                    'key' => $twoWayKey,
                    'databaseInternalId' => $database->getSequence(),
                    'databaseId' => $database->getId(),
                    'collectionInternalId' => $relatedCollection->getSequence(),
                    'collectionId' => $relatedCollection->getId(),
                    'type' => $type,
                    'status' => 'processing', // processing, available, failed, deleting, stuck
                    'size' => $size,
                    'required' => $required,
                    'signed' => $signed,
                    'default' => $default,
                    'array' => $array,
                    'format' => $format,
                    'formatOptions' => $formatOptions,
                    'filters' => $filters,
                    'options' => $options,
                ]);

                $dbForProject->checkAttribute($relatedCollection, $twoWayAttribute);
                $dbForProject->createDocument('attributes', $twoWayAttribute);
            } catch (DuplicateException) {
                throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
            } catch (LimitException) {
                throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
            } catch (StructureException $e) {
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
            }
        } catch (\Throwable $e) {
            $dbForProject->deleteDocument('attributes', $attribute->getId());
            throw $e;
        } finally {
            $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collectionId);
            $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());
        }

        // If operation succeeded, purge the cache for the related collection too
        $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $relatedCollection->getId());
        $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence());
    }

    $queueForDatabase
        ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
        ->setDatabase($database)
        ->setCollection($collection)
        ->setDocument($attribute);

    $queueForEvents
        ->setContext('collection', $collection)
        ->setContext('database', $database)
        ->setParam('databaseId', $databaseId)
        ->setParam('collectionId', $collection->getId())
        ->setParam('attributeId', $attribute->getId());

    $response->setStatusCode(Response::STATUS_CODE_CREATED);

    return $attribute;
}

function updateAttribute(
    string $databaseId,
    string $collectionId,
    string $key,
    Database $dbForProject,
    Event $queueForEvents,
    string $type,
    int $size = null,
    string $filter = null,
    string|bool|int|float $default = null,
    bool $required = null,
    int|float|null $min = null,
    int|float|null $max = null,
    array $elements = null,
    array $options = [],
    string $newKey = null,
): Document {
    $database = $dbForProject->getDocument('databases', $databaseId);
    if ($database->isEmpty()) {
        throw new Exception(Exception::DATABASE_NOT_FOUND);
    }

    $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
    if ($collection->isEmpty()) {
        throw new Exception(Exception::COLLECTION_NOT_FOUND);
    }

    $attribute = $dbForProject->getDocument('attributes', $database->getSequence() . '_' . $collection->getSequence() . '_' . $key);
    if ($attribute->isEmpty()) {
        throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
    }

    if ($attribute->getAttribute('status') !== 'available') {
        throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE);
    }

    if ($attribute->getAttribute(('type') !== $type)) {
        throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
    }

    if ($attribute->getAttribute('type') === Database::VAR_STRING && $attribute->getAttribute(('filter') !== $filter)) {
        throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
    }

    if ($required && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required attribute');
    }

    if ($attribute->getAttribute('array', false) && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array attributes');
    }

    $collectionId =  'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

    $attribute
        ->setAttribute('default', $default)
        ->setAttribute('required', $required);

    if (!empty($size)) {
        $attribute->setAttribute('size', $size);
    }

    switch ($attribute->getAttribute('format')) {
        case APP_DATABASE_ATTRIBUTE_INT_RANGE:
        case APP_DATABASE_ATTRIBUTE_FLOAT_RANGE:
            $min ??= $attribute->getAttribute('formatOptions')['min'];
            $max ??= $attribute->getAttribute('formatOptions')['max'];

            if ($min > $max) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
            }

            if ($attribute->getAttribute('format') === APP_DATABASE_ATTRIBUTE_INT_RANGE) {
                $validator = new Range($min, $max, Database::VAR_INTEGER);
            } else {
                $validator = new Range($min, $max, Database::VAR_FLOAT);

                if (!is_null($default)) {
                    $default = \floatval($default);
                }
            }

            if (!is_null($default) && !$validator->isValid($default)) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
            }

            $options = [
                'min' => $min,
                'max' => $max
            ];
            $attribute->setAttribute('formatOptions', $options);

            break;
        case APP_DATABASE_ATTRIBUTE_ENUM:
            if (empty($elements)) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Enum elements must not be empty');
            }

            foreach ($elements as $element) {
                if (\strlen($element) === 0) {
                    throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Each enum element must not be empty');
                }
            }

            if (!is_null($default) && !in_array($default, $elements)) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
            }

            $options = [
                'elements' => $elements
            ];

            $attribute->setAttribute('formatOptions', $options);

            break;
    }

    if ($type === Database::VAR_RELATIONSHIP) {
        $primaryDocumentOptions = \array_merge($attribute->getAttribute('options', []), $options);
        $attribute->setAttribute('options', $primaryDocumentOptions);
        try {
            $dbForProject->updateRelationship(
                collection: $collectionId,
                id: $key,
                newKey: $newKey,
                onDelete: $primaryDocumentOptions['onDelete'],
            );
        } catch (IndexException) {
            throw new Exception(Exception::INDEX_INVALID);
        } catch (LimitException) {
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        if ($primaryDocumentOptions['twoWay']) {
            $relatedCollection = $dbForProject->getDocument('database_' . $database->getSequence(), $primaryDocumentOptions['relatedCollection']);
            $relatedAttribute = $dbForProject->getDocument('attributes', $database->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $primaryDocumentOptions['twoWayKey']);

            if (!empty($newKey) && $newKey !== $key) {
                $options['twoWayKey'] = $newKey;
            }

            $relatedOptions = \array_merge($relatedAttribute->getAttribute('options'), $options);
            $relatedAttribute->setAttribute('options', $relatedOptions);


            $dbForProject->updateDocument('attributes', $database->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $primaryDocumentOptions['twoWayKey'], $relatedAttribute);
            $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $relatedCollection->getId());
        }
    } else {
        try {
            $dbForProject->updateAttribute(
                collection: $collectionId,
                id: $key,
                size: $size,
                required: $required,
                default: $default,
                formatOptions: $options,
                newKey: $newKey ?? null
            );
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (IndexException $e) {
            throw new Exception(Exception::INDEX_INVALID, $e->getMessage());
        } catch (LimitException) {
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
        } catch (TruncateException) {
            throw new Exception(Exception::ATTRIBUTE_INVALID_RESIZE);
        }
    }

    if (!empty($newKey) && $key !== $newKey) {
        $originalUid = $attribute->getId();

        $attribute
            ->setAttribute('$id', ID::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $newKey))
            ->setAttribute('key', $newKey);

        try {
            $dbForProject->updateDocument('attributes', $originalUid, $attribute);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        }

        /**
         * @var Document $index
         */
        foreach ($collection->getAttribute('indexes') as $index) {
            /**
             * @var string[] $attributes
             */
            $attributes = $index->getAttribute('attributes', []);
            $found = \array_search($key, $attributes);

            if ($found !== false) {
                $attributes[$found] = $newKey;
                $index->setAttribute('attributes', $attributes);
                $dbForProject->updateDocument('indexes', $index->getId(), $index);
            }
        }
    } else {
        $attribute = $dbForProject->updateDocument('attributes', $database->getSequence() . '_' . $collection->getSequence() . '_' . $key, $attribute);
    }

    $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collection->getId());

    $queueForEvents
        ->setContext('collection', $collection)
        ->setContext('database', $database)
        ->setParam('databaseId', $databaseId)
        ->setParam('collectionId', $collection->getId())
        ->setParam('attributeId', $attribute->getId());

    return $attribute;
}

App::init()
    ->groups(['api', 'database'])
    ->inject('request')
    ->inject('dbForProject')
    ->action(function (Request $request, Database $dbForProject) {
        $timeout = \intval($request->getHeader('x-appwrite-timeout'));

        if (!empty($timeout) && App::isDevelopment()) {
            $dbForProject->setTimeout($timeout);
        }
    });

App::post('/v1/databases')
    ->desc('Create database')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].create')
    ->label('scope', 'databases.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'database.create')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'create',
        description: '/docs/references/databases/create.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_DATABASE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $name, bool $enabled, Response $response, Database $dbForProject, Event $queueForEvents) {

        $databaseId = $databaseId === 'unique()'
            ? ID::unique()
            : $databaseId;

        try {
            $dbForProject->createDocument('databases', new Document([
                '$id' => $databaseId,
                'name' => $name,
                'enabled' => $enabled,
                'search' => implode(' ', [$databaseId, $name]),
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $database = $dbForProject->getDocument('databases', $databaseId);

        $collections = (Config::getParam('collections', [])['databases'] ?? [])['collections'] ?? [];
        if (empty($collections)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The "collections" collection is not configured.');
        }

        $attributes = [];
        foreach ($collections['attributes'] as $attribute) {
            $attributes[] = new Document($attribute);
        }

        $indexes = [];
        foreach ($collections['indexes'] as $index) {
            $indexes[] = new Document($index);
        }

        try {
            $dbForProject->createCollection('database_' . $database->getSequence(), $attributes, $indexes);
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS);
        } catch (IndexException) {
            throw new Exception(Exception::INDEX_INVALID);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $queueForEvents->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($database, Response::MODEL_DATABASE);
    });

App::get('/v1/databases')
    ->desc('List databases')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'list',
        description: '/docs/references/databases/list.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DATABASE_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('queries', [], new Databases(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Databases::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {
        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $databaseId = $cursor->getValue();

            $cursorDocument = $dbForProject->getDocument('databases', $databaseId);
            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Database '{$databaseId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $databases = $dbForProject->find('databases', $queries);
            $total = $dbForProject->count('databases', $queries, APP_LIMIT_COUNT);
        } catch (OrderException) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL);
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        $response->dynamic(new Document([
            'databases' => $databases,
            'total' => $total,
        ]), Response::MODEL_DATABASE_LIST);
    });

App::get('/v1/databases/:databaseId')
    ->desc('Get database')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'get',
        description: '/docs/references/databases/get.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DATABASE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $response->dynamic($database, Response::MODEL_DATABASE);
    });

App::get('/v1/databases/:databaseId/logs')
    ->desc('List database logs')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'logs',
        name: 'listLogs',
        description: '/docs/references/databases/get-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Temp fix for logs
        $queries[] = Query::or([
            Query::greaterThan('$createdAt', DateTime::format(new \DateTime('2025-02-26T01:30+00:00'))),
            Query::lessThan('$createdAt', DateTime::format(new \DateTime('2025-02-13T00:00+00:00'))),
        ]);

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId;
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource, $queries),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });


App::put('/v1/databases/:databaseId')
    ->desc('Update database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].update')
    ->label('audits.event', 'database.update')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'update',
        description: '/docs/references/databases/update.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DATABASE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('name', null, new Text(128), 'Database name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(), 'Is database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $name, bool $enabled, Response $response, Database $dbForProject, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $database
            ->setAttribute('name', $name)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('search', implode(' ', [$databaseId, $name]));

        $database = $dbForProject->updateDocument('databases', $databaseId, $database);

        $queueForEvents->setParam('databaseId', $database->getId());

        $response->dynamic($database, Response::MODEL_DATABASE);
    });

App::delete('/v1/databases/:databaseId')
    ->desc('Delete database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].delete')
    ->label('audits.event', 'database.delete')
    ->label('audits.resource', 'database/{request.databaseId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'delete',
        description: '/docs/references/databases/delete.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('databases', $databaseId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove database from database');
        }

        $dbForProject->purgeCachedDocument('databases', $database->getId());
        $dbForProject->purgeCachedCollection('database_' . $database->getSequence());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_DATABASE)
            ->setDatabase($database);

        $queueForEvents
            ->setParam('databaseId', $database->getId())
            ->setPayload($response->output($database, Response::MODEL_DATABASE));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections')
    ->desc('Create collection')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'collection.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'createCollection',
        description: '/docs/references/databases/create-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_COLLECTION,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, string $mode, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionId = $collectionId === 'unique()'
            ? ID::unique()
            : $collectionId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions) ?? [];

        try {
            $collection = $dbForProject->createDocument('database_' . $database->getSequence(), new Document([
                '$id' => $collectionId,
                'databaseInternalId' => $database->getSequence(),
                'databaseId' => $databaseId,
                '$permissions' => $permissions,
                'documentSecurity' => $documentSecurity,
                'enabled' => $enabled,
                'name' => $name,
                'search' => implode(' ', [$collectionId, $name]),
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::COLLECTION_ALREADY_EXISTS);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED);
        } catch (NotFoundException) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        try {
            $dbForProject->createCollection(
                id: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                permissions: $permissions,
                documentSecurity: $documentSecurity
            );
        } catch (DuplicateException) {
            throw new Exception(Exception::COLLECTION_ALREADY_EXISTS);
        } catch (IndexException) {
            throw new Exception(Exception::INDEX_INVALID);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/collections')
    ->alias('/v1/database/collections')
    ->desc('List collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'listCollections',
        description: '/docs/references/databases/list-collections.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COLLECTION_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('queries', [], new Collections(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Collections::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, array $queries, string $search, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $collectionId = $cursor->getValue();

            $cursorDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Collection '{$collectionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $collectionId = 'database_' . $database->getSequence();

        try {
            $collections = $dbForProject->find($collectionId, $queries);
            $total = $dbForProject->count($collectionId, $queries, APP_LIMIT_COUNT);
        } catch (OrderException) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL);
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        $response->dynamic(new Document([
            'collections' => $collections,
            'total' => $total,
        ]), Response::MODEL_COLLECTION_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId')
    ->desc('Get collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'getCollection',
        description: '/docs/references/databases/get-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COLLECTION,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/logs')
    ->alias('/v1/database/collections/:collectionId/logs')
    ->desc('List collection logs')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'listCollectionLogs',
        description: '/docs/references/databases/get-collection-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $collectionDocument->getSequence());

        if ($collectionDocument->isEmpty() || $collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Temp fix for logs
        $queries[] = Query::or([
            Query::greaterThan('$createdAt', DateTime::format(new \DateTime('2025-02-26T01:30+00:00'))),
            Query::lessThan('$createdAt', DateTime::format(new \DateTime('2025-02-13T00:00+00:00'))),
        ]);

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId . '/collection/' . $collectionId;
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['data']['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource, $queries),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });


App::put('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId')
    ->desc('Update collection')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].update')
    ->label('audits.event', 'collection.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'updateCollection',
        description: '/docs/references/databases/update-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COLLECTION,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, string $mode, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $permissions ??= $collection->getPermissions() ?? [];

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        $enabled ??= $collection->getAttribute('enabled', true);

        $collection
            ->setAttribute('name', $name)
            ->setAttribute('$permissions', $permissions)
            ->setAttribute('documentSecurity', $documentSecurity)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('search', \implode(' ', [$collectionId, $name]));

        $collection = $dbForProject->updateDocument(
            'database_' . $database->getSequence(),
            $collectionId,
            $collection
        );

        $dbForProject->updateCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $permissions, $documentSecurity);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId());

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId')
    ->desc('Delete collection')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].delete')
    ->label('audits.event', 'collection.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'deleteCollection',
        description: '/docs/references/databases/delete-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('database_' . $database->getSequence(), $collectionId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_COLLECTION)
            ->setDatabase($database)
            ->setCollection($collection);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setPayload($response->output($collection, Response::MODEL_COLLECTION));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/string')
    ->alias('/v1/database/collections/:collectionId/attributes/string')
    ->desc('Create string attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createStringAttribute',
        description: '/docs/references/databases/create-string-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_STRING
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0, 0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->param('encrypt', false, new Boolean(), 'Toggle encryption for the attribute. Encryption enhances security by not storing any plain text values in the database. However, encrypted attributes cannot be queried.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->inject('plan')
    ->action(function (string $databaseId, string $collectionId, string $key, ?int $size, ?bool $required, ?string $default, bool $array, bool $encrypt, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, array $plan) {
        if (!App::isDevelopment() && $encrypt && !empty($plan) && !($plan['databasesAllowEncrypt'] ?? false)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Encrypted string attributes are not available on your plan. Please upgrade to create encrypted string attributes.');
        }
        if ($encrypt && $size < APP_DATABASE_ENCRYPT_SIZE_MIN) {
            throw new Exception(
                Exception::GENERAL_BAD_REQUEST,
                "Size too small. Encrypted strings require a minimum size of " . APP_DATABASE_ENCRYPT_SIZE_MIN . " characters."
            );
        }
        // Ensure attribute default is within required size
        $validator = new Text($size, 0);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $filters = [];
        if ($encrypt) {
            $filters[] = 'encrypt';
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => $filters,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);
        $attribute->setAttribute('encrypt', $encrypt);
        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/email')
    ->alias('/v1/database/collections/:collectionId/attributes/email')
    ->desc('Create email attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createEmailAttribute',
        description: '/docs/references/databases/create-email-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_EMAIL,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Email(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 254,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_EMAIL,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/enum')
    ->alias('/v1/database/collections/:collectionId/attributes/enum')
    ->desc('Create enum attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createEnumAttribute',
        description: '/docs/references/databases/create-attribute-enum.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_ENUM,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('elements', [], new ArrayList(new Text(DATABASE::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . DATABASE::LENGTH_KEY . ' characters long.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, array $elements, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        if (!is_null($default) && !in_array($default, $elements)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => Database::LENGTH_KEY,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_ENUM,
            'formatOptions' => ['elements' => $elements],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/ip')
    ->alias('/v1/database/collections/:collectionId/attributes/ip')
    ->desc('Create IP address attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createIpAttribute',
        description: '/docs/references/databases/create-ip-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_IP,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new IP(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 39,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_IP,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/url')
    ->alias('/v1/database/collections/:collectionId/attributes/url')
    ->desc('Create URL attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createUrlAttribute',
        description: '/docs/references/databases/create-url-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_URL,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new URL(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 2000,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/integer')
    ->alias('/v1/database/collections/:collectionId/attributes/integer')
    ->desc('Create integer attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createIntegerAttribute',
        description: '/docs/references/databases/create-integer-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_INTEGER,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Integer(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        // Ensure attribute default is within range
        $min ??= PHP_INT_MIN;
        $max ??= PHP_INT_MAX;

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_INTEGER);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $size = $max > 2147483647 ? 8 : 4; // Automatically create BigInt depending on max value

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_INTEGER,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/float')
    ->alias('/v1/database/collections/:collectionId/attributes/float')
    ->desc('Create float attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createFloatAttribute',
        description: '/docs/references/databases/create-float-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_FLOAT,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new FloatValidator(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        // Ensure attribute default is within range
        $min ??= -PHP_FLOAT_MAX;
        $max ??= PHP_FLOAT_MAX;

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_FLOAT);

        if (!\is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_FLOAT,
            'required' => $required,
            'size' => 0,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_FLOAT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/boolean')
    ->alias('/v1/database/collections/:collectionId/attributes/boolean')
    ->desc('Create boolean attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createBooleanAttribute',
        description: '/docs/references/databases/create-boolean-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_BOOLEAN,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Boolean(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?bool $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/datetime')
    ->alias('/v1/database/collections/:collectionId/attributes/datetime')
    ->desc('Create datetime attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createDatetimeAttribute',
        description: '/docs/references/databases/create-datetime-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_DATETIME,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, fn (Database $dbForProject) => new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime()), 'Default value for the attribute in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Cannot be set when attribute is required.', true, ['dbForProject'])
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $filters[] = 'datetime';

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_DATETIME,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => $filters,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/relationship')
    ->alias('/v1/database/collections/:collectionId/attributes/relationship')
    ->desc('Create relationship attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'createRelationshipAttribute',
        description: '/docs/references/databases/create-relationship-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('relatedCollectionId', '', new UID(), 'Related Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('type', '', new WhiteList([Database::RELATION_ONE_TO_ONE, Database::RELATION_MANY_TO_ONE, Database::RELATION_MANY_TO_MANY, Database::RELATION_ONE_TO_MANY], true), 'Relation type')
    ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
    ->param('key', null, new Key(), 'Attribute Key.', true)
    ->param('twoWayKey', null, new Key(), 'Two Way Attribute Key.', true)
    ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL], true), 'Constraints option', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (
        string $databaseId,
        string $collectionId,
        string $relatedCollectionId,
        string $type,
        bool $twoWay,
        ?string $key,
        ?string $twoWayKey,
        string $onDelete,
        Response $response,
        Database $dbForProject,
        EventDatabase $queueForDatabase,
        Event $queueForEvents
    ) {
        $key ??= $relatedCollectionId;
        $twoWayKey ??= $collectionId;

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $relatedCollectionDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId);
        $relatedCollection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $relatedCollectionDocument->getSequence());

        if ($relatedCollection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attributes = $collection->getAttribute('attributes', []);

        /** @var Document[] $attributes */
        foreach ($attributes as $attribute) {
            if ($attribute->getAttribute('type') !== Database::VAR_RELATIONSHIP) {
                continue;
            }

            if (\strtolower($attribute->getId()) === \strtolower($key)) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
            }

            if (
                \strtolower($attribute->getAttribute('options')['twoWayKey']) === \strtolower($twoWayKey) &&
                $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                // Console should provide a unique twoWayKey input!
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.');
            }

            if (
                $type === Database::RELATION_MANY_TO_MANY &&
                $attribute->getAttribute('options')['relationType'] === Database::RELATION_MANY_TO_MANY &&
                $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Creating more than one "manyToMany" relationship on the same collection is currently not permitted.');
            }
        }

        $attribute = createAttribute(
            $databaseId,
            $collectionId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_RELATIONSHIP,
                'size' => 0,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
                'options' => [
                    'relatedCollection' => $relatedCollectionId,
                    'relationType' => $type,
                    'twoWay' => $twoWay,
                    'twoWayKey' => $twoWayKey,
                    'onDelete' => $onDelete,
                ]
            ]),
            $response,
            $dbForProject,
            $queueForDatabase,
            $queueForEvents
        );

        $options = $attribute->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/attributes')
    ->alias('/v1/database/collections/:collectionId/attributes')
    ->desc('List attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'listAttributes',
        description: '/docs/references/databases/list-attributes.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_LIST
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new Attributes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Attributes::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        \array_push(
            $queries,
            Query::equal('databaseInternalId', [$database->getSequence()]),
            Query::equal('collectionInternalId', [$collection->getSequence()]),
        );

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $attributeId = $cursor->getValue();

            try {
                $cursorDocument = $dbForProject->findOne('attributes', [
                    Query::equal('databaseInternalId', [$database->getSequence()]),
                    Query::equal('collectionInternalId', [$collection->getSequence()]),
                    Query::equal('key', [$attributeId]),
                ]);
            } catch (QueryException $e) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Attribute '{$attributeId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $attributes = $dbForProject->find('attributes', $queries);
            $total = $dbForProject->count('attributes', $queries, APP_LIMIT_COUNT);
        } catch (OrderException) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL);
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        foreach ($attributes as $attribute) {
            if ($attribute->getAttribute('type') === Database::VAR_STRING) {
                $filters = $attribute->getAttribute('filters', []);
                $attribute->setAttribute('encrypt', in_array('encrypt', $filters));
            }
        }

        $response->dynamic(new Document([
            'attributes' => $attributes,
            'total' => $total,
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
    ->alias('/v1/database/collections/:collectionId/attributes/:key')
    ->desc('Get attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'getAttribute',
        description: '/docs/references/databases/get-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: [
                    Response::MODEL_ATTRIBUTE_BOOLEAN,
                    Response::MODEL_ATTRIBUTE_INTEGER,
                    Response::MODEL_ATTRIBUTE_FLOAT,
                    Response::MODEL_ATTRIBUTE_EMAIL,
                    Response::MODEL_ATTRIBUTE_ENUM,
                    Response::MODEL_ATTRIBUTE_URL,
                    Response::MODEL_ATTRIBUTE_IP,
                    Response::MODEL_ATTRIBUTE_DATETIME,
                    Response::MODEL_ATTRIBUTE_RELATIONSHIP,
                    Response::MODEL_ATTRIBUTE_STRING
                ]
            ),
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attribute = $dbForProject->getDocument('attributes', $database->getSequence() . '_' . $collection->getSequence() . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');
        $options = $attribute->getAttribute('options', []);
        $filters = $attribute->getAttribute('filters', []);
        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
        }

        $model = match ($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
            Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };
        $attribute->setAttribute('encrypt', in_array('encrypt', $filters));
        $response->dynamic($attribute, $model);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/string/:key')
    ->desc('Update string attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateStringAttribute',
        description: '/docs/references/databases/update-string-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_STRING,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Maximum size of the string attribute.', true)
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?int $size, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            size: $size,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/email/:key')
    ->desc('Update email attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateEmailAttribute',
        description: '/docs/references/databases/update-email-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_EMAIL,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Email()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_EMAIL,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/enum/:key')
    ->desc('Update enum attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateEnumAttribute',
        description: '/docs/references/databases/update-enum-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_ENUM,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('elements', null, new ArrayList(new Text(DATABASE::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . DATABASE::LENGTH_KEY . ' characters long.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Text(0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?array $elements, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_ENUM,
            default: $default,
            required: $required,
            elements: $elements,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/ip/:key')
    ->desc('Update IP address attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateIpAttribute',
        description: '/docs/references/databases/update-ip-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_IP,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new IP()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_IP,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/url/:key')
    ->desc('Update URL attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateUrlAttribute',
        description: '/docs/references/databases/update-url-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_URL,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new URL()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_URL,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/integer/:key')
    ->desc('Update integer attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateIntegerAttribute',
        description: '/docs/references/databases/update-integer-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_INTEGER,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Nullable(new Integer()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_INTEGER,
            default: $default,
            required: $required,
            min: $min,
            max: $max,
            newKey: $newKey
        );

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/float/:key')
    ->desc('Update float attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateFloatAttribute',
        description: '/docs/references/databases/update-float-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_FLOAT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Nullable(new FloatValidator()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_FLOAT,
            default: $default,
            required: $required,
            min: $min,
            max: $max,
            newKey: $newKey
        );

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/boolean/:key')
    ->desc('Update boolean attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateBooleanAttribute',
        description: '/docs/references/databases/update-boolean-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_BOOLEAN,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Boolean()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?bool $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_BOOLEAN,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/datetime/:key')
    ->desc('Update dateTime attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateDatetimeAttribute',
        description: '/docs/references/databases/update-datetime-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_DATETIME,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, fn (Database $dbForProject) => new Nullable(new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime())), 'Default value for attribute when not provided. Cannot be set when attribute is required.', injections: ['dbForProject'])
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_DATETIME,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/:key/relationship')
    ->desc('Update relationship attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'updateRelationshipAttribute',
        description: '/docs/references/databases/update-relationship-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('onDelete', null, new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL], true), 'Constraints option', true)
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (
        string $databaseId,
        string $collectionId,
        string $key,
        ?string $onDelete,
        ?string $newKey,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $attribute = updateAttribute(
            $databaseId,
            $collectionId,
            $key,
            $dbForProject,
            $queueForEvents,
            type: Database::VAR_RELATIONSHIP,
            required: false,
            options: [
                'onDelete' => $onDelete
            ],
            newKey: $newKey
        );

        $options = $attribute->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
    ->alias('/v1/database/collections/:collectionId/attributes/:key')
    ->desc('Delete attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'attributes',
        name: 'deleteAttribute',
        description: '/docs/references/databases/delete-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attribute = $dbForProject->getDocument('attributes', $database->getSequence() . '_' . $collection->getSequence() . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        /**
         * Check index dependency
         */
        $validator = new IndexDependencyValidator(
            $collection->getAttribute('indexes'),
            $dbForProject->getAdapter()->getSupportForCastIndexArray(),
        );

        if (! $validator->isValid($attribute)) {
            throw new Exception(Exception::INDEX_DEPENDENCY);
        }

        // Only update status if removing available attribute
        if ($attribute->getAttribute('status') === 'available') {
            $attribute = $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());

        if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
            $options = $attribute->getAttribute('options');
            if ($options['twoWay']) {
                $relatedCollection = $dbForProject->getDocument('database_' . $database->getSequence(), $options['relatedCollection']);

                if ($relatedCollection->isEmpty()) {
                    throw new Exception(Exception::COLLECTION_NOT_FOUND);
                }

                $relatedAttribute = $dbForProject->getDocument('attributes', $database->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $options['twoWayKey']);

                if ($relatedAttribute->isEmpty()) {
                    throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
                }

                if ($relatedAttribute->getAttribute('status') === 'available') {
                    $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'deleting'));
                }

                $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $options['relatedCollection']);
                $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence());
            }
        }

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setCollection($collection)
            ->setDatabase($database)
            ->setDocument($attribute);

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = match ($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
            Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->output($attribute, $model));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/indexes')
    ->alias('/v1/database/collections/:collectionId/indexes')
    ->desc('Create index')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'index.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'createIndex',
        description: '/docs/references/databases/create-index.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_INDEX,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' attributes are allowed, each 32 characters long.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
    ->param('lengths', [], new ArrayList(new Nullable(new Integer()), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Length of index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE, optional: true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, string $type, array $attributes, array $orders, array $lengths, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $limit = $dbForProject->getLimitForIndexes();

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$collection->getSequence()]),
            Query::equal('databaseInternalId', [$database->getSequence()])
        ], max: $limit);


        if ($count >= $limit) {
            throw new Exception(Exception::INDEX_LIMIT_EXCEEDED, 'Index limit exceeded');
        }

        // Convert Document array to array of attribute metadata
        $oldAttributes = \array_map(fn ($a) => $a->getArrayCopy(), $collection->getAttribute('attributes'));

        $oldAttributes[] = [
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => Database::LENGTH_KEY
        ];
        $oldAttributes[] = [
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];
        $oldAttributes[] = [
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        foreach ($attributes as $i => $attribute) {
            // Find attribute metadata in collection document
            $attributeIndex = \array_search($attribute, array_column($oldAttributes, 'key'));

            if ($attributeIndex === false) {
                throw new Exception(Exception::ATTRIBUTE_UNKNOWN, 'Unknown attribute: ' . $attribute . '. Verify the attribute name or create the attribute.');
            }

            $attributeStatus = $oldAttributes[$attributeIndex]['status'];
            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeArray = $oldAttributes[$attributeIndex]['array'] ?? false;

            if ($attributeType === Database::VAR_RELATIONSHIP) {
                throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, 'Cannot create an index for a relationship attribute: ' . $oldAttributes[$attributeIndex]['key']);
            }

            // ensure attribute is available
            if ($attributeStatus !== 'available') {
                throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE, 'Attribute not available: ' . $oldAttributes[$attributeIndex]['key']);
            }

            $lengths[$i] ??= null;
            if ($attributeArray === true) {
                $lengths[$i] = Database::ARRAY_INDEX_LENGTH;
                $orders[$i] = null;
            }
        }

        $index = new Document([
            '$id' => ID::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $key),
            'key' => $key,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'databaseInternalId' => $database->getSequence(),
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
            $dbForProject->getAdapter()->getMaxIndexLength(),
            $dbForProject->getAdapter()->getInternalIndexesKeys(),
        );

        if (!$validator->isValid($index)) {
            throw new Exception(Exception::INDEX_INVALID, $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception(Exception::INDEX_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($database)
            ->setCollection($collection)
            ->setDocument($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($index, Response::MODEL_INDEX);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/indexes')
    ->alias('/v1/database/collections/:collectionId/indexes')
    ->desc('List indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'indexes',
        name: 'listIndexes',
        description: '/docs/references/databases/list-indexes.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_INDEX_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new Indexes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Indexes::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        \array_push(
            $queries,
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId]),
        );

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $indexId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('indexes', [
                Query::equal('collectionInternalId', [$collection->getSequence()]),
                Query::equal('databaseInternalId', [$database->getSequence()]),
                Query::equal('key', [$indexId]),
                Query::limit(1)
            ]));

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Index '{$indexId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        try {
            $total = $dbForProject->count('indexes', $queries, APP_LIMIT_COUNT);
            $indexes = $dbForProject->find('indexes', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $response->dynamic(new Document([
            'total' => $total,
            'indexes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
    ->alias('/v1/database/collections/:collectionId/indexes/:key')
    ->desc('Get index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'indexes',
        name: 'getIndex',
        description: '/docs/references/databases/get-index.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_INDEX,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $collection->find('key', $key, 'indexes');

        if (empty($index)) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
    ->alias('/v1/database/collections/:collectionId/indexes/:key')
    ->desc('Delete index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].update')
    ->label('audits.event', 'index.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'indexes',
        name: 'deleteIndex',
        description: '/docs/references/databases/delete-index.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $dbForProject->getDocument('indexes', $database->getSequence() . '_' . $collection->getSequence() . '_' . $key);

        if ($index->isEmpty()) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setDatabase($database)
            ->setCollection($collection)
            ->setDocument($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->output($index, Response::MODEL_INDEX));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->alias('/v1/database/collections/:collectionId/documents')
    ->desc('Create document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'document.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label(
        'sdk',
        // Using multiple methods to abstract the complexity for SDK users
        [
            new Method(
                namespace: 'databases',
                group: 'documents',
                name: 'createDocument',
                description: '/docs/references/databases/create-document.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DOCUMENT,
                    )
                ],
                contentType: ContentType::JSON,
                parameters: [
                    new Parameter('databaseId', optional: false),
                    new Parameter('collectionId', optional: false),
                    new Parameter('documentId', optional: false),
                    new Parameter('data', optional: false),
                    new Parameter('permissions', optional: true),
                ]
            ),
            new Method(
                namespace: 'databases',
                group: 'documents',
                name: 'createDocuments',
                description: '/docs/references/databases/create-documents.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DOCUMENT_LIST,
                    )
                ],
                contentType: ContentType::JSON,
                parameters: [
                    new Parameter('databaseId', optional: false),
                    new Parameter('collectionId', optional: false),
                    new Parameter('documents', optional: false),
                ]
            )
        ]
    )
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('documentId', '', new CustomId(), 'Document ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', true)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). Make sure to define attributes before creating documents.')
    ->param('data', [], new JSON(), 'Document data as JSON object.', true)
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documents', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of documents data as JSON objects.', true, ['plan'])
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, ?string $documentId, string $collectionId, string|array|null $data, ?array $permissions, ?array $documents, Response $response, Database $dbForProject, Document $user, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
        $data = \is_string($data)
            ? \json_decode($data, true)
            : $data;

        /**
         * Determine which internal path to call, single or bulk
         */
        if (empty($data) && empty($documents)) {
            // No single or bulk documents provided
            throw new Exception(Exception::DOCUMENT_MISSING_DATA);
        }
        if (!empty($data) && !empty($documents)) {
            // Both single and bulk documents provided
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'You can only send one of the following parameters: data, documents');
        }
        if (!empty($data) && empty($documentId)) {
            // Single document provided without document ID
            throw new Exception(Exception::DOCUMENT_MISSING_DATA, 'Document ID is required when creating a single document');
        }
        if (!empty($documents) && !empty($documentId)) {
            // Bulk documents provided with document ID
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "documentId" is disallowed when creating multiple documents, set "$id" in each document instead');
        }
        if (!empty($documents) && !empty($permissions)) {
            // Bulk documents provided with permissions
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "permissions" is disallowed when creating multiple documents, set "$permissions" in each document instead');
        }

        $isBulk = true;
        if (!empty($data)) {
            // Single document provided, convert to single item array
            // But remember that it was single to respond with a single document
            $isBulk = false;
            $documents = [$data];
        }

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($isBulk && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE);
        }

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($isBulk && $hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk create is not supported for collections with relationship attributes');
        }

        $setPermissions = function (Document $document, ?array $permissions) use ($user, $isAPIKey, $isPrivilegedUser, $isBulk) {
            $allowedPermissions = [
                Database::PERMISSION_READ,
                Database::PERMISSION_UPDATE,
                Database::PERMISSION_DELETE,
            ];

            // If bulk, we need to validate permissions explicitly per document
            if ($isBulk) {
                $permissions = $document['$permissions'] ?? null;
                if (!empty($permissions)) {
                    $validator = new Permissions();
                    if (!$validator->isValid($permissions)) {
                        throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
                    }
                }
            }

            $permissions = Permission::aggregate($permissions, $allowedPermissions);

            // Add permissions for current the user if none were provided.
            if (\is_null($permissions)) {
                $permissions = [];
                if (!empty($user->getId())) {
                    foreach ($allowedPermissions as $permission) {
                        $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                    }
                }
            }

            // Users can only manage their own roles, API keys and Admin users can manage any
            if (!$isAPIKey && !$isPrivilegedUser) {
                foreach (Database::PERMISSIONS as $type) {
                    foreach ($permissions as $permission) {
                        $permission = Permission::parse($permission);
                        if ($permission->getPermission() != $type) {
                            continue;
                        }
                        $role = (new Role(
                            $permission->getRole(),
                            $permission->getIdentifier(),
                            $permission->getDimension()
                        ))->toString();
                        if (!Authorization::isRole($role)) {
                            throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', Authorization::getRoles()) . ')');
                        }
                    }
                }
            }

            $document->setAttribute('$permissions', $permissions);
        };

        $operations = 0;

        $checkPermissions = function (Document $collection, Document $document, string $permission) use (&$checkPermissions, $dbForProject, $database, &$operations) {
            $operations++;

            $documentSecurity = $collection->getAttribute('documentSecurity', false);
            $validator = new Authorization($permission);

            $valid = $validator->isValid($collection->getPermissionsByType($permission));
            if (($permission === Database::PERMISSION_UPDATE && !$documentSecurity) || !$valid) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            if ($permission === Database::PERMISSION_UPDATE) {
                $valid = $valid || $validator->isValid($document->getUpdate());
                if ($documentSecurity && !$valid) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }
            }

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }

                $isList = \is_array($related) && \array_values($related) === $related;

                if ($isList) {
                    $relations = $related;
                } else {
                    $relations = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($relations as &$relation) {
                    if (
                        \is_array($relation)
                        && \array_values($relation) !== $relation
                        && !isset($relation['$id'])
                    ) {
                        $relation['$id'] = ID::unique();
                        $relation = new Document($relation);
                    }
                    if ($relation instanceof Document) {
                        $current = Authorization::skip(
                            fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(), $relation->getId())
                        );

                        if ($current->isEmpty()) {
                            $type = Database::PERMISSION_CREATE;

                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        } else {
                            $relation->removeAttribute('$collectionId');
                            $relation->removeAttribute('$databaseId');
                            $relation->setAttribute('$collection', $relatedCollection->getId());
                            $type = Database::PERMISSION_UPDATE;
                        }

                        $checkPermissions($relatedCollection, $relation, $type);
                    }
                }

                if ($isList) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $document->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        };

        $documents = \array_map(function ($document) use ($collection, $permissions, $checkPermissions, $isBulk, $documentId, $setPermissions) {
            $document['$collection'] = $collection->getId();

            // Determine the source ID depending on whether it's a bulk operation.
            $sourceId = $isBulk
                ? ($document['$id'] ?? ID::unique())
                : $documentId;

            // If bulk, we need to validate ID explicitly
            if ($isBulk) {
                $validator = new CustomId();
                if (!$validator->isValid($sourceId)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
                }
            }

            // Assign a unique ID if needed, otherwise use the provided ID.
            $document['$id'] = $sourceId === 'unique()' ? ID::unique() : $sourceId;
            $document = new Document($document);
            $setPermissions($document, $permissions);
            $checkPermissions($collection, $document, Database::PERMISSION_CREATE);

            return $document;
        }, $documents);

        try {
            $dbForProject->createDocuments(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                $documents
            );
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (NotFoundException) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database);

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            $document->removeAttribute('$collection');
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        foreach ($documents as $document) {
            $processDocument($collection, $document);
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $operations)); // per collection

        $response->setStatusCode(Response::STATUS_CODE_CREATED);

        if ($isBulk) {
            $response->dynamic(new Document([
                'total' => count($documents),
                'documents' => $documents
            ]), Response::MODEL_DOCUMENT_LIST);

            return;
        }

        $queueForEvents
            ->setParam('documentId', $documents[0]->getId())
            ->setEvent('databases.[databaseId].collections.[collectionId].documents.[documentId].create');

        $response->dynamic(
            $documents[0],
            Response::MODEL_DOCUMENT
        );
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->alias('/v1/database/collections/:collectionId/documents')
    ->desc('List documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'listDocuments',
        description: '/docs/references/databases/list-documents.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage) {
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $documentId = $cursor->getValue();

            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Document '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }
        try {
            $documents = $dbForProject->find('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $queries);
            $total = $dbForProject->count('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $queries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $operations = 0;

        // Add $collectionId and $databaseId for all documents
        $processDocument = (function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database, &$operations): bool {
            if ($document->isEmpty()) {
                return false;
            }

            $operations++;

            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    if (\in_array(\gettype($related), ['array', 'object'])) {
                        $operations++;
                    }

                    continue;
                }

                if (!\is_array($related)) {
                    $relations = [$related];
                } else {
                    $relations = $related;
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                // todo: Use local cache for this getDocument
                $relatedCollection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId));

                foreach ($relations as $index => $doc) {
                    if ($doc instanceof Document) {
                        if (!$processDocument($relatedCollection, $doc)) {
                            unset($relations[$index]);
                        }
                    }
                }

                if (\is_array($related)) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } elseif (empty($relations)) {
                    $document->setAttribute($relationship->getAttribute('key'), null);
                }
            }

            return true;
        });

        foreach ($documents as $document) {
            $processDocument($collection, $document);
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS), \max(1, $operations));

        $select = \array_reduce($queries, function ($result, $query) {
            return $result || ($query->getMethod() === Query::TYPE_SELECT);
        }, false);

        // Check if the SELECT query includes $databaseId and $collectionId
        $hasDatabaseId = false;
        $hasCollectionId = false;
        if ($select) {
            $hasDatabaseId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$databaseId', $query->getValues()));
            }, false);
            $hasCollectionId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$collectionId', $query->getValues()));
            }, false);
        }

        if ($select) {
            foreach ($documents as $document) {
                if (!$hasDatabaseId) {
                    $document->removeAttribute('$databaseId');
                }
                if (!$hasCollectionId) {
                    $document->removeAttribute('$collectionId');
                }
            }
        }

        $response->dynamic(new Document([
            'total' => $total,
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Get document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'getDocument',
        description: '/docs/references/databases/get-document.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, string $documentId, array $queries, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage) {
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        try {
            $document = $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId, $queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $operations = 0;

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database, &$operations) {
            if ($document->isEmpty()) {
                return;
            }

            $operations++;

            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    if (\in_array(\gettype($related), ['array', 'object'])) {
                        $operations++;
                    }

                    continue;
                }

                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS), \max(1, $operations));

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId/logs')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId/logs')
    ->desc('List document logs')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'logs',
        name: 'listDocumentLogs',
        description: '/docs/references/databases/get-document-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, string $collectionId, string $documentId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $document = $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId);

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Temp fix for logs
        $queries[] = Query::or([
            Query::greaterThan('$createdAt', DateTime::format(new \DateTime('2025-02-26T01:30+00:00'))),
            Query::lessThan('$createdAt', DateTime::format(new \DateTime('2025-02-13T00:00+00:00'))),
        ]);

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId . '/collection/' . $collectionId . '/document/' . $document->getId();
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['data']['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource, $queries),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Update document')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'document.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'updateDocument',
        description: '/docs/references/databases/update-document.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object. Include only attribute and value pairs to be updated.', true)
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, string $documentId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data) && \is_null($permissions)) {
            throw new Exception(Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Read permission should not be required for update
        $document = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));
        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = Authorization::getRoles();
        if (!$isAPIKey && !$isPrivilegedUser && !\is_null($permissions)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        if (\is_null($permissions)) {
            $permissions = $document->getPermissions() ?? [];
        }

        $data['$id'] = $documentId;
        $data['$permissions'] = $permissions;
        $newDocument = new Document($data);

        $operations = 0;

        $setCollection = (function (Document $collection, Document $document) use (&$setCollection, $dbForProject, $database, &$operations) {

            $operations++;

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }

                $isList = \is_array($related) && \array_values($related) === $related;

                if ($isList) {
                    $relations = $related;
                } else {
                    $relations = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($relations as &$relation) {
                    // If the relation is an array it can be either update or create a child document.
                    if (
                        \is_array($relation)
                        && \array_values($relation) !== $relation
                        && !isset($relation['$id'])
                    ) {
                        $relation['$id'] = ID::unique();
                        $relation = new Document($relation);
                    }
                    if ($relation instanceof Document) {
                        $oldDocument = Authorization::skip(fn () => $dbForProject->getDocument(
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
                            $relation->getId()
                        ));
                        $relation->removeAttribute('$collectionId');
                        $relation->removeAttribute('$databaseId');
                        // Attribute $collection is required for Utopia.
                        $relation->setAttribute(
                            '$collection',
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence()
                        );

                        if ($oldDocument->isEmpty()) {
                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        }
                        $setCollection($relatedCollection, $relation);
                    }
                }

                if ($isList) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $document->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        });

        $setCollection($collection, $newDocument);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $operations));

        try {
            $document = $dbForProject->updateDocument(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                $document->getId(),
                $newDocument
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->getPayload(), sensitive: $relationships);

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::put('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->desc('Upsert document')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].upsert')
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'document.upsert')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'upsertDocument',
        description: '/docs/references/databases/upsert-document.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new CustomId(), 'Document ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object. Include all required attributes of the document to be created or updated.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, string $documentId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data) && \is_null($permissions)) {
            throw new Exception(Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = Authorization::getRoles();
        if (!$isAPIKey && !$isPrivilegedUser && !\is_null($permissions)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        $data['$id'] = $documentId;
        $data['$permissions'] = $permissions ?? [];
        $newDocument = new Document($data);

        $operations = 0;

        $setCollection = (function (Document $collection, Document $document) use (&$setCollection, $dbForProject, $database, &$operations) {

            $operations++;

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }

                $isList = \is_array($related) && \array_values($related) === $related;

                if ($isList) {
                    $relations = $related;
                } else {
                    $relations = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($relations as &$relation) {
                    // If the relation is an array it can be either update or create a child document.
                    if (
                        \is_array($relation)
                        && \array_values($relation) !== $relation
                        && !isset($relation['$id'])
                    ) {
                        $relation['$id'] = ID::unique();
                        $relation = new Document($relation);
                    }
                    if ($relation instanceof Document) {
                        $oldDocument = Authorization::skip(fn () => $dbForProject->getDocument(
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
                            $relation->getId()
                        ));
                        $relation->removeAttribute('$collectionId');
                        $relation->removeAttribute('$databaseId');
                        // Attribute $collection is required for Utopia.
                        $relation->setAttribute(
                            '$collection',
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence()
                        );

                        if ($oldDocument->isEmpty()) {
                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        }
                        $setCollection($relatedCollection, $relation);
                    }
                }

                if ($isList) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $document->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        });

        $setCollection($collection, $newDocument);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $operations));

        $upserted = [];
        try {
            $modified = $dbForProject->createOrUpdateDocuments(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                [$newDocument],
                onNext: function (Document $document) use (&$upserted) {
                    $upserted[] = $document;
                },
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $document = $upserted[0];
        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->getPayload(), sensitive: $relationships);

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId/:attribute/increment')
    ->desc('Increment document attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].increment')
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'documents.increment')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'incrementDocumentAttribute',
        description: '/docs/references/databases/increment-document-attribute.md',
        auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('attribute', '', new Key(), 'Attribute key.')
    ->param('value', 1, new Numeric(), 'Value to increment the attribute by. The value must be a number.', true)
    ->param('max', null, new Numeric(), 'Maximum value for the attribute. If the current value is greater than this value, an error will be thrown.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, string $documentId, string $attribute, int|float $value, int|float|null $max, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $document = $dbForProject->increaseDocumentAttribute(
                collection: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                id: $documentId,
                attribute: $attribute,
                value: $value,
                max: $max
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (NotFoundException) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        } catch (LimitException) {
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, 'Attribute "' . $attribute . '" has reached the maximum value of ' . $max);
        } catch (TypeException) {
            throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, 'Attribute "' . $attribute . '" is not a number');
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collectionId)
            ->setContext('collection', $collection)
            ->setContext('database', $database);

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId/:attribute/decrement')
    ->desc('Decrement document attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].decrement')
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'documents.decrement')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'decrementDocumentAttribute',
        description: '/docs/references/databases/decrement-document-attribute.md',
        auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('attribute', '', new Key(), 'Attribute key.')
    ->param('value', 1, new Numeric(), 'Value to decrement the attribute by. The value must be a number.', true)
    ->param('min', null, new Numeric(), 'Minimum value for the attribute. If the current value is lesser than this value, an exception will be thrown.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, string $documentId, string $attribute, int|float $value, int|float|null $min, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $document = $dbForProject->decreaseDocumentAttribute(
                collection: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                id: $documentId,
                attribute: $attribute,
                value: $value,
                min: $min
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (NotFoundException) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        } catch (LimitException) {
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, 'Attribute "' . $attribute . '" has reached the minimum value of ' . $min);
        } catch (TypeException) {
            throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, 'Attribute "' . $attribute . '" is not a number');
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collectionId)
            ->setContext('collection', $collection)
            ->setContext('database', $database);

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->desc('Update documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'documents.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'updateDocuments',
        description: '/docs/references/databases/update-documents.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object. Include only attribute and value pairs to be updated.', true)
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->inject('plan')
    ->action(function (string $databaseId, string $collectionId, string|array $data, array $queries, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage, array $plan) {
        $data = \is_string($data)
            ? \json_decode($data, true)
            : $data;

        if (empty($data)) {
            throw new Exception(Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk update is not supported for collections with relationship attributes');
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if ($data['$permissions']) {
            $validator = new Permissions();
            if (!$validator->isValid($data['$permissions'])) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
            }
        }

        $documents = [];

        try {
            $modified = $dbForProject->updateDocuments(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                new Document($data),
                $queries,
                onNext: function (Document $document) use ($plan, &$documents) {
                    if (\count($documents) < ($plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH)) {
                        $documents[] = $document;
                    }
                },
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        foreach ($documents as $document) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $modified))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $modified));

        $response->dynamic(new Document([
            'total' => $modified,
            'documents' => $documents
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::put('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->desc('Upsert documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'documents.upsert')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'upsertDocuments',
        description: '/docs/references/databases/upsert-documents.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documents', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of document data as JSON objects. May contain partial documents.', false, ['plan'])
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->inject('plan')
    ->action(function (string $databaseId, string $collectionId, array $documents, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage, array $plan) {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk upsert is not supported for collections with relationship attributes');
        }

        foreach ($documents as $key => $document) {
            $documents[$key] = new Document($document);
        }

        $upserted = [];

        try {
            $modified = $dbForProject->createOrUpdateDocuments(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                $documents,
                onNext: function (Document $document) use ($plan, &$upserted) {
                    if (\count($upserted) < ($plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH)) {
                        $upserted[] = $document;
                    }
                },
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        foreach ($upserted as $document) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $modified))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $modified));

        $response->dynamic(new Document([
            'total' => $modified,
            'documents' => $upserted
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Delete document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].delete')
    ->label('audits.event', 'document.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{request.documentId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'deleteDocument',
        description: '/docs/references/databases/delete-document.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $collectionId, string $documentId, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Read permission should not be required for delete
        $document = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));
        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $dbForProject->deleteDocument(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                $documentId
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (RestrictedException) {
            throw new Exception(Exception::DOCUMENT_DELETE_RESTRICTED);
        }

        $operations = 0;

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database, &$operations) {
            $operations++;

            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $operations));

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->output($document, Response::MODEL_DOCUMENT), sensitive: $relationships);

        $response->noContent();
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->desc('Delete documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'documents.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'documents',
        name: 'deleteDocuments',
        description: '/docs/references/databases/delete-documents.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->inject('plan')
    ->action(function (string $databaseId, string $collectionId, array $queries, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage, array $plan) {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk delete is not supported for collections with relationship attributes');
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $documents = [];

        try {
            $modified = $dbForProject->deleteDocuments(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                $queries,
                onNext: function (Document $document) use ($plan, &$documents) {
                    if (\count($documents) < ($plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH)) {
                        $documents[] = $document;
                    }
                },
            );
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (RestrictedException) {
            throw new Exception(Exception::DOCUMENT_DELETE_RESTRICTED);
        }

        foreach ($documents as $document) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $modified))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $modified));

        $response->dynamic(new Document([
            'total' => $modified,
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/databases/usage')
    ->desc('Get databases usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: null,
        name: 'getUsage',
        description: '/docs/references/databases/get-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_DATABASES,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), '`Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {
        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_DATABASES,
            METRIC_COLLECTIONS,
            METRIC_DOCUMENTS,
            METRIC_DATABASES_STORAGE,
            METRIC_DATABASES_OPERATIONS_READS,
            METRIC_DATABASES_OPERATIONS_WRITES,
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }
        $response->dynamic(new Document([
            'range' => $range,
            'databasesTotal'   => $usage[$metrics[0]]['total'],
            'collectionsTotal' => $usage[$metrics[1]]['total'],
            'documentsTotal'   => $usage[$metrics[2]]['total'],
            'storageTotal'   => $usage[$metrics[3]]['total'],
            'databasesReadsTotal' => $usage[$metrics[4]]['total'],
            'databasesWritesTotal' => $usage[$metrics[5]]['total'],
            'databases'   => $usage[$metrics[0]]['data'],
            'collections' => $usage[$metrics[1]]['data'],
            'documents'   => $usage[$metrics[2]]['data'],
            'storage'   => $usage[$metrics[3]]['data'],
            'databasesReads' => $usage[$metrics[4]]['data'],
            'databasesWrites' => $usage[$metrics[5]]['data'],
        ]), Response::MODEL_USAGE_DATABASES);
    });

App::get('/v1/databases/:databaseId/usage')
    ->desc('Get database usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: null,
        name: 'getDatabaseUsage',
        description: '/docs/references/databases/get-database-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_DATABASE,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), '`Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $range, Response $response, Database $dbForProject) {
        $database =  $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_COLLECTIONS),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_DOCUMENTS),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_STORAGE),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES)
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'collectionsTotal'   => $usage[$metrics[0]]['total'],
            'documentsTotal'   => $usage[$metrics[1]]['total'],
            'storageTotal'   => $usage[$metrics[2]]['total'],
            'databaseReadsTotal' => $usage[$metrics[3]]['total'],
            'databaseWritesTotal' => $usage[$metrics[4]]['total'],
            'collections'   => $usage[$metrics[0]]['data'],
            'documents'   => $usage[$metrics[1]]['data'],
            'storage'   => $usage[$metrics[2]]['data'],
            'databaseReads'   => $usage[$metrics[3]]['data'],
            'databaseWrites'   => $usage[$metrics[4]]['data'],
        ]), Response::MODEL_USAGE_DATABASE);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/usage')
    ->alias('/v1/database/:collectionId/usage')
    ->desc('Get collection usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: null,
        name: 'getCollectionUsage',
        description: '/docs/references/databases/get-collection-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_COLLECTION,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $range, string $collectionId, Response $response, Database $dbForProject) {
        $database = $dbForProject->getDocument('databases', $databaseId);
        $collectionDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $collectionDocument->getSequence());

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collectionDocument->getSequence()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS),
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' =>  $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'documentsTotal' => $usage[$metrics[0]]['total'],
            'documents' => $usage[$metrics[0]]['data'],
        ]), Response::MODEL_USAGE_COLLECTION);
    });
