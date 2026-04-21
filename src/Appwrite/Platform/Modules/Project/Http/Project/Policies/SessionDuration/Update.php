<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionDuration;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectSessionDurationPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/session-duration')
            ->httpAlias('/v1/projects/:projectId/auth/duration')
            ->desc('Update session duration policy')
            ->groups(['api', 'project'])
            ->label('scope', 'policies.write')
            ->label('event', 'policies.session-duration.update')
            ->label('audits.event', 'policies.session-duration.update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updateSessionDurationPolicy',
                description: <<<EOT
                Update maximum duration how long sessions created within a project should stay active for.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('duration', null, new Range(60, 31536000), 'Maximum session length in seconds. Minium allowed value is 60 seconds, and maximum is 1 year, which is 31536000 seconds.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        int $duration,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['duration'] = $duration;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
