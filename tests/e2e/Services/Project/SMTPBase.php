<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait SMTPBase
{
    // Update SMTP status tests

    public function testUpdateSMTPStatusEnable(): void
    {
        $response = $this->updateSMTPStatus(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPStatusDisable(): void
    {
        $this->updateSMTPStatus(true);

        $response = $this->updateSMTPStatus(false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(false, $response['body']['smtpEnabled']);
    }

    public function testUpdateSMTPStatusEnableIdempotent(): void
    {
        $first = $this->updateSMTPStatus(true);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['smtpEnabled']);

        $second = $this->updateSMTPStatus(true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPStatusDisableIdempotent(): void
    {
        $first = $this->updateSMTPStatus(false);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(false, $first['body']['smtpEnabled']);

        $second = $this->updateSMTPStatus(false);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(false, $second['body']['smtpEnabled']);
    }

    public function testUpdateSMTPStatusResponseModel(): void
    {
        $response = $this->updateSMTPStatus(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('smtpEnabled', $response['body']);
        $this->assertArrayHasKey('smtpSenderName', $response['body']);
        $this->assertArrayHasKey('smtpSenderEmail', $response['body']);
        $this->assertArrayHasKey('smtpReplyTo', $response['body']);
        $this->assertArrayHasKey('smtpHost', $response['body']);
        $this->assertArrayHasKey('smtpPort', $response['body']);
        $this->assertArrayHasKey('smtpUsername', $response['body']);
        $this->assertArrayHasKey('smtpPassword', $response['body']);
        $this->assertArrayHasKey('smtpSecure', $response['body']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPStatusWithoutAuthentication(): void
    {
        $response = $this->updateSMTPStatus(true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Update SMTP tests

    public function testUpdateSMTP(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['smtpEnabled']);
        $this->assertSame('Test Sender', $response['body']['smtpSenderName']);
        $this->assertSame('sender@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPWithAllOptionalFields(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Full Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            replyTo: 'reply@example.com',
            username: 'smtpuser',
            password: 'smtppass',
            secure: '',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);
        $this->assertSame('Full Sender', $response['body']['smtpSenderName']);
        $this->assertSame('sender@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('reply@example.com', $response['body']['smtpReplyTo']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);
        $this->assertSame('smtpuser', $response['body']['smtpUsername']);
        $this->assertSame('smtppass', $response['body']['smtpPassword']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPOverwritesPreviousSettings(): void
    {
        $this->updateSMTP(
            senderName: 'First Sender',
            senderEmail: 'first@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->updateSMTP(
            senderName: 'Second Sender',
            senderEmail: 'second@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Second Sender', $response['body']['smtpSenderName']);
        $this->assertSame('second@example.com', $response['body']['smtpSenderEmail']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPEnablesSMTP(): void
    {
        // Ensure SMTP is disabled
        $this->updateSMTPStatus(false);

        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPResponseModel(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('smtpEnabled', $response['body']);
        $this->assertArrayHasKey('smtpSenderName', $response['body']);
        $this->assertArrayHasKey('smtpSenderEmail', $response['body']);
        $this->assertArrayHasKey('smtpReplyTo', $response['body']);
        $this->assertArrayHasKey('smtpHost', $response['body']);
        $this->assertArrayHasKey('smtpPort', $response['body']);
        $this->assertArrayHasKey('smtpUsername', $response['body']);
        $this->assertArrayHasKey('smtpPassword', $response['body']);
        $this->assertArrayHasKey('smtpSecure', $response['body']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPWithoutAuthentication(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            authenticated: false,
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidSenderEmail(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'not-an-email',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPEmptySenderName(): void
    {
        $response = $this->updateSMTP(
            senderName: '',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPEmptySenderEmail(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: '',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPEmptyHost(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: '',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidHost(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'not a valid host!@#',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidReplyToEmail(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            replyTo: 'not-an-email',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidSecure(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            secure: 'invalid',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPValidSecureTLS(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            secure: '',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['smtpSecure']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testUpdateSMTPInvalidConnectionRefused(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'localhost',
            port: 12345,
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_smtp_config_invalid', $response['body']['type']);
    }

    public function testUpdateSMTPBackwardsCompatibilityDisable(): void
    {
        // First enable SMTP
        $this->updateSMTPStatus(true);

        // Use the deprecated enabled=false parameter to disable
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['smtpEnabled']);
    }

    // Create SMTP test tests

    public function testCreateSMTPTest(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest(['recipient@example.com']);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testCreateSMTPTestMultipleRecipients(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest([
            'recipient1@example.com',
            'recipient2@example.com',
            'recipient3@example.com',
        ]);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testCreateSMTPTestWhenSMTPDisabled(): void
    {
        // Ensure SMTP is disabled
        $this->updateSMTPStatus(false);

        $response = $this->createSMTPTest(['recipient@example.com']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateSMTPTestWithoutAuthentication(): void
    {
        $response = $this->createSMTPTest(['recipient@example.com'], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateSMTPTestEmptyEmails(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest([]);

        $this->assertSame(400, $response['headers']['status-code']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testCreateSMTPTestInvalidEmail(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest(['not-an-email']);

        $this->assertSame(400, $response['headers']['status-code']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testCreateSMTPTestExceedsMaxEmails(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $emails = [];
        for ($i = 1; $i <= 11; $i++) {
            $emails[] = "recipient{$i}@example.com";
        }

        $response = $this->createSMTPTest($emails);

        $this->assertSame(400, $response['headers']['status-code']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    public function testCreateSMTPTestMaxEmails(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $emails = [];
        for ($i = 1; $i <= 10; $i++) {
            $emails[] = "recipient{$i}@example.com";
        }

        $response = $this->createSMTPTest($emails);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTPStatus(false);
    }

    // Helpers

    protected function updateSMTPStatus(bool $enabled, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/smtp/status', $headers, [
            'enabled' => $enabled,
        ]);
    }

    protected function updateSMTP(
        string $senderName = '',
        string $senderEmail = '',
        string $host = '',
        int $port = 587,
        string $replyTo = '',
        string $username = '',
        string $password = '',
        string $secure = '',
        ?bool $enabled = null,
        bool $authenticated = true,
    ): mixed {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        $params = [
            'senderName' => $senderName,
            'senderEmail' => $senderEmail,
            'host' => $host,
            'port' => $port,
            'replyTo' => $replyTo,
            'username' => $username,
            'password' => $password,
            'secure' => $secure,
        ];

        if (!\is_null($enabled)) {
            $params['enabled'] = $enabled;
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/smtp', $headers, $params);
    }

    /**
     * @param array<string> $emails
     */
    protected function createSMTPTest(array $emails, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/smtp/tests', $headers, [
            'emails' => $emails,
        ]);
    }
}
