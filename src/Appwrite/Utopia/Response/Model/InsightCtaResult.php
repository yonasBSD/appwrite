<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class InsightCtaResult extends Model
{
    public function __construct()
    {
        $this
            ->addRule('insightId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the insight the CTA was triggered against.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('ctaId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the CTA that was triggered.',
                'default' => '',
                'example' => 'createIndex',
            ])
            ->addRule('action', [
                'type' => self::TYPE_STRING,
                'description' => 'Registered server-side action that was executed.',
                'default' => '',
                'example' => 'databases.createIndex',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Outcome of the CTA execution. One of succeeded, failed.',
                'default' => 'succeeded',
                'example' => 'succeeded',
            ])
            ->addRule('result', [
                'type' => self::TYPE_JSON,
                'description' => 'Action-specific result data. May reference the resource that was created or updated.',
                'default' => new \stdClass(),
                'example' => ['indexId' => '_idx_status'],
            ]);
    }

    public function getName(): string
    {
        return 'InsightCtaResult';
    }

    public function getType(): string
    {
        return Response::MODEL_INSIGHT_CTA_RESULT;
    }
}
