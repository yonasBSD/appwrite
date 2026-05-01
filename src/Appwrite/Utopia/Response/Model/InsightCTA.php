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
                'description' => 'Registered server-side action name to execute when this CTA is triggered.',
                'default' => '',
                'example' => 'databases.createIndex',
            ])
            ->addRule('params', [
                'type' => self::TYPE_JSON,
                'description' => 'Parameter map passed to the action when this CTA is triggered.',
                'default' => new \stdClass(),
                'example' => ['databaseId' => 'main', 'collectionId' => 'orders', 'key' => '_idx_status'],
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
