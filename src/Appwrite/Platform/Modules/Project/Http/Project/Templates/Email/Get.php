<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectEmailTemplate';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email/:type/:locale')
            ->desc('Get project email template')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'getEmailTemplate',
                description: <<<EOT
                Get a custom email template for the specified locale and type. This endpoint returns the template content, subject, and other configuration details.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE,
                    )
                ]
            ))
            ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Custom email template type. Can be one of: '.\implode(', ', Config::getParam('locale-templates')['email'] ?? []))
            ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Custom email template locale.', optional: true, injections: ['localeCodes'])
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(
        string $type,
        string $locale,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        Locale $localeObject,
    ) {
        $locale = $locale ?: $localeObject->default ?: $localeObject->fallback ?: System::getEnv('_APP_LOCALE', 'en');

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['email.' . $type . '-' . $locale] ?? null;

        $localeObj = new Locale($locale);
        $localeObj->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        if (is_null($template)) {
            /**
             * different templates, different placeholders.
             */
            $templateConfigs = [
                'magicSession' => [
                    'file' => 'email-magic-url.tpl',
                    'placeholders' => ['optionButton', 'buttonText', 'optionUrl', 'clientInfo', 'securityPhrase']
                ],
                'mfaChallenge' => [
                    'file' => 'email-mfa-challenge.tpl',
                    'placeholders' => ['description', 'clientInfo']
                ],
                'otpSession' => [
                    'file' => 'email-otp.tpl',
                    'placeholders' => ['description', 'clientInfo', 'securityPhrase']
                ],
                'sessionAlert' => [
                    'file' => 'email-session-alert.tpl',
                    'placeholders' => ['body', 'listDevice', 'listIpAddress', 'listCountry', 'footer']
                ],
            ];

            // fallback to the base template.
            $config = $templateConfigs[$type] ?? [
                'file' => 'email-inner-base.tpl',
                'placeholders' => ['buttonText', 'body', 'footer']
            ];

            $templateString = file_get_contents(__DIR__ . '/../../config/locale/templates/' . $config['file']);

            // We use `fromString` due to the replace above
            $message = Template::fromString($templateString);

            // Set type-specific parameters
            foreach ($config['placeholders'] as $param) {
                $escapeHtml = !in_array($param, ['clientInfo', 'body', 'footer', 'description']);
                $message->setParam("{{{$param}}}", $localeObj->getText("emails.{$type}.{$param}"), escapeHtml: $escapeHtml);
            }

            $message
                // common placeholders on all the templates
                ->setParam('{{hello}}', $localeObj->getText("emails.{$type}.hello"))
                ->setParam('{{thanks}}', $localeObj->getText("emails.{$type}.thanks"))
                ->setParam('{{signature}}', $localeObj->getText("emails.{$type}.signature"));

            // `useContent: false` will strip new lines!
            $message = $message->render(useContent: true);

            $template = [
                'message' => $message,
                'subject' => $localeObj->getText('emails.' . $type . '.subject'),
                'senderEmail' => '',
                'senderName' => '',
                'custom' => false,
            ];
        } else {
            $template['custom'] = true;
        }

        $template['type'] = $type;
        $template['locale'] = $locale;

        $response->dynamic(new Document($template), Response::MODEL_EMAIL_TEMPLATE);
    }
}
