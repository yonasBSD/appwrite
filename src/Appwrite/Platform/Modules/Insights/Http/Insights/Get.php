<?php

namespace Appwrite\Platform\Modules\Insights\Http\Insights;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/insights/:insightId')
            ->desc('Get insight')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.read')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'get',
                description: <<<EOT
                Get an insight by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT,
                    ),
                ]
            ))
            ->param('insightId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $insightId,
        Response $response,
        Database $dbForProject
    ) {
        $insight = $dbForProject->getDocument('insights', $insightId);

        if ($insight->isEmpty()) {
            throw new Exception(Exception::INSIGHT_NOT_FOUND);
        }

        $response->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
