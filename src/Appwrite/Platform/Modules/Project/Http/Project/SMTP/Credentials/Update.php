<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\SMTP\Credentials;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Validator\Email;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectSMTPCredentials';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/smtp/credentials')
            ->httpAlias('/v1/projects/:projectId/smtp')
            ->desc('Update project SMTP details')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'smtp.*.update')
            ->label('audits.event', 'project.smtp.update')
            ->label('audits.resource', 'project.smtp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'smtp',
                name: 'updateSMTPCredentials',
                description: <<<EOT
                Update the SMTP configuration for your project. Use this endpoint to configure your project's SMTP provider with your custom settings for sending transactional emails.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('senderName', '', new Text(256), 'Name of the email sender')
            ->param('senderEmail', '', new Email(), 'Email of the sender')
            ->param('replyTo', '', new Email(), 'Reply to email', true)
            ->param('host', '', new Hostname(), 'SMTP server host name')
            ->param('port', 587, new Integer(), 'SMTP server port')
            ->param('username', '', new Text(256), 'SMTP server username', true)
            ->param('password', '', new Text(256), 'SMTP server password', true)
            ->param('secure', '', new WhiteList(['tls', 'ssl'], true), 'Does SMTP server use secure connection', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Enable custom SMTP service', optional: true, deprecated: true) // Backwards compatibility
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }


    public function action(
        string $senderName,
        string $senderEmail,
        string $replyTo,
        string $host,
        int $port,
        string $username,
        string $password,
        string $secure,
        ?bool $enabled, // Backwards compatibility
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization
    ): void {
        // Backwards compatibility
        if (!\is_null($enabled) && $enabled === false) {
            $smtp = $project->getAttribute('smtp', []);

            $smtp['enabled'] = $enabled;

            $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('smtp', $smtp)));

            $response->dynamic($project, Response::MODEL_PROJECT);

            return;
        }

        // Validate SMTP settings
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth = (!empty($username) && !empty($password));
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPSecure = $secure;
        $mail->SMTPAutoTLS = false;
        $mail->Timeout = 5;

        try {
            $valid = $mail->SmtpConnect();

            if (!$valid) {
                throw new \Exception('Connection is not valid.');
            }
        } catch (Throwable $error) {
            throw new Exception(Exception::PROJECT_SMTP_CONFIG_INVALID, $error->getMessage());
        }

        $smtp = [
            'enabled' => true,
            'senderName' => $senderName,
            'senderEmail' => $senderEmail,
            'replyTo' => $replyTo,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'secure' => $secure,
        ];

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('smtp', $smtp)));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
