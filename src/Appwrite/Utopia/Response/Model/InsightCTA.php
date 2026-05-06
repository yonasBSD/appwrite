<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class InsightCTA extends Model
{
    public function __construct()
    {
        $this
            ->addRule('id', [
                'type' => self::TYPE_STRING,
                'description' => 'CTA identifier, unique within the parent insight.',
                'default' => '',
                'example' => 'createIndex',
            ])
            ->addRule('label', [
                'type' => self::TYPE_STRING,
                'description' => 'Human-readable label for the CTA, used in UI.',
                'default' => '',
                'example' => 'Create missing index',
            ])
            ->addRule('action', [
                'type' => self::TYPE_STRING,
                'description' => 'Public API method the client should invoke when this CTA is triggered. Must match the engine that owns the resource: databases.createIndex (legacy), tablesDB.createIndex, documentsDB.createIndex, or vectorsDB.createIndex for index suggestions.',
                'default' => '',
                'example' => 'tablesDB.createIndex',
            ])
            ->addRule('params', [
                'type' => self::TYPE_JSON,
                'description' => 'Parameter map the client should pass to the action when this CTA is triggered. Keys match the target API\'s parameter names (e.g. databaseId/tableId/columns for tablesDB, databaseId/collectionId/attributes for the legacy Databases API).',
                'default' => new \stdClass(),
                'example' => ['databaseId' => 'main', 'tableId' => 'orders', 'key' => '_idx_status', 'type' => 'key', 'columns' => ['status']],
            ]);
    }

    public function getName(): string
    {
        return 'InsightCTA';
    }

    public function getType(): string
    {
        return Response::MODEL_INSIGHT_CTA;
    }
}
