<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Insight extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Insight ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Insight creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Insight update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Insight permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => [],
                'example' => ['read("any")'],
                'array' => true,
            ])
            ->addRule('reportId', [
                'type' => self::TYPE_STRING,
                'description' => 'Parent report ID, if this insight belongs to a report. Empty for ad-hoc insights.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Insight type. One of databaseIndex (legacy), tablesDBIndex, documentsDBIndex, vectorsDBIndex, databasePerformance, sitePerformance, siteAccessibility, siteSeo, functionPerformance. The index types are engine-specific so the CTA action can map to the correct public API (databases.createIndex, tablesDB.createIndex, documentsDB.createIndex, or vectorsDB.createIndex).',
                'default' => '',
                'example' => 'tablesDBIndex',
            ])
            ->addRule('severity', [
                'type' => self::TYPE_STRING,
                'description' => 'Insight severity. One of info, warning, critical.',
                'default' => 'info',
                'example' => 'warning',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Insight status. One of active, dismissed.',
                'default' => 'active',
                'example' => 'active',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of the resource the insight is about. Plural noun, e.g. databases, sites, functions.',
                'default' => '',
                'example' => 'databases',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the resource the insight is about.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('resourceInternalId', [
                'type' => self::TYPE_STRING,
                'description' => 'Internal ID of the resource the insight is about.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('title', [
                'type' => self::TYPE_STRING,
                'description' => 'Insight title.',
                'default' => '',
                'example' => 'Missing index on collection orders',
            ])
            ->addRule('summary', [
                'type' => self::TYPE_STRING,
                'description' => 'Short markdown summary describing the insight.',
                'default' => '',
                'example' => 'Queries against `orders.status` are scanning the full collection.',
            ])
            ->addRule('payload', [
                'type' => self::TYPE_JSON,
                'description' => 'Type-specific structured payload for the insight.',
                'default' => new \stdClass(),
                'example' => ['databaseId' => 'main', 'collectionId' => 'orders'],
            ])
            ->addRule('ctas', [
                'type' => Response::MODEL_INSIGHT_CTA,
                'description' => 'List of call-to-action buttons attached to this insight.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('analyzedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Time the insight was analyzed in ISO 8601 format.',
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
                'required' => false,
            ])
            ->addRule('dismissedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Time the insight was dismissed in ISO 8601 format. Empty when not dismissed.',
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
                'required' => false,
            ])
            ->addRule('dismissedBy', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID that dismissed the insight. Empty when not dismissed.',
                'default' => '',
                'example' => '5e5ea5c16897e',
                'required' => false,
            ]);
    }

    public function getName(): string
    {
        return 'Insight';
    }

    public function getType(): string
    {
        return Response::MODEL_INSIGHT;
    }
}
