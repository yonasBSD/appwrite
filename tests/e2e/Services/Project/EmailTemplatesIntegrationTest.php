<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class EmailTemplatesIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testMagicUrlUsesDefaultCustomAndRestoredDefaultTemplate(): void
    {
        $this->clearMaildev();

        $recipientEmail = 'magic-template-' . \uniqid() . '@appwrite.io';

        $this->updateSMTP(['enabled' => false]);

        $firstEmail = $this->triggerMagicUrlAndGetEmail($recipientEmail);
        $defaultSnapshot = $this->normalizeMagicUrlEmail($firstEmail);

        $this->updateSMTP([
            'enabled' => true,
            'senderName' => 'Template Test Mailer',
            'senderEmail' => 'template-test@appwrite.io',
            'host' => 'maildev',
            'port' => 1025,
            'username' => 'user',
            'password' => 'password',
        ]);

        $customSubject = 'Custom magic login ' . \uniqid();
        $customMarker = 'CUSTOM_MAGIC_TEMPLATE_' . \uniqid();
        $this->updateEmailTemplate([
            'templateId' => 'magicSession',
            'locale' => 'en',
            'subject' => $customSubject,
            'message' => '<p>' . $customMarker . '</p><p>{{redirect}}</p>',
        ]);

        $customEmail = $this->triggerMagicUrlAndGetEmail($recipientEmail);
        $this->assertSame($customSubject, $customEmail['subject']);
        $this->assertStringContainsString($customMarker, $customEmail['text']);
        $this->assertStringContainsString($customMarker, $customEmail['html']);

        $defaultTemplate = $this->getConsoleEmailTemplate('magicSession', 'en');
        $this->assertSame(200, $defaultTemplate['headers']['status-code']);

        $this->updateEmailTemplate([
            'templateId' => 'magicSession',
            'locale' => 'en',
            'subject' => $defaultTemplate['body']['subject'],
            'message' => $defaultTemplate['body']['message'],
        ]);

        $restoredEmail = $this->triggerMagicUrlAndGetEmail($recipientEmail);
        $restoredSnapshot = $this->normalizeMagicUrlEmail($restoredEmail);

        $this->assertSame($defaultSnapshot, $restoredSnapshot);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function updateSMTP(array $params): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/smtp', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $params);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function updateEmailTemplate(array $params): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/templates/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $params);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    private function getConsoleEmailTemplate(string $templateId, string $locale): array
    {
        return $this->client->call(Client::METHOD_GET, '/console/templates/email/' . $templateId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], [
            'locale' => $locale,
        ]);
    }

    private function triggerMagicUrlAndGetEmail(string $recipientEmail): array
    {
        $previousCount = $this->countEmailsTo($recipientEmail);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $recipientEmail,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        return $this->getNextEmailByAddress($recipientEmail, $previousCount);
    }

    private function countEmailsTo(string $address): int
    {
        $emails = \json_decode(\file_get_contents('http://maildev:1080/email'), true) ?? [];
        $count = 0;

        foreach ($emails as $email) {
            foreach ($email['to'] ?? [] as $recipient) {
                if (($recipient['address'] ?? '') === $address) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function clearMaildev(): void
    {
        $context = \stream_context_create([
            'http' => [
                'method' => 'DELETE',
            ],
        ]);

        \file_get_contents('http://maildev:1080/email/all', false, $context);
    }

    private function getNextEmailByAddress(string $address, int $previousCount): array
    {
        $result = [];

        $this->assertEventually(function () use (&$result, $address, $previousCount) {
            $emails = \json_decode(\file_get_contents('http://maildev:1080/email'), true) ?? [];
            $matches = [];

            foreach ($emails as $email) {
                foreach ($email['to'] ?? [] as $recipient) {
                    if (($recipient['address'] ?? '') === $address) {
                        $matches[] = $email;
                        break;
                    }
                }
            }

            $this->assertGreaterThan($previousCount, \count($matches), 'Expected a new email for ' . $address);
            $result = $matches[\count($matches) - 1];
        }, 15_000, 500);

        return $result;
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private function normalizeMagicUrlEmail(array $email): array
    {
        return [
            'subject' => $this->normalizeMagicUrlContent($email['subject'] ?? ''),
            'text' => $this->normalizeMagicUrlContent($email['text'] ?? ''),
            'html' => $this->normalizeMagicUrlContent($email['html'] ?? ''),
        ];
    }

    private function normalizeMagicUrlContent(string $content): string
    {
        $content = \html_entity_decode($content, ENT_QUOTES);
        $content = \preg_replace('/([?&](?:secret|expire)=)[^&\s<"]+/', '$1{value}', $content) ?? $content;

        return \trim($content);
    }
}
