<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\SMTP\Test;

use Appwrite\Event\Mail;
use Appwrite\Extend\Exception as Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Document;
use Utopia\Emails\Validator\Email;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectSMTPTest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/smtp/tests')
            ->httpAlias('/v1/projects/:projectId/smtp/tests')
            ->desc('Create project SMTP test')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'smtp.*.update')
            ->label('audits.event', 'project.smtp.update')
            ->label('audits.resource', 'project.smtp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'smtp',
                name: 'createSMTPTest',
                description: <<<EOT
                Send a test email to verify SMTP configuration. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
            ))
            ->param('emails', [], new ArrayList(new Email(), 10), 'Array of emails to send test email to. Maximum of 10 emails are allowed.')
            ->inject('response')
            ->inject('project')
            ->inject('queueForMails')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $emails
     */
    public function action(
        array $emails,
        Response $response,
        Document $project,
        Mail $queueForMails
    ): void {

        $smtp = $project->getAttribute('smtp', []);

        if ($smtp['enabled'] !== true) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP must be enabled on the project to send a test email.');
        }

        $senderName = $smtp['senderName'] ?? '';
        $senderEmail = $smtp['senderEmail'] ?? '';
        $replyTo = $smtp['replyTo'] ?? '';
        $host = $smtp['host'] ?? '';
        $port = $smtp['port'] ?? '';
        $username = $smtp['username'] ?? '';
        $password = $smtp['password'] ?? '';
        $secure = $smtp['secure'] ?? '';

        if (empty($senderName)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP sender name must be configured on the project to send a test email.');
        }

        if (empty($senderEmail)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP sender email must be configured on the project to send a test email.');
        }

        if (empty($host)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP host must be configured on the project to send a test email.');
        }

        if (empty($port)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP port must be configured on the project to send a test email.');
        }

        $replyToEmail = !empty($replyTo) ? $replyTo : $senderEmail;

        $subject = 'Custom SMTP email sample';
        $template = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-smtp-test.tpl');
        $template
            ->setParam('{{from}}', "{$senderName} ({$senderEmail})")
            ->setParam('{{replyTo}}', "{$senderName} ({$replyToEmail})")
            ->setParam('{{logoUrl}}', $plan['logoUrl'] ?? APP_EMAIL_LOGO_URL)
            ->setParam('{{accentColor}}', $plan['accentColor'] ?? APP_EMAIL_ACCENT_COLOR)
            ->setParam('{{twitterUrl}}', $plan['twitterUrl'] ?? APP_SOCIAL_TWITTER)
            ->setParam('{{discordUrl}}', $plan['discordUrl'] ?? APP_SOCIAL_DISCORD)
            ->setParam('{{githubUrl}}', $plan['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE)
            ->setParam('{{termsUrl}}', $plan['termsUrl'] ?? APP_EMAIL_TERMS_URL)
            ->setParam('{{privacyUrl}}', $plan['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL);

        foreach ($emails as $email) {
            $queueForMails
                ->setSmtpHost($host)
                ->setSmtpPort($port)
                ->setSmtpUsername($username)
                ->setSmtpPassword($password)
                ->setSmtpSecure($secure)
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName)
                ->setRecipient($email)
                ->setName('')
                ->setBodyTemplate(__DIR__ . '/../../config/locale/templates/email-base-styled.tpl')
                ->setBody($template->render())
                ->setVariables([])
                ->setSubject($subject)
                ->trigger();
        }

        $response->noContent();
    }
}
