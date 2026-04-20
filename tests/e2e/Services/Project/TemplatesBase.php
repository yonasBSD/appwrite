<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait TemplatesBase
{
    // =========================================================================
    // Get email template tests
    // =========================================================================

    public function testGetEmailTemplateDefault(): void
    {
        $template = $this->getEmailTemplate('verification', 'en');

        $this->assertSame(200, $template['headers']['status-code']);
        $this->assertSame('verification', $template['body']['templateId']);
        $this->assertSame('en', $template['body']['locale']);
        $this->assertFalse($template['body']['custom']);
        $this->assertNotEmpty($template['body']['subject']);
        $this->assertNotEmpty($template['body']['message']);
    }

    public function testGetEmailTemplateDefaultLocale(): void
    {
        $template = $this->getEmailTemplate('verification');

        $this->assertSame(200, $template['headers']['status-code']);
        $this->assertSame('verification', $template['body']['templateId']);
        $this->assertSame('en', $template['body']['locale']);
        $this->assertFalse($template['body']['custom']);
    }

    public function testGetEmailTemplateCustom(): void
    {
        $update = $this->updateEmailTemplate('magicSession', 'en', 'Magic Subject', 'Magic Body');
        $this->assertSame(200, $update['headers']['status-code']);

        $get = $this->getEmailTemplate('magicSession', 'en');

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('magicSession', $get['body']['templateId']);
        $this->assertSame('en', $get['body']['locale']);
        $this->assertTrue($get['body']['custom']);
        $this->assertSame('Magic Subject', $get['body']['subject']);
        $this->assertSame('Magic Body', $get['body']['message']);

        // Cleanup
        $this->deleteEmailTemplate('magicSession', 'en');
    }

    public function testGetEmailTemplateInvalidType(): void
    {
        $template = $this->getEmailTemplate('notATemplate', 'en');

        $this->assertSame(400, $template['headers']['status-code']);
    }

    public function testGetEmailTemplateInvalidLocale(): void
    {
        $template = $this->getEmailTemplate('verification', 'not-a-locale');

        $this->assertSame(400, $template['headers']['status-code']);
    }

    public function testGetEmailTemplateWithoutAuthentication(): void
    {
        $template = $this->getEmailTemplate('verification', 'en', false);

        $this->assertSame(401, $template['headers']['status-code']);
    }

    // =========================================================================
    // Update email template tests
    // =========================================================================

    public function testUpdateEmailTemplate(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Please verify your email',
            'Click here to verify: {{url}}',
        );

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('verification', $update['body']['templateId']);
        $this->assertSame('en', $update['body']['locale']);
        $this->assertSame('Please verify your email', $update['body']['subject']);
        $this->assertSame('Click here to verify: {{url}}', $update['body']['message']);
        $this->assertTrue($update['body']['custom']);

        // Verify persisted via GET
        $get = $this->getEmailTemplate('verification', 'en');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Please verify your email', $get['body']['subject']);
        $this->assertSame('Click here to verify: {{url}}', $get['body']['message']);
        $this->assertTrue($get['body']['custom']);

        // Cleanup
        $this->deleteEmailTemplate('verification', 'en');
    }

    public function testUpdateEmailTemplateWithOptionalFields(): void
    {
        $update = $this->updateEmailTemplate(
            'invitation',
            'en',
            'Team invitation',
            'You have been invited',
            'Appwrite Team',
            'team@appwrite.io',
            'reply@appwrite.io',
        );

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('Team invitation', $update['body']['subject']);
        $this->assertSame('You have been invited', $update['body']['message']);
        $this->assertSame('Appwrite Team', $update['body']['senderName']);
        $this->assertSame('team@appwrite.io', $update['body']['senderEmail']);
        $this->assertSame('reply@appwrite.io', $update['body']['replyToEmail']);

        // Cleanup
        $this->deleteEmailTemplate('invitation', 'en');
    }

    public function testUpdateEmailTemplateDefaultLocale(): void
    {
        $update = $this->updateEmailTemplate(
            'sessionAlert',
            null,
            'Session alert',
            'Someone signed in',
        );

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('sessionAlert', $update['body']['templateId']);
        $this->assertSame('en', $update['body']['locale']);

        // Cleanup
        $this->deleteEmailTemplate('sessionAlert', 'en');
    }

    public function testUpdateEmailTemplateOverwrite(): void
    {
        $this->updateEmailTemplate('otpSession', 'en', 'First', 'First body');

        $second = $this->updateEmailTemplate('otpSession', 'en', 'Second', 'Second body');

        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame('Second', $second['body']['subject']);
        $this->assertSame('Second body', $second['body']['message']);

        $get = $this->getEmailTemplate('otpSession', 'en');
        $this->assertSame('Second', $get['body']['subject']);

        // Cleanup
        $this->deleteEmailTemplate('otpSession', 'en');
    }

    public function testUpdateEmailTemplateInvalidType(): void
    {
        $update = $this->updateEmailTemplate('notATemplate', 'en', 'Subject', 'Message');

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateMissingSubject(): void
    {
        $update = $this->updateEmailTemplate('verification', 'en', null, 'Message only');

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateMissingMessage(): void
    {
        $update = $this->updateEmailTemplate('verification', 'en', 'Subject only', null);

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidSenderEmail(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Subject',
            'Message',
            'Sender',
            'not-an-email',
        );

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidReplyTo(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Subject',
            'Message',
            null,
            null,
            'not-an-email',
        );

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateWithoutAuthentication(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Subject',
            'Message',
            null,
            null,
            null,
            false,
        );

        $this->assertSame(401, $update['headers']['status-code']);
    }

    // =========================================================================
    // Delete email template tests
    // =========================================================================

    public function testDeleteEmailTemplate(): void
    {
        $update = $this->updateEmailTemplate('mfaChallenge', 'en', 'MFA', 'Enter code');
        $this->assertSame(200, $update['headers']['status-code']);

        $customBefore = $this->getEmailTemplate('mfaChallenge', 'en');
        $this->assertTrue($customBefore['body']['custom']);

        $delete = $this->deleteEmailTemplate('mfaChallenge', 'en');
        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify reset back to default
        $after = $this->getEmailTemplate('mfaChallenge', 'en');
        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertFalse($after['body']['custom']);
        $this->assertNotSame('MFA', $after['body']['subject']);
    }

    public function testDeleteEmailTemplateDefault(): void
    {
        // Attempt to delete a template that was never customized
        $delete = $this->deleteEmailTemplate('verification', 'fr');

        $this->assertSame(401, $delete['headers']['status-code']);
        $this->assertSame('project_template_default_deletion', $delete['body']['type']);
    }

    public function testDeleteEmailTemplateInvalidType(): void
    {
        $delete = $this->deleteEmailTemplate('notATemplate', 'en');

        $this->assertSame(400, $delete['headers']['status-code']);
    }

    public function testDeleteEmailTemplateWithoutAuthentication(): void
    {
        $update = $this->updateEmailTemplate('recovery', 'en', 'Recovery', 'Reset password');
        $this->assertSame(200, $update['headers']['status-code']);

        $delete = $this->deleteEmailTemplate('recovery', 'en', false);

        $this->assertSame(401, $delete['headers']['status-code']);

        // Verify still customized
        $get = $this->getEmailTemplate('recovery', 'en');
        $this->assertTrue($get['body']['custom']);

        // Cleanup
        $this->deleteEmailTemplate('recovery', 'en');
    }

    // =========================================================================
    // Legacy response format tests (request + response filters)
    // =========================================================================

    public function testGetEmailTemplateLegacyResponseFormat(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $template = $this->client->call(
            Client::METHOD_GET,
            '/project/templates/email/verification',
            $headers,
        );

        $this->assertSame(200, $template['headers']['status-code']);
        // Response filter should rename templateId -> type for < 1.9.2 clients.
        $this->assertArrayHasKey('type', $template['body']);
        $this->assertArrayNotHasKey('templateId', $template['body']);
        $this->assertSame('verification', $template['body']['type']);
        $this->assertSame('en', $template['body']['locale']);
    }

    public function testUpdateEmailTemplateLegacyResponseFormat(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        // Request filter should accept legacy `type` and map it to `templateId`.
        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/project/templates/email',
            $headers,
            [
                'type' => 'magicSession',
                'locale' => 'en',
                'subject' => 'Legacy Subject',
                'message' => 'Legacy Body',
            ],
        );

        $this->assertSame(200, $update['headers']['status-code']);
        // Response filter should rename templateId -> type for < 1.9.2 clients.
        $this->assertArrayHasKey('type', $update['body']);
        $this->assertArrayNotHasKey('templateId', $update['body']);
        $this->assertSame('magicSession', $update['body']['type']);
        $this->assertSame('Legacy Subject', $update['body']['subject']);
        $this->assertSame('Legacy Body', $update['body']['message']);
        $this->assertTrue($update['body']['custom']);

        // Verify persisted, then cleanup via legacy DELETE with `type`.
        $get = $this->getEmailTemplate('magicSession', 'en');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['custom']);

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/project/templates/email',
            $headers,
            [
                'type' => 'magicSession',
                'locale' => 'en',
            ],
        );
        $this->assertSame(204, $delete['headers']['status-code']);

        $after = $this->getEmailTemplate('magicSession', 'en');
        $this->assertFalse($after['body']['custom']);
    }

    public function testDeleteEmailTemplateLegacyResponseFormat(): void
    {
        // Seed a custom template using the current API.
        $update = $this->updateEmailTemplate('otpSession', 'en', 'Legacy OTP', 'Legacy OTP body');
        $this->assertSame(200, $update['headers']['status-code']);

        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        // Request filter should accept legacy `type` and map it to `templateId`.
        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/project/templates/email',
            $headers,
            [
                'type' => 'otpSession',
                'locale' => 'en',
            ],
        );

        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify reset back to default.
        $after = $this->getEmailTemplate('otpSession', 'en');
        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertFalse($after['body']['custom']);
        $this->assertNotSame('Legacy OTP', $after['body']['subject']);
    }

    public function testDeleteEmailTemplateLegacyInvalidType(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/project/templates/email',
            $headers,
            [
                'type' => 'notATemplate',
                'locale' => 'en',
            ],
        );

        $this->assertSame(400, $delete['headers']['status-code']);
    }

    public function testUpdateEmailTemplateLegacyInvalidType(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/project/templates/email',
            $headers,
            [
                'type' => 'notATemplate',
                'locale' => 'en',
                'subject' => 'Subject',
                'message' => 'Message',
            ],
        );

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateLegacyReplyTo(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        // Legacy clients send replyTo (not replyToEmail) — request filter maps it.
        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/project/templates/email',
            $headers,
            [
                'type' => 'invitation',
                'locale' => 'en',
                'subject' => 'Legacy reply-to subject',
                'message' => 'Legacy reply-to body',
                'senderName' => 'Legacy Sender',
                'senderEmail' => 'legacy-sender@appwrite.io',
                'replyTo' => 'legacy-reply@appwrite.io',
            ],
        );

        $this->assertSame(200, $update['headers']['status-code']);
        // Response filter should rename replyToEmail -> replyTo, strip replyToName / custom.
        $this->assertArrayHasKey('replyTo', $update['body']);
        $this->assertArrayNotHasKey('replyToEmail', $update['body']);
        $this->assertArrayNotHasKey('replyToName', $update['body']);
        $this->assertArrayNotHasKey('custom', $update['body']);
        $this->assertSame('legacy-reply@appwrite.io', $update['body']['replyTo']);
        $this->assertSame('Legacy Sender', $update['body']['senderName']);
        $this->assertSame('legacy-sender@appwrite.io', $update['body']['senderEmail']);

        // Verify value is persisted and readable via the legacy GET shape.
        $get = $this->client->call(
            Client::METHOD_GET,
            '/project/templates/email/invitation',
            $headers,
            ['locale' => 'en'],
        );
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertArrayHasKey('replyTo', $get['body']);
        $this->assertArrayNotHasKey('replyToEmail', $get['body']);
        $this->assertArrayNotHasKey('replyToName', $get['body']);
        $this->assertArrayNotHasKey('custom', $get['body']);
        $this->assertSame('legacy-reply@appwrite.io', $get['body']['replyTo']);

        // Cleanup
        $this->deleteEmailTemplate('invitation', 'en');
    }

    public function testGetEmailTemplateLegacyReplyTo(): void
    {
        // Seed a custom template using the current API (includes replyToEmail + replyToName).
        $update = $this->updateEmailTemplate(
            'otpSession',
            'en',
            'Legacy OTP',
            'Legacy OTP body',
            'Legacy Sender',
            'legacy-sender@appwrite.io',
            'legacy-reply@appwrite.io',
            'Legacy Reply Team',
        );
        $this->assertSame(200, $update['headers']['status-code']);

        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $get = $this->client->call(
            Client::METHOD_GET,
            '/project/templates/email/otpSession',
            $headers,
            ['locale' => 'en'],
        );

        $this->assertSame(200, $get['headers']['status-code']);
        // Legacy fields present
        $this->assertArrayHasKey('type', $get['body']);
        $this->assertArrayHasKey('replyTo', $get['body']);
        $this->assertSame('otpSession', $get['body']['type']);
        $this->assertSame('legacy-reply@appwrite.io', $get['body']['replyTo']);
        // New fields stripped
        $this->assertArrayNotHasKey('templateId', $get['body']);
        $this->assertArrayNotHasKey('replyToEmail', $get['body']);
        $this->assertArrayNotHasKey('replyToName', $get['body']);
        $this->assertArrayNotHasKey('custom', $get['body']);

        // Cleanup
        $this->deleteEmailTemplate('otpSession', 'en');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function getEmailTemplate(string $type, ?string $locale = null, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = [];
        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        return $this->client->call(Client::METHOD_GET, '/project/templates/email/' . $type, $headers, $params);
    }

    protected function updateEmailTemplate(
        string $type,
        ?string $locale,
        ?string $subject,
        ?string $message,
        ?string $senderName = null,
        ?string $senderEmail = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        bool $authenticated = true,
    ): mixed {
        $params = [
            'templateId' => $type,
        ];

        if ($locale !== null) {
            $params['locale'] = $locale;
        }
        if ($subject !== null) {
            $params['subject'] = $subject;
        }
        if ($message !== null) {
            $params['message'] = $message;
        }
        if ($senderName !== null) {
            $params['senderName'] = $senderName;
        }
        if ($senderEmail !== null) {
            $params['senderEmail'] = $senderEmail;
        }
        if ($replyToEmail !== null) {
            $params['replyToEmail'] = $replyToEmail;
        }
        if ($replyToName !== null) {
            $params['replyToName'] = $replyToName;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/templates/email', $headers, $params);
    }

    protected function deleteEmailTemplate(string $type, ?string $locale = null, bool $authenticated = true): mixed
    {
        $params = [
            'templateId' => $type,
        ];

        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_DELETE, '/project/templates/email', $headers, $params);
    }
}
