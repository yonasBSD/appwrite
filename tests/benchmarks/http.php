<?php

declare(strict_types=1);

final class BenchmarkResponse
{
    private mixed $json = null;
    private bool $jsonParsed = false;

    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly float $duration,
    ) {
    }

    public function json(?string $key = null): mixed
    {
        if (!$this->jsonParsed) {
            $this->json = json_decode($this->body, true);
            $this->jsonParsed = true;
        }

        if ($key === null) {
            return $this->json;
        }

        return is_array($this->json) ? ($this->json[$key] ?? null) : null;
    }

    public function header(string $name): string
    {
        $key = strtolower($name);
        return isset($this->headers[$key]) ? implode(', ', $this->headers[$key]) : '';
    }

    public function cookieHeader(): string
    {
        $cookies = [];

        foreach ($this->headers['set-cookie'] ?? [] as $cookie) {
            $cookies[] = explode(';', $cookie, 2)[0];
        }

        return implode('; ', $cookies);
    }
}

final class BenchmarkMetrics
{
    private array $trends = [];
    private array $counters = [
        'appwrite_benchmark_flow_failures' => 0,
    ];
    private int $checksPassed = 0;
    private int $checksFailed = 0;

    public function addTrend(string $name, float $value): void
    {
        $this->trends[$name] ??= [];
        $this->trends[$name][] = $value;
    }

    public function addCounter(string $name, int $value = 1): void
    {
        $this->counters[$name] ??= 0;
        $this->counters[$name] += $value;
    }

    public function addCheck(bool $passed): void
    {
        if ($passed) {
            $this->checksPassed++;
            return;
        }

        $this->checksFailed++;
    }

    public function summary(): array
    {
        $metrics = [];

        foreach ($this->trends as $name => $values) {
            $metrics[$name] = [
                'type' => 'trend',
                'contains' => 'time',
                'values' => $this->trendValues($values),
            ];
        }

        foreach ($this->counters as $name => $count) {
            $metrics[$name] = [
                'type' => 'counter',
                'contains' => 'default',
                'values' => [
                    'count' => $count,
                ],
            ];
        }

        $totalChecks = $this->checksPassed + $this->checksFailed;
        $metrics['checks'] = [
            'type' => 'rate',
            'contains' => 'default',
            'values' => [
                'rate' => $totalChecks > 0 ? $this->checksPassed / $totalChecks : 1,
                'passes' => $this->checksPassed,
                'fails' => $this->checksFailed,
            ],
        ];

        return ['metrics' => $metrics];
    }

    public function failedChecks(): int
    {
        return $this->checksFailed;
    }

    public function flowFailures(): int
    {
        return $this->counters['appwrite_benchmark_flow_failures'] ?? 0;
    }

    private function trendValues(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        if ($count === 0) {
            return [
                'count' => 0,
                'min' => null,
                'avg' => null,
                'med' => null,
                'max' => null,
                'p(90)' => null,
                'p(95)' => null,
            ];
        }

        return [
            'count' => $count,
            'min' => $values[0],
            'avg' => array_sum($values) / $count,
            'med' => $this->percentile($values, 50),
            'max' => $values[$count - 1],
            'p(90)' => $this->percentile($values, 90),
            'p(95)' => $this->percentile($values, 95),
        ];
    }

    private function percentile(array $sortedValues, int $percentile): float
    {
        $count = count($sortedValues);

        if ($count === 1) {
            return (float) $sortedValues[0];
        }

        $rank = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return (float) $sortedValues[$lower];
        }

        $weight = $rank - $lower;
        return (float) ($sortedValues[$lower] + (($sortedValues[$upper] - $sortedValues[$lower]) * $weight));
    }
}

final class HttpBenchmark
{
    private const API_SCOPES = [
        'sessions.write',
        'users.read',
        'users.write',
        'teams.read',
        'teams.write',
        'databases.read',
        'databases.write',
        'collections.read',
        'collections.write',
        'tables.read',
        'tables.write',
        'attributes.read',
        'attributes.write',
        'columns.read',
        'columns.write',
        'indexes.read',
        'indexes.write',
        'documents.read',
        'documents.write',
        'rows.read',
        'rows.write',
        'files.read',
        'files.write',
        'buckets.read',
        'buckets.write',
        'functions.read',
        'functions.write',
        'sites.read',
        'sites.write',
        'log.read',
        'log.write',
        'execution.read',
        'execution.write',
        'locale.read',
        'avatars.read',
        'health.read',
        'providers.read',
        'providers.write',
        'messages.read',
        'messages.write',
        'topics.read',
        'topics.write',
        'subscribers.read',
        'subscribers.write',
        'targets.read',
        'targets.write',
        'rules.read',
        'rules.write',
        'migrations.read',
        'migrations.write',
        'vcs.read',
        'vcs.write',
        'assistant.read',
        'tokens.read',
        'tokens.write',
        'platforms.read',
        'platforms.write',
    ];

    private const BASE_PERMISSIONS = [
        'read("any")',
        'create("any")',
        'update("any")',
        'delete("any")',
    ];

    private const ITEM_PERMISSIONS = [
        'read("any")',
        'update("any")',
        'delete("any")',
    ];

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAXpeqz8AAAAASUVORK5CYII=';

    private BenchmarkMetrics $metrics;
    private string $endpoint;
    private string $maildevEndpoint;
    private string $consoleProject;
    private string $region;
    private string $redirectUrl;
    private string $password;
    private int $mailTimeoutMs;
    private int $workerTimeoutMs;
    private int $iterations;
    private int $runs;
    private string $summaryPath;
    private ?array $previousSummary;

    public function __construct()
    {
        $this->metrics = new BenchmarkMetrics();
        $this->endpoint = rtrim($this->env('APPWRITE_ENDPOINT', 'http://localhost/v1'), '/');
        $this->maildevEndpoint = $this->env('APPWRITE_MAILDEV_ENDPOINT', 'http://localhost:9503/email');
        $this->consoleProject = $this->env('APPWRITE_CONSOLE_PROJECT', 'console');
        $this->region = $this->env('APPWRITE_REGION', 'default');
        $this->redirectUrl = $this->env('APPWRITE_BENCHMARK_REDIRECT_URL', 'http://localhost');
        $this->password = $this->env('APPWRITE_BENCHMARK_PASSWORD', 'Password123!');
        $this->mailTimeoutMs = (int) $this->env('APPWRITE_MAIL_TIMEOUT_MS', '20000');
        $this->workerTimeoutMs = (int) $this->env('APPWRITE_WORKER_TIMEOUT_MS', '60000');
        $this->iterations = max(1, (int) $this->env('APPWRITE_BENCHMARK_ITERATIONS', '1'));
        $this->runs = max(1, (int) $this->env('APPWRITE_BENCHMARK_RUNS', $this->env('APPWRITE_BENCHMARK_VUS', '1')));
        $this->summaryPath = $this->env('APPWRITE_BENCHMARK_SUMMARY_PATH', 'tests/benchmarks/http-summary.json');
        $this->previousSummary = $this->loadPreviousSummary($this->env('APPWRITE_BENCHMARK_PREVIOUS_SUMMARY_PATH', $this->summaryPath));
    }

    public function run(): int
    {
        $context = null;
        $exitCode = 0;

        try {
            $context = $this->setup();

            for ($i = 0; $i < $this->iterations * $this->runs; $i++) {
                try {
                    $this->curatedFlows($context);
                } catch (Throwable $error) {
                    $exitCode = 1;
                    fwrite(STDERR, 'Iteration ' . ($i + 1) . ' failed: ' . $error->getMessage() . PHP_EOL);
                }
            }
        } catch (Throwable $error) {
            $exitCode = 1;
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
        } finally {
            if (is_array($context)) {
                try {
                    $this->teardown($context);
                } catch (Throwable $error) {
                    $exitCode = 1;
                    fwrite(STDERR, 'Teardown failed: ' . $error->getMessage() . PHP_EOL);
                }
            }

            $summary = $this->metrics->summary();
            echo $this->renderSummary($summary);
            try {
                $this->writeSummary($summary);
            } catch (Throwable $error) {
                $exitCode = 1;
                fwrite(STDERR, $error->getMessage() . PHP_EOL);
            }
        }

        if ($this->metrics->failedChecks() > 0 || $this->metrics->flowFailures() > 0) {
            $exitCode = 1;
        }

        return $exitCode;
    }

    private function setup(): array
    {
        $runId = $this->unique('run');
        $consoleEmail = $this->env('APPWRITE_ADMIN_EMAIL', "bench-admin-{$runId}@example.com");
        $consolePassword = $this->env('APPWRITE_ADMIN_PASSWORD', $this->password);
        $consoleHeaders = [
            'Content-Type' => 'application/json',
            'X-Appwrite-Project' => $this->consoleProject,
        ];

        $account = $this->rawRequest('POST', '/account', [
            'userId' => $this->unique('admin'),
            'email' => $consoleEmail,
            'password' => $consolePassword,
            'name' => 'Benchmark Admin',
        ], $consoleHeaders, 'setup.account.create');

        if (!in_array($account->status, [201, 409], true)) {
            $this->failResponse($account, 'Unable to create or reuse the benchmark console account');
        }

        $session = $this->rawRequest('POST', '/account/sessions/email', [
            'email' => $consoleEmail,
            'password' => $consolePassword,
        ], $consoleHeaders, 'setup.account.session');
        $this->assertStatus($session, [201], 'console session created');

        $consoleSessionHeaders = [
            ...$consoleHeaders,
            'Cookie' => $session->cookieHeader(),
        ];

        $team = $this->api('POST', '/teams', [
            'teamId' => $this->unique('team'),
            'name' => "Benchmark Team {$runId}",
        ], $consoleSessionHeaders, [201], 'setup.teams.create');

        $teamId = (string) $team->json('$id');
        $project = $this->api('POST', '/projects', [
            'projectId' => $this->unique('project'),
            'name' => "Benchmark Project {$runId}",
            'teamId' => $teamId,
            'region' => $this->region,
        ], $consoleSessionHeaders, [201], 'setup.projects.create');

        $projectId = (string) $project->json('$id');
        $key = $this->api('POST', "/projects/{$projectId}/keys", [
            'keyId' => $this->unique('key'),
            'name' => 'Benchmark API key',
            'scopes' => self::API_SCOPES,
        ], $consoleSessionHeaders, [201], 'setup.projects.keys.create');

        $apiHeaders = [
            'Content-Type' => 'application/json',
            'X-Appwrite-Project' => $projectId,
            'X-Appwrite-Key' => (string) $key->json('secret'),
        ];

        $platform = $this->api('POST', '/project/platforms/web', [
            'platformId' => $this->unique('web'),
            'name' => 'Benchmark web',
            'hostname' => $this->hostnameFromUrl($this->redirectUrl),
        ], $apiHeaders, [201, 409], 'setup.project.platforms.web.create');

        $smtpBody = [
            'enabled' => true,
            'senderName' => 'Benchmark',
            'senderEmail' => 'benchmark@appwrite.io',
            'replyTo' => 'benchmark@appwrite.io',
            'host' => $this->env('APPWRITE_SMTP_HOST', 'maildev'),
            'port' => (int) $this->env('APPWRITE_SMTP_PORT', '1025'),
            'username' => $this->env('APPWRITE_SMTP_USERNAME', 'user'),
            'password' => $this->env('APPWRITE_SMTP_PASSWORD', 'password'),
        ];

        if ($this->env('APPWRITE_SMTP_SECURE', '') !== '') {
            $smtpBody['secure'] = $this->env('APPWRITE_SMTP_SECURE', '');
        }

        $smtp = $this->rawRequest('PATCH', "/projects/{$projectId}/smtp", $smtpBody, $consoleSessionHeaders, 'setup.projects.smtp.update');
        if ($smtp->status !== 200) {
            fwrite(STDERR, "Custom SMTP was not enabled ({$smtp->status}). Mail worker timings may be unavailable." . PHP_EOL);
        }

        return [
            'runId' => $runId,
            'teamId' => $teamId,
            'projectId' => $projectId,
            'consoleSessionHeaders' => $consoleSessionHeaders,
            'apiHeaders' => $apiHeaders,
            'platformStatus' => $platform->status,
        ];
    }

    private function curatedFlows(array &$context): void
    {
        try {
            $this->accountFlow($context);
            $this->databasesFlow($context);
            $this->tablesDbFlow($context);
            $this->storageFlow($context);
            $this->messagingFlow($context);
            $this->computeFlow($context);
            $this->healthFlow($context);
        } catch (Throwable $error) {
            $this->metrics->addCounter('appwrite_benchmark_flow_failures');
            throw $error;
        }
    }

    private function teardown(array $context): void
    {
        if (($context['projectId'] ?? null) && ($context['consoleSessionHeaders'] ?? null)) {
            $this->rawRequest('DELETE', "/projects/{$context['projectId']}", null, $context['consoleSessionHeaders'], 'teardown.projects.delete');
        }

        if (($context['teamId'] ?? null) && ($context['consoleSessionHeaders'] ?? null)) {
            $this->rawRequest('DELETE', "/teams/{$context['teamId']}", null, $context['consoleSessionHeaders'], 'teardown.teams.delete');
        }
    }

    private function accountFlow(array &$context): void
    {
        $userId = $this->unique('user');
        $email = 'bench-user-' . $this->unique('mail') . '@example.com';
        $headers = $this->projectHeaders($context['projectId']);

        $this->api('POST', '/account', [
            'userId' => $userId,
            'email' => $email,
            'password' => $this->password,
            'name' => 'Benchmark User',
        ], $headers, [201], 'account.create');

        $session = $this->api('POST', '/account/sessions/email', [
            'email' => $email,
            'password' => $this->password,
        ], $headers, [201], 'account.sessions.email.create');

        $sessionHeaders = [
            ...$headers,
            'Cookie' => $session->cookieHeader(),
        ];

        $context['userId'] = $userId;
        $context['userEmail'] = $email;
        $context['sessionHeaders'] = $sessionHeaders;

        $jwt = $this->api('POST', '/account/jwts', null, $sessionHeaders, [201], 'account.jwts.create');
        $context['jwtHeaders'] = [
            ...$headers,
            'X-Appwrite-JWT' => (string) $jwt->json('jwt'),
        ];

        $this->api('GET', '/account', null, $sessionHeaders, [200], 'account.get');
        $this->api('GET', '/account/logs', null, $sessionHeaders, [200], 'account.logs.list');
        $this->api('PATCH', '/account/prefs', ['prefs' => ['benchmark' => true, 'runId' => $context['runId']]], $sessionHeaders, [200], 'account.prefs.update');
        $this->api('PATCH', '/account/name', ['name' => 'Benchmark User Updated'], $sessionHeaders, [200], 'account.name.update');
        $this->api('PATCH', '/account/password', ['password' => $this->password . '2', 'oldPassword' => $this->password], $sessionHeaders, [200], 'account.password.update');

        $verificationStarted = $this->nowMs();
        $this->api('POST', '/account/verifications/email', ['url' => $this->redirectUrl], $sessionHeaders, [201], 'account.emailVerification.create');
        $verificationEmail = $this->waitForEmail($email, fn (array $message): bool => $this->messageIncludes($message, ['verify', 'verification']), $this->mailTimeoutMs);
        $this->metrics->addTrend('appwrite_worker_mails_duration', $this->nowMs() - $verificationStarted);

        $verification = $this->extractQueryParams($verificationEmail);
        if (($verification['userId'] ?? null) && ($verification['secret'] ?? null)) {
            $this->api('PUT', '/account/verifications/email', [
                'userId' => $verification['userId'],
                'secret' => $verification['secret'],
            ], $sessionHeaders, [200], 'account.emailVerification.update');
        }

        $recoveryStarted = $this->nowMs();
        $this->api('POST', '/account/recovery', ['email' => $email, 'url' => $this->redirectUrl], $headers, [201], 'account.recovery.create');
        $recoveryEmail = $this->waitForEmail($email, fn (array $message): bool => $this->messageIncludes($message, ['recovery', 'recover', 'reset']), $this->mailTimeoutMs);
        $this->metrics->addTrend('appwrite_worker_mails_duration', $this->nowMs() - $recoveryStarted);

        $recovery = $this->extractQueryParams($recoveryEmail);
        if (($recovery['userId'] ?? null) && ($recovery['secret'] ?? null)) {
            $this->api('DELETE', '/account/sessions/current', null, $sessionHeaders, [204], 'account.sessions.current.delete');
            $this->api('PUT', '/account/recovery', [
                'userId' => $recovery['userId'],
                'secret' => $recovery['secret'],
                'password' => $this->password . '3',
            ], $headers, [200], 'account.recovery.update');

            $recoveredSession = $this->api('POST', '/account/sessions/email', [
                'email' => $email,
                'password' => $this->password . '3',
            ], $headers, [201], 'account.sessions.email.recovered');

            $context['sessionHeaders'] = [
                ...$headers,
                'Cookie' => $recoveredSession->cookieHeader(),
            ];

            $recoveredJwt = $this->api('POST', '/account/jwts', null, $context['sessionHeaders'], [201], 'account.jwts.recovered');
            $context['jwtHeaders'] = [
                ...$headers,
                'X-Appwrite-JWT' => (string) $recoveredJwt->json('jwt'),
            ];
        }
    }

    private function databasesFlow(array $context): void
    {
        $databaseId = $this->unique('db');
        $collectionId = $this->unique('col');
        $documentId = $this->unique('doc');
        $indexKey = $this->unique('idx');

        $this->api('POST', '/databases', ['databaseId' => $databaseId, 'name' => 'Benchmark DB'], $context['apiHeaders'], [201], 'databases.create');
        $this->api('POST', "/databases/{$databaseId}/collections", [
            'collectionId' => $collectionId,
            'name' => 'Benchmark Collection',
            'permissions' => self::BASE_PERMISSIONS,
            'documentSecurity' => false,
        ], $context['apiHeaders'], [201], 'databases.collections.create');

        $attributes = [
            ['string', 'title', ['size' => 128]],
            ['integer', 'count', ['min' => 0, 'max' => 100000]],
            ['email', 'email', []],
            ['boolean', 'active', []],
            ['datetime', 'publishedAt', []],
            ['float', 'score', ['min' => 0, 'max' => 1000]],
            ['url', 'url', []],
            ['ip', 'ip', []],
        ];

        foreach ($attributes as [$type, $key, $extra]) {
            $started = $this->nowMs();
            $this->api('POST', "/databases/{$databaseId}/collections/{$collectionId}/attributes/{$type}", [
                'key' => $key,
                'required' => false,
                'array' => false,
                ...$extra,
            ], $context['apiHeaders'], [202], "databases.attributes.{$type}.create");
            $this->waitForStatus("/databases/{$databaseId}/collections/{$collectionId}/attributes/{$key}", $context['apiHeaders'], 'available', $this->workerTimeoutMs);
            $this->metrics->addTrend('appwrite_worker_database_duration', $this->nowMs() - $started);
        }

        $indexStarted = $this->nowMs();
        $this->api('POST', "/databases/{$databaseId}/collections/{$collectionId}/indexes", [
            'key' => $indexKey,
            'type' => 'key',
            'attributes' => ['title'],
            'orders' => ['asc'],
        ], $context['apiHeaders'], [202], 'databases.indexes.create');
        $this->waitForStatus("/databases/{$databaseId}/collections/{$collectionId}/indexes/{$indexKey}", $context['apiHeaders'], 'available', $this->workerTimeoutMs);
        $this->metrics->addTrend('appwrite_worker_database_duration', $this->nowMs() - $indexStarted);

        $this->api('POST', "/databases/{$databaseId}/collections/{$collectionId}/documents", [
            'documentId' => $documentId,
            'data' => $this->documentPayload(),
            'permissions' => self::ITEM_PERMISSIONS,
        ], $context['apiHeaders'], [201], 'databases.documents.create');
        $this->api('GET', "/databases/{$databaseId}/collections/{$collectionId}/documents", null, $context['apiHeaders'], [200], 'databases.documents.list');
        $this->api('GET', "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", null, $context['apiHeaders'], [200], 'databases.documents.get');
        $this->api('PATCH', "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", ['data' => ['title' => 'Benchmark Document Updated']], $context['apiHeaders'], [200], 'databases.documents.update');
        $this->api('PATCH', "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}/count/increment", ['value' => 1], $context['apiHeaders'], [200], 'databases.documents.increment');
        $this->api('PATCH', "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}/count/decrement", ['value' => 1], $context['apiHeaders'], [200], 'databases.documents.decrement');
        $this->api('DELETE', "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", null, $context['apiHeaders'], [204], 'databases.documents.delete');
        $this->api('DELETE', "/databases/{$databaseId}", null, $context['apiHeaders'], [204], 'databases.delete');
    }

    private function tablesDbFlow(array $context): void
    {
        if (!isset($context['sessionHeaders']) || !is_array($context['sessionHeaders'])) {
            throw new RuntimeException('accountFlow must run before tablesDbFlow');
        }

        $databaseId = $this->unique('tdb');
        $tableId = $this->unique('tbl');
        $rowId = $this->unique('row');
        $indexKey = $this->unique('tidx');

        $this->api('POST', '/tablesdb', ['databaseId' => $databaseId, 'name' => 'Benchmark TablesDB'], $context['apiHeaders'], [201], 'tablesdb.create');
        $this->api('POST', "/tablesdb/{$databaseId}/tables", [
            'tableId' => $tableId,
            'name' => 'Benchmark Table',
            'permissions' => self::BASE_PERMISSIONS,
            'rowSecurity' => false,
        ], $context['apiHeaders'], [201], 'tablesdb.tables.create');

        $columns = [
            ['string', 'title', ['size' => 128]],
            ['integer', 'count', ['min' => 0, 'max' => 100000]],
            ['email', 'email', []],
            ['boolean', 'active', []],
        ];

        foreach ($columns as [$type, $key, $extra]) {
            $started = $this->nowMs();
            $this->api('POST', "/tablesdb/{$databaseId}/tables/{$tableId}/columns/{$type}", [
                'key' => $key,
                'required' => false,
                'array' => false,
                ...$extra,
            ], $context['apiHeaders'], [202], "tablesdb.columns.{$type}.create");
            $this->waitForStatus("/tablesdb/{$databaseId}/tables/{$tableId}/columns/{$key}", $context['apiHeaders'], 'available', $this->workerTimeoutMs);
            $this->metrics->addTrend('appwrite_worker_tables_duration', $this->nowMs() - $started);
        }

        $indexStarted = $this->nowMs();
        $this->api('POST', "/tablesdb/{$databaseId}/tables/{$tableId}/indexes", [
            'key' => $indexKey,
            'type' => 'key',
            'columns' => ['title'],
            'orders' => ['asc'],
        ], $context['apiHeaders'], [202], 'tablesdb.indexes.create');
        $this->waitForStatus("/tablesdb/{$databaseId}/tables/{$tableId}/indexes/{$indexKey}", $context['apiHeaders'], 'available', $this->workerTimeoutMs);
        $this->metrics->addTrend('appwrite_worker_tables_duration', $this->nowMs() - $indexStarted);

        $this->api('POST', "/tablesdb/{$databaseId}/tables/{$tableId}/rows", [
            'rowId' => $rowId,
            'data' => $this->tablePayload(),
            'permissions' => self::ITEM_PERMISSIONS,
        ], $context['sessionHeaders'], [201], 'tablesdb.rows.create');
        $this->api('GET', "/tablesdb/{$databaseId}/tables/{$tableId}/rows", null, $context['sessionHeaders'], [200], 'tablesdb.rows.list');
        $this->api('GET', "/tablesdb/{$databaseId}/tables/{$tableId}/rows/{$rowId}", null, $context['sessionHeaders'], [200], 'tablesdb.rows.get');
        $this->api('PATCH', "/tablesdb/{$databaseId}/tables/{$tableId}/rows/{$rowId}", ['data' => ['title' => 'Benchmark Row Updated']], $context['sessionHeaders'], [200], 'tablesdb.rows.update');
        $this->api('PATCH', "/tablesdb/{$databaseId}/tables/{$tableId}/rows/{$rowId}/count/increment", ['value' => 1], $context['sessionHeaders'], [200], 'tablesdb.rows.increment');
        $this->api('PATCH', "/tablesdb/{$databaseId}/tables/{$tableId}/rows/{$rowId}/count/decrement", ['value' => 1], $context['sessionHeaders'], [200], 'tablesdb.rows.decrement');
        $this->api('DELETE', "/tablesdb/{$databaseId}/tables/{$tableId}/rows/{$rowId}", null, $context['sessionHeaders'], [204], 'tablesdb.rows.delete');
        $this->api('DELETE', "/tablesdb/{$databaseId}", null, $context['apiHeaders'], [204], 'tablesdb.delete');
    }

    private function storageFlow(array $context): void
    {
        $bucketId = $this->unique('bucket');
        $fileId = $this->unique('file');

        $this->api('POST', '/storage/buckets', [
            'bucketId' => $bucketId,
            'name' => 'Benchmark Bucket',
            'permissions' => self::BASE_PERMISSIONS,
            'fileSecurity' => false,
            'enabled' => true,
            'maximumFileSize' => 30000000,
            'allowedFileExtensions' => [],
            'compression' => 'none',
            'encryption' => false,
            'antivirus' => false,
        ], $context['apiHeaders'], [201], 'storage.buckets.create');

        $tmpFile = tempnam(sys_get_temp_dir(), 'appwrite-benchmark-');
        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temporary PNG fixture');
        }

        file_put_contents($tmpFile, base64_decode(self::PNG_1X1, true));

        try {
            $fields = [
                'fileId' => $fileId,
                'file' => new CURLFile($tmpFile, 'image/png', 'benchmark.png'),
                ...$this->flattenMultipartArray('permissions', self::ITEM_PERMISSIONS),
            ];
            $multipartHeaders = $context['sessionHeaders'];
            unset($multipartHeaders['Content-Type']);

            $upload = $this->rawMultipartRequest('POST', "/storage/buckets/{$bucketId}/files", $fields, $multipartHeaders, 'storage.files.create');
            $this->metrics->addTrend('appwrite_api_duration', $upload->duration);
            $this->assertStatus($upload, [201], 'storage file created');
        } finally {
            @unlink($tmpFile);
        }

        $this->api('GET', "/storage/buckets/{$bucketId}/files", null, $context['sessionHeaders'], [200], 'storage.files.list');
        $this->api('GET', "/storage/buckets/{$bucketId}/files/{$fileId}", null, $context['sessionHeaders'], [200], 'storage.files.get');
        $this->api('GET', "/storage/buckets/{$bucketId}/files/{$fileId}/view", null, $context['sessionHeaders'], [200], 'storage.files.view');
        $this->api('GET', "/storage/buckets/{$bucketId}/files/{$fileId}/download", null, $context['sessionHeaders'], [200], 'storage.files.download');
        $this->api('GET', "/storage/buckets/{$bucketId}/files/{$fileId}/preview", null, $context['sessionHeaders'], [200], 'storage.files.preview');
        $this->api('PUT', "/storage/buckets/{$bucketId}/files/{$fileId}", [
            'name' => 'benchmark-renamed.png',
            'permissions' => self::ITEM_PERMISSIONS,
        ], $context['sessionHeaders'], [200], 'storage.files.update');

        $token = $this->api('POST', "/tokens/buckets/{$bucketId}/files/{$fileId}", (object) [], $context['apiHeaders'], [201], 'tokens.files.create');
        $tokenId = (string) $token->json('$id');
        $this->api('GET', "/tokens/buckets/{$bucketId}/files/{$fileId}", null, $context['apiHeaders'], [200], 'tokens.files.list');
        $this->api('GET', "/tokens/{$tokenId}", null, $context['apiHeaders'], [200], 'tokens.get');
        $this->api('PATCH', "/tokens/{$tokenId}", ['expire' => null], $context['apiHeaders'], [200], 'tokens.update');
        $this->api('DELETE', "/tokens/{$tokenId}", null, $context['apiHeaders'], [204], 'tokens.delete');

        $this->api('DELETE', "/storage/buckets/{$bucketId}/files/{$fileId}", null, $context['sessionHeaders'], [204], 'storage.files.delete');
        $this->api('DELETE', "/storage/buckets/{$bucketId}", null, $context['apiHeaders'], [204], 'storage.buckets.delete');
    }

    private function messagingFlow(array $context): void
    {
        $providerId = $this->unique('smtp');
        $targetId = $this->unique('target');
        $existingTarget = false;
        $topicId = $this->unique('topic');
        $subscriberId = $this->unique('sub');
        $messageId = $this->unique('msg');

        $this->api('POST', '/messaging/providers/smtp', [
            'providerId' => $providerId,
            'name' => 'Benchmark SMTP',
            'host' => $this->env('APPWRITE_SMTP_HOST', 'maildev'),
            'port' => (int) $this->env('APPWRITE_SMTP_PORT', '1025'),
            'username' => $this->env('APPWRITE_SMTP_USERNAME', 'user'),
            'password' => $this->env('APPWRITE_SMTP_PASSWORD', 'password'),
            'encryption' => $this->env('APPWRITE_SMTP_ENCRYPTION', 'none'),
            'autoTLS' => false,
            'fromName' => 'Benchmark',
            'fromEmail' => 'benchmark@appwrite.io',
            'replyToName' => 'Benchmark',
            'replyToEmail' => 'benchmark@appwrite.io',
            'enabled' => true,
        ], $context['apiHeaders'], [201], 'messaging.providers.smtp.create');

        $targets = $this->api('GET', "/users/{$context['userId']}/targets", null, $context['apiHeaders'], [200], 'users.targets.list');
        foreach ($targets->json('targets') ?? [] as $target) {
            if (($target['providerType'] ?? '') === 'email' && ($target['identifier'] ?? '') === $context['userEmail']) {
                $targetId = (string) $target['$id'];
                $existingTarget = true;
                break;
            }
        }

        if ($existingTarget) {
            $this->api('PATCH', "/users/{$context['userId']}/targets/{$targetId}", [
                'providerId' => $providerId,
                'name' => 'Benchmark email target',
            ], $context['apiHeaders'], [200], 'users.targets.update');
        } else {
            $this->api('POST', "/users/{$context['userId']}/targets", [
                'targetId' => $targetId,
                'providerType' => 'email',
                'identifier' => $context['userEmail'],
                'providerId' => $providerId,
                'name' => 'Benchmark email target',
            ], $context['apiHeaders'], [201], 'users.targets.create');
        }

        $this->api('POST', '/messaging/topics', [
            'topicId' => $topicId,
            'name' => 'Benchmark Topic',
            'subscribe' => ['users'],
        ], $context['apiHeaders'], [201], 'messaging.topics.create');

        $this->api('POST', "/messaging/topics/{$topicId}/subscribers", [
            'subscriberId' => $subscriberId,
            'targetId' => $targetId,
        ], $context['sessionHeaders'], [201], 'messaging.subscribers.create');

        $started = $this->nowMs();
        $this->api('POST', '/messaging/messages/email', [
            'messageId' => $messageId,
            'subject' => "Benchmark message {$context['runId']}",
            'content' => "Benchmark messaging worker probe {$context['runId']}",
            'targets' => [$targetId],
            'draft' => false,
            'html' => false,
        ], $context['apiHeaders'], [201], 'messaging.messages.email.create');

        $this->waitForMessage($messageId, $context['apiHeaders'], $this->workerTimeoutMs);
        $this->waitForEmail($context['userEmail'], fn (array $message): bool => $this->includes($message['subject'] ?? '', "Benchmark message {$context['runId']}"), $this->mailTimeoutMs, true);
        $this->metrics->addTrend('appwrite_worker_messaging_duration', $this->nowMs() - $started);

        $this->api('GET', '/messaging/messages', null, $context['apiHeaders'], [200], 'messaging.messages.list');
        $this->api('GET', "/messaging/messages/{$messageId}/logs", null, $context['apiHeaders'], [200], 'messaging.messages.logs.list');
        $this->api('GET', "/messaging/messages/{$messageId}/targets", null, $context['apiHeaders'], [200], 'messaging.messages.targets.list');
        $this->api('GET', "/messaging/providers/{$providerId}/logs", null, $context['apiHeaders'], [200], 'messaging.providers.logs.list');
        $this->api('GET', "/messaging/topics/{$topicId}/logs", null, $context['apiHeaders'], [200], 'messaging.topics.logs.list');
        $this->api('GET', "/messaging/subscribers/{$subscriberId}/logs", null, $context['apiHeaders'], [200], 'messaging.subscribers.logs.list');
        $this->api('DELETE', "/messaging/topics/{$topicId}/subscribers/{$subscriberId}", null, $context['sessionHeaders'], [204], 'messaging.subscribers.delete');
        $this->api('DELETE', "/messaging/topics/{$topicId}", null, $context['apiHeaders'], [204], 'messaging.topics.delete');
        $this->api('DELETE', "/messaging/messages/{$messageId}", null, $context['apiHeaders'], [204], 'messaging.messages.delete');
        $this->api('DELETE', "/messaging/providers/{$providerId}", null, $context['apiHeaders'], [204], 'messaging.providers.delete');
    }

    private function computeFlow(array $context): void
    {
        $functionId = $this->unique('fn');
        $siteId = $this->unique('site');
        $runtime = $this->env('APPWRITE_BENCHMARK_RUNTIME', 'node-22');

        $this->api('POST', '/functions', [
            'functionId' => $functionId,
            'name' => 'Benchmark Function',
            'runtime' => $runtime,
            'execute' => ['any'],
            'events' => [],
            'schedule' => '',
            'timeout' => 15,
            'enabled' => true,
            'logging' => true,
            'entrypoint' => 'index.js',
            'commands' => 'npm install',
            'scopes' => ['users.read'],
        ], $context['apiHeaders'], [201], 'functions.create');
        $this->api('GET', '/functions/runtimes', null, $context['sessionHeaders'], [200], 'functions.runtimes.list');
        $this->api('GET', '/functions/specifications', null, $context['apiHeaders'], [200], 'functions.specifications.list');

        $functionVariable = $this->api('POST', "/functions/{$functionId}/variables", [
            'key' => 'BENCHMARK',
            'value' => 'true',
            'secret' => false,
        ], $context['apiHeaders'], [201], 'functions.variables.create');
        $functionVariableId = (string) $functionVariable->json('$id');
        $this->api('PUT', "/functions/{$functionId}/variables/{$functionVariableId}", ['key' => 'BENCHMARK', 'value' => 'updated', 'secret' => false], $context['apiHeaders'], [200], 'functions.variables.update');
        $this->api('GET', "/functions/{$functionId}/variables/{$functionVariableId}", null, $context['apiHeaders'], [200], 'functions.variables.get');
        $this->api('DELETE', "/functions/{$functionId}/variables/{$functionVariableId}", null, $context['apiHeaders'], [204], 'functions.variables.delete');
        $this->api('DELETE', "/functions/{$functionId}", null, $context['apiHeaders'], [204], 'functions.delete');

        $this->api('POST', '/sites', [
            'siteId' => $siteId,
            'name' => 'Benchmark Site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => $runtime,
            'buildCommand' => '',
            'outputDirectory' => '.',
            'installCommand' => '',
            'fallbackFile' => 'index.html',
            'providerRootDirectory' => '.',
            'specification' => '',
        ], $context['apiHeaders'], [201], 'sites.create');
        $this->api('GET', '/sites/frameworks', null, $context['sessionHeaders'], [200], 'sites.frameworks.list');
        $this->api('GET', '/sites/specifications', null, $context['apiHeaders'], [200], 'sites.specifications.list');

        $siteVariable = $this->api('POST', "/sites/{$siteId}/variables", ['key' => 'BENCHMARK', 'value' => 'true', 'secret' => false], $context['apiHeaders'], [201], 'sites.variables.create');
        $siteVariableId = (string) $siteVariable->json('$id');
        $this->api('PUT', "/sites/{$siteId}/variables/{$siteVariableId}", ['key' => 'BENCHMARK', 'value' => 'updated', 'secret' => false], $context['apiHeaders'], [200], 'sites.variables.update');
        $this->api('GET', "/sites/{$siteId}/variables/{$siteVariableId}", null, $context['apiHeaders'], [200], 'sites.variables.get');
        $this->api('DELETE', "/sites/{$siteId}/variables/{$siteVariableId}", null, $context['apiHeaders'], [204], 'sites.variables.delete');
        $this->api('DELETE', "/sites/{$siteId}", null, $context['apiHeaders'], [204], 'sites.delete');
    }

    private function healthFlow(array $context): void
    {
        $probes = [
            '/health',
            '/health/db',
            '/health/cache',
            '/health/pubsub',
            '/health/storage',
            '/health/storage/local',
            '/health/time',
            '/health/queue/databases',
            '/health/queue/mails',
            '/health/queue/messaging',
            '/health/queue/functions',
            '/health/queue/builds',
            '/health/queue/deletes',
            '/health/queue/webhooks',
            '/health/queue/stats-resources',
            '/health/queue/stats-usage',
            '/health/queue/failed/v1-mails',
        ];

        foreach ($probes as $path) {
            $this->api('GET', $path, null, $context['apiHeaders'], [200], 'health' . str_replace('/', '.', $path));
        }
    }

    private function api(string $method, string $path, mixed $body, array $headers, array $expected, string $name): BenchmarkResponse
    {
        $response = $this->rawRequest($method, $path, $body, $headers, $name);
        $this->metrics->addTrend('appwrite_api_duration', $response->duration);
        $this->assertStatus($response, $expected, $name);
        return $response;
    }

    private function rawRequest(string $method, string $path, mixed $body, array $headers, string $name, bool $recordHttpDuration = true): BenchmarkResponse
    {
        return $this->send($method, str_starts_with($path, 'http') ? $path : $this->endpoint . $path, $body, $headers, $name, false, $recordHttpDuration);
    }

    private function rawMultipartRequest(string $method, string $path, array $fields, array $headers, string $name): BenchmarkResponse
    {
        return $this->send($method, $this->endpoint . $path, $fields, $headers, $name, true, true);
    }

    private function send(string $method, string $url, mixed $body, array $headers, string $name, bool $multipart, bool $recordHttpDuration): BenchmarkResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException("Unable to initialize curl for {$url}");
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 120,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $multipart ? $body : json_encode($body, JSON_UNESCAPED_SLASHES));
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, '');
        }

        $started = hrtime(true);
        $raw = curl_exec($handle);
        $duration = (hrtime(true) - $started) / 1_000_000;
        if ($recordHttpDuration) {
            $this->metrics->addTrend('http_req_duration', $duration);
        }

        if ($raw === false) {
            $error = curl_error($handle);
            throw new RuntimeException("{$name} curl error: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        return new BenchmarkResponse(
            $status,
            substr($raw, $headerSize),
            $this->parseHeaders(substr($raw, 0, $headerSize)),
            $duration,
        );
    }

    private function waitForStatus(string $path, array $headers, string $wantedStatus, int $timeoutMs): BenchmarkResponse
    {
        $started = $this->nowMs();

        while ($this->nowMs() - $started < $timeoutMs) {
            $response = $this->rawRequest('GET', $path, null, $headers, "wait{$path}");
            if ($response->status === 200) {
                $status = $response->json('status');
                if ($status === $wantedStatus) {
                    return $response;
                }

                if ($status === 'failed') {
                    throw new RuntimeException("Resource {$path} failed while waiting for {$wantedStatus}");
                }
            }

            usleep(500_000);
        }

        throw new RuntimeException("Timed out waiting for {$path} to become {$wantedStatus}");
    }

    private function waitForMessage(string $messageId, array $headers, int $timeoutMs): BenchmarkResponse
    {
        $started = $this->nowMs();

        while ($this->nowMs() - $started < $timeoutMs) {
            $response = $this->rawRequest('GET', "/messaging/messages/{$messageId}", null, $headers, 'messaging.messages.poll');
            $status = $response->status === 200 ? $response->json('status') : null;

            if (in_array($status, ['sent', 'failed'], true)) {
                if ($status === 'failed') {
                    throw new RuntimeException("Messaging worker marked message {$messageId} as failed");
                }

                return $response;
            }

            usleep(500_000);
        }

        throw new RuntimeException("Timed out waiting for messaging worker to send message {$messageId}");
    }

    private function waitForEmail(string $address, callable $predicate, int $timeoutMs, bool $allowMissingRecipient = false): array
    {
        $started = $this->nowMs();

        while ($this->nowMs() - $started < $timeoutMs) {
            $response = $this->rawRequest('GET', $this->maildevEndpoint, null, [], 'maildev.email.list', false);

            if ($response->status === 200) {
                $emails = $response->json();
                if (is_array($emails)) {
                    for ($i = count($emails) - 1; $i >= 0; $i--) {
                        $message = $emails[$i];
                        if (!is_array($message)) {
                            continue;
                        }

                        if (($this->emailMatches($message, $address) || ($allowMissingRecipient && $this->emailRecipientMissing($message))) && $predicate($message)) {
                            return $message;
                        }
                    }
                }
            }

            usleep(500_000);
        }

        throw new RuntimeException("Timed out waiting for email to {$address}");
    }

    private function assertStatus(BenchmarkResponse $response, array $expected, string $name): void
    {
        $passed = in_array($response->status, $expected, true);
        $this->metrics->addCheck($passed);

        if (!$passed) {
            $this->failResponse($response, "{$name} returned an unexpected status");
        }
    }

    private function failResponse(BenchmarkResponse $response, string $message): never
    {
        throw new RuntimeException("{$message}. Status: {$response->status}. Body: {$response->body}");
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $blocks = preg_split("/\r\n\r\n|\n\n/", trim($rawHeaders)) ?: [];
        $headerBlock = end($blocks) ?: '';
        $headers = [];

        foreach (preg_split("/\r\n|\n|\r/", $headerBlock) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }

        return $headers;
    }

    private function emailMatches(array $message, string $address): bool
    {
        foreach ($message['to'] ?? [] as $recipient) {
            if (($recipient['address'] ?? null) === $address) {
                return true;
            }
        }

        return false;
    }

    private function emailRecipientMissing(array $message): bool
    {
        $recipients = $message['to'] ?? [];
        if ($recipients === []) {
            return true;
        }

        foreach ($recipients as $recipient) {
            if ($recipient['address'] ?? null) {
                return false;
            }
        }

        return true;
    }

    private function extractQueryParams(array $message): array
    {
        $content = ($message['html'] ?? '') . "\n" . ($message['text'] ?? '');
        preg_match_all('/href="([^"]+)"/', $content, $matches);
        $links = $matches[1] ?: [$content];

        foreach ($links as $link) {
            $query = parse_url(html_entity_decode($link), PHP_URL_QUERY);
            if (!is_string($query)) {
                continue;
            }

            parse_str($query, $params);
            if (($params['userId'] ?? null) && ($params['secret'] ?? null)) {
                return $params;
            }
        }

        return [];
    }

    private function projectHeaders(string $projectId): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Appwrite-Project' => $projectId,
        ];
    }

    private function documentPayload(): array
    {
        return [
            'title' => 'Benchmark Document',
            'count' => 1,
            'email' => 'document@example.com',
            'active' => true,
            'publishedAt' => gmdate('c'),
            'score' => 10.5,
            'url' => 'https://appwrite.io',
            'ip' => '127.0.0.1',
        ];
    }

    private function tablePayload(): array
    {
        return [
            'title' => 'Benchmark Row',
            'count' => 1,
            'email' => 'row@example.com',
            'active' => true,
        ];
    }

    private function flattenMultipartArray(string $key, array $values): array
    {
        $output = [];

        foreach (array_values($values) as $index => $value) {
            $output["{$key}[{$index}]"] = $value;
        }

        return $output;
    }

    private function messageIncludes(array $message, array $needles): bool
    {
        $content = implode("\n", [
            (string) ($message['subject'] ?? ''),
            (string) ($message['html'] ?? ''),
            (string) ($message['text'] ?? ''),
        ]);

        foreach ($needles as $needle) {
            if ($this->includes($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function includes(string $value, string $needle): bool
    {
        return str_contains(strtolower($value), strtolower($needle));
    }

    private function hostnameFromUrl(string $value): string
    {
        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return explode(':', explode('/', preg_replace('/^https?:\/\//', '', $value) ?? '')[0])[0];
    }

    private function unique(string $prefix): string
    {
        $id = strtolower($prefix . '-' . base_convert((string) ((int) (microtime(true) * 1000)), 10, 36) . '-' . bin2hex(random_bytes(4)));
        return substr(preg_replace('/[^a-z0-9-]/', '-', $id) ?? $id, 0, 36);
    }

    private function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }

    private function loadPreviousSummary(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $summary = json_decode((string) file_get_contents($path), true);
        return is_array($summary) ? $summary : null;
    }

    private function writeSummary(array $summary): void
    {
        $directory = dirname($this->summaryPath);
        if ($directory !== '.' && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create benchmark summary directory: {$directory}");
        }

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode benchmark summary: ' . json_last_error_msg());
        }

        if (file_put_contents($this->summaryPath, $json) === false) {
            throw new RuntimeException("Unable to write benchmark summary: {$this->summaryPath}");
        }
    }

    private function renderSummary(array $summary): string
    {
        $lines = [
            'Appwrite curated benchmark review',
            '',
            'Before/after comparison',
            '',
            $this->comparisonTable($this->previousSummary, $summary),
            '',
            'Current run details',
            '',
            $this->metricLine($summary, 'http_req_duration', 'HTTP total'),
            $this->metricLine($summary, 'appwrite_api_duration', 'API endpoints'),
            $this->metricLine($summary, 'appwrite_worker_database_duration', 'Database worker schema jobs'),
            $this->metricLine($summary, 'appwrite_worker_tables_duration', 'TablesDB worker schema jobs'),
            $this->metricLine($summary, 'appwrite_worker_mails_duration', 'Mail worker delivery'),
            $this->metricLine($summary, 'appwrite_worker_messaging_duration', 'Messaging worker delivery'),
            '',
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function comparisonTable(?array $before, array $after): string
    {
        $rows = [
            ['HTTP total p95', $this->trendMetric($before, 'http_req_duration', 'p(95)'), $this->trendMetric($after, 'http_req_duration', 'p(95)'), 'ms'],
            ['API endpoints p95', $this->trendMetric($before, 'appwrite_api_duration', 'p(95)'), $this->trendMetric($after, 'appwrite_api_duration', 'p(95)'), 'ms'],
            ['Database worker p95', $this->trendMetric($before, 'appwrite_worker_database_duration', 'p(95)'), $this->trendMetric($after, 'appwrite_worker_database_duration', 'p(95)'), 'ms'],
            ['TablesDB worker p95', $this->trendMetric($before, 'appwrite_worker_tables_duration', 'p(95)'), $this->trendMetric($after, 'appwrite_worker_tables_duration', 'p(95)'), 'ms'],
            ['Mail worker p95', $this->trendMetric($before, 'appwrite_worker_mails_duration', 'p(95)'), $this->trendMetric($after, 'appwrite_worker_mails_duration', 'p(95)'), 'ms'],
            ['Messaging worker p95', $this->trendMetric($before, 'appwrite_worker_messaging_duration', 'p(95)'), $this->trendMetric($after, 'appwrite_worker_messaging_duration', 'p(95)'), 'ms'],
        ];

        $table = [
            '| Metric | Before | After | Delta |',
            '| --- | ---: | ---: | ---: |',
        ];

        foreach ($rows as [$label, $beforeValue, $afterValue, $unit]) {
            $table[] = "| {$label} | {$this->formatValue($beforeValue, $unit)} | {$this->formatValue($afterValue, $unit)} | {$this->formatDelta($beforeValue, $afterValue, $unit)} |";
        }

        return implode(PHP_EOL, $table);
    }

    private function trendMetric(?array $data, string $metric, string $stat): ?float
    {
        return $data['metrics'][$metric]['values'][$stat] ?? null;
    }

    private function metricLine(array $data, string $metric, string $label): string
    {
        $values = $data['metrics'][$metric]['values'] ?? null;
        if (!is_array($values) || ($values['count'] ?? 0) === 0) {
            return "{$label}: no samples";
        }

        return "{$label}: avg={$this->round($values['avg'])}ms p90={$this->round($values['p(90)'])}ms p95={$this->round($values['p(95)'])}ms max={$this->round($values['max'])}ms";
    }

    private function formatValue(?float $value, string $unit): string
    {
        return $value === null || is_nan($value) ? 'n/a' : $this->round($value) . $unit;
    }

    private function formatDelta(?float $before, ?float $after, string $unit): string
    {
        if ($before === null || $after === null || is_nan($before) || is_nan($after)) {
            return 'n/a';
        }

        $delta = $this->round($after - $before);
        return ($delta > 0 ? '+' : '') . $delta . $unit;
    }

    private function round(float|int|null $value): string
    {
        $rounded = round((float) ($value ?? 0), 2);
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}

exit((new HttpBenchmark())->run());
