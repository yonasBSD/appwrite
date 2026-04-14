<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\SMTP\Status;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectSMTPStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/smtp/status')
            ->desc('Update project SMTP status')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'smtp.*.update')
            ->label('audits.event', 'project.smtp.update')
            ->label('audits.resource', 'project.smtp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'smtp',
                name: 'updateSMTPStatus',
                description: <<<EOT
                Update the status of a SMTP. Use this endpoint to enable or disable ability to configure custom email sender in your project. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('enabled', null, new Boolean(), 'SMTP status.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization
    ): void {
        $smtp = $project->getAttribute('smtp', []);

        $smtp['enabled'] = $enabled;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('smtp', $smtp)));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
