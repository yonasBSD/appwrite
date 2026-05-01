<?php

namespace Appwrite\Platform\Modules\Insights\Http\Insights;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Dismiss extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'dismissInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/insights/:insightId/dismiss')
            ->desc('Dismiss insight')
            ->groups(['api', 'insights'])
            ->label('scope', 'insights.write')
            ->label('event', 'insights.[insightId].dismiss')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('audits.event', 'insight.dismiss')
            ->label('audits.resource', 'insight/{response.$id}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'insights',
                group: 'insights',
                name: 'dismiss',
                description: <<<EOT
                Dismiss an insight. Stamps the current user and time on the insight without deleting it, so analyzers can see it has been acknowledged.
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
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $insightId,
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $insight = $dbForProject->getDocument('insights', $insightId);

        if ($insight->isEmpty()) {
            throw new Exception(Exception::INSIGHT_NOT_FOUND);
        }

        $insight = $dbForProject->updateDocument('insights', $insight->getId(), new Document([
            'dismissedAt' => DateTime::now(),
            'dismissedBy' => $user->getId(),
        ]));

        $queueForEvents->setParam('insightId', $insight->getId());

        $response->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
