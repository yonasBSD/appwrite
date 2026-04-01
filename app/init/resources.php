<?php

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Utopia\Database\Documents\User;
use Executor\Executor;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;

// Runtime Execution
global $register;
global $container;
$container = new Container();

$container->set('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

$container->set('hooks', function ($register) {
    return $register->get('hooks');
}, ['register']);

$container->set('register', fn () => $register);

$container->set('localeCodes', function () {
    return array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', []));
});

// Queues - shared infrastructure (stateless pool wrappers)
$container->set('publisher', function (Group $pools) {
    return new BrokerPool(publisher: $pools->get('publisher'));
}, ['pools']);
$container->set('publisherDatabases', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherFunctions', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMigrations', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMails', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherDeletes', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMessaging', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherWebhooks', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherForUsage', fn (Publisher $publisher) => new UsagePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME))
), ['publisher']);

/**
 * Platform configuration
 */
$container->set('platform', function () {
    return Config::getParam('platform', []);
}, []);

/**
 * List of allowed request hostnames for the request.
 */
Http::setResource('allowedHostnames', function (array $platform, Document $project, Document $rule, Document $devKey, Request $request) {
    $allowed = [...($platform['hostnames'] ?? [])];

    /* Add platform configured hostnames */
    if (! $project->isEmpty() && $project->getId() !== 'console') {
        $platforms = $project->getAttribute('platforms', []);
        $hostnames = Platform::getHostnames($platforms);
        $allowed = [...$allowed, ...$hostnames];
    }

    /* Add the request hostname if a dev key is found */
    if (! $devKey->isEmpty()) {
        $allowed[] = $request->getHostname();
    }

    $originHostname = parse_url($request->getOrigin(), PHP_URL_HOST);
    $refererHostname = parse_url($request->getReferer(), PHP_URL_HOST);

    $hostname = $originHostname;
    if (empty($hostname)) {
        $hostname = $refererHostname;
    }

    /* Add request hostname for preflight requests */
    if ($request->getMethod() === 'OPTIONS') {
        $allowed[] = $hostname;
    }

    /* Allow the request origin of rule */
    if (! $rule->isEmpty() && ! empty($rule->getAttribute('domain', ''))) {
        $allowed[] = $rule->getAttribute('domain', '');
    }

    /* Allow the request origin if a dev key is found */
    if (! $devKey->isEmpty() && ! empty($hostname)) {
        $allowed[] = $hostname;
    }

    return array_unique($allowed);
}, ['platform', 'project', 'rule', 'devKey', 'request']);

/**
 * List of allowed request schemes for the request.
 */
Http::setResource('allowedSchemes', function (array $platform, Document $project) {
    $allowed = [...($platform['schemas'] ?? [])];

    if (! $project->isEmpty() && $project->getId() !== 'console') {
        /* Add hardcoded schemes */
        $allowed[] = 'exp';
        $allowed[] = 'appwrite-callback-' . $project->getId();

        /* Add platform configured schemes */
        $platforms = $project->getAttribute('platforms', []);
        $schemes = Platform::getSchemes($platforms);
        $allowed = [...$allowed, ...$schemes];
    }

    return array_unique($allowed);
}, ['platform', 'project']);

/**
 * Rule associated with a request origin.
 */
Http::setResource('rule', function (Request $request, Database $dbForPlatform, Document $project, Authorization $authorization) {
    $domain = \parse_url($request->getOrigin(), PHP_URL_HOST);

    if (empty($domain)) {
        $domain = \parse_url($request->getReferer(), PHP_URL_HOST);
    }

    if (empty($domain)) {
        return new Document();
    }

    // TODO: (@Meldiron) Remove after 1.7.x migration
    $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
    $rule = $authorization->skip(function () use ($dbForPlatform, $domain, $isMd5) {
        if ($isMd5) {
            return $dbForPlatform->getDocument('rules', md5($domain));
        }

        return $dbForPlatform->findOne('rules', [
            Query::equal('domain', [$domain]),
        ]) ?? new Document();
    });

    $permitsCurrentProject = $rule->getAttribute('projectInternalId', '') === $project->getSequence();

    // Temporary implementation until custom wildcard domains are an official feature
    // Allow trusted projects; Used for Console (website) previews
    if (! $permitsCurrentProject && ! $rule->isEmpty() && ! empty($rule->getAttribute('projectId', ''))) {
        $trustedProjects = [];
        foreach (\explode(',', System::getEnv('_APP_CONSOLE_TRUSTED_PROJECTS', '')) as $trustedProject) {
            if (empty($trustedProject)) {
                continue;
            }
            $trustedProjects[] = $trustedProject;
        }
        if (\in_array($rule->getAttribute('projectId', ''), $trustedProjects)) {
            $permitsCurrentProject = true;
        }
    }

    if (! $permitsCurrentProject) {
        return new Document();
    }

    return $rule;
}, ['request', 'dbForPlatform', 'project', 'authorization']);

/**
 * CORS service
 */
Http::setResource('cors', function (array $allowedHostnames) {
    $corsConfig = Config::getParam('cors');

    return new Cors(
        $allowedHostnames,
        allowedMethods: $corsConfig['allowedMethods'],
        allowedHeaders: $corsConfig['allowedHeaders'],
        allowCredentials: true,
        exposedHeaders: $corsConfig['exposedHeaders'],
    );
}, ['allowedHostnames']);

Http::setResource('originValidator', function (Document $devKey, array $allowedHostnames, array $allowedSchemes) {
    if (! $devKey->isEmpty()) {
        return new URL();
    }

    return new Origin($allowedHostnames, $allowedSchemes);
}, ['devKey', 'allowedHostnames', 'allowedSchemes']);

Http::setResource('redirectValidator', function (Document $devKey, array $allowedHostnames, array $allowedSchemes) {
    if (! $devKey->isEmpty()) {
        return new URL();
    }

    return new Redirect($allowedHostnames, $allowedSchemes);
}, ['devKey', 'allowedHostnames', 'allowedSchemes']);

Http::setResource('user', function (string $mode, Document $project, Document $console, Request $request, Response $response, Database $dbForProject, Database $dbForPlatform, Store $store, Token $proofForToken, $authorization) {
    /**
     * Handles user authentication and session validation.
     *
     * This function follows a series of steps to determine the appropriate user session
     * based on cookies, headers, and JWT tokens.
     *
     * Process:
     * 1. Checks the cookie based on mode:
     *    - If in admin mode, uses console project id for key.
     *    - Otherwise, sets the key using the project ID
     * 2. If no cookie is found, attempts to retrieve the fallback header `x-fallback-cookies`.
     *    - If this method is used, returns the header: `X-Debug-Fallback: true`.
     * 3. Fetches the user document from the appropriate database based on the mode.
     * 4. If the user document is empty or the session key cannot be verified, sets an empty user document.
     * 5. Regardless of the results from steps 1-4, attempts to fetch the JWT token.
     * 6. If the JWT user has a valid session ID, updates the user variable with the user from `projectDB`,
     *    overwriting the previous value.
     * 7. If account API key is passed, use user of the account API key as long as user ID header matches too
     */
    $authorization->setDefaultStatus(true);

    $store->setKey('a_session_' . $project->getId());

    if ($mode === APP_MODE_ADMIN) {
        $store->setKey('a_session_' . $console->getId());
    }

    $store->decode(
        $request->getCookie(
            $store->getKey(), // Get sessions
            $request->getCookie($store->getKey() . '_legacy', '')
        )
    );

    // Get session from header for SSR clients
    if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
        $sessionHeader = $request->getHeader('x-appwrite-session', '');

        if (! empty($sessionHeader)) {
            $store->decode($sessionHeader);
        }
    }

    // Get fallback session from old clients (no SameSite support) or clients who block 3rd-party cookies
    if ($response) { // if in http context - add debug header
        $response->addHeader('X-Debug-Fallback', 'false');
    }

    if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
        if ($response) {
            $response->addHeader('X-Debug-Fallback', 'true');
        }
        $fallback = $request->getHeader('x-fallback-cookies', '');
        $fallback = \json_decode($fallback, true);
        $store->decode(((is_array($fallback) && isset($fallback[$store->getKey()])) ? $fallback[$store->getKey()] : ''));
    }

    $user = null;
    if ($mode === APP_MODE_ADMIN) {
        /** @var User $user */
        $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
    } else {
        if ($project->isEmpty()) {
            $user = new User([]);
        } else {
            if (! empty($store->getProperty('id', ''))) {
                if ($project->getId() === 'console') {
                    /** @var User $user */
                    $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
                } else {
                    /** @var User $user */
                    $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
                }
            }
        }
    }

    if (
        ! $user ||
        $user->isEmpty() // Check a document has been found in the DB
        || ! $user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
    ) { // Validate user has valid login token
        $user = new User([]);
    }

    $authJWT = $request->getHeader('x-appwrite-jwt', '');
    if (! empty($authJWT) && ! $project->isEmpty()) { // JWT authentication
        if (! $user->isEmpty()) {
            throw new Exception(Exception::USER_JWT_AND_COOKIE_SET);
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);
        try {
            $payload = $jwt->decode($authJWT);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        $jwtUserId = $payload['userId'] ?? '';
        if (! empty($jwtUserId)) {
            if ($mode === APP_MODE_ADMIN) {
                /** @var User $user */
                $user = $dbForPlatform->getDocument('users', $jwtUserId);
            } else {
                /** @var User $user */
                $user = $dbForProject->getDocument('users', $jwtUserId);
            }
        }
        $jwtSessionId = $payload['sessionId'] ?? '';
        if (! empty($jwtSessionId)) {
            if (empty($user->find('$id', $jwtSessionId, 'sessions'))) { // Match JWT to active token
                $user = new User([]);
            }
        }
    }

    // Account based on account API key
    $accountKey = $request->getHeader('x-appwrite-key', '');
    $accountKeyUserId = $request->getHeader('x-appwrite-user', '');
    if (! empty($accountKeyUserId) && ! empty($accountKey)) {
        if (! $user->isEmpty()) {
            throw new Exception(Exception::USER_API_KEY_AND_SESSION_SET);
        }

        /** @var User $accountKeyUser */
        $accountKeyUser = $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->getDocument('users', $accountKeyUserId));
        if (! $accountKeyUser->isEmpty()) {
            $key = $accountKeyUser->find(
                key: 'secret',
                find: $accountKey,
                subject: 'keys'
            );

            if (! empty($key)) {
                $expire = $key->getAttribute('expire');
                if (! empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
                    throw new Exception(Exception::ACCOUNT_KEY_EXPIRED);
                }

                $user = $accountKeyUser;
            }
        }
    }

    // Impersonation: if current user has impersonator capability and headers are set, act as another user
    $impersonateUserId = $request->getHeader('x-appwrite-impersonate-user-id', '');
    $impersonateEmail = $request->getHeader('x-appwrite-impersonate-user-email', '');
    $impersonatePhone = $request->getHeader('x-appwrite-impersonate-user-phone', '');
    if (!$user->isEmpty() && $user->getAttribute('impersonator', false)) {
        $userDb = (APP_MODE_ADMIN === $mode || $project->getId() === 'console') ? $dbForPlatform : $dbForProject;
        $targetUser = null;
        if (!empty($impersonateUserId)) {
            $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->getDocument('users', $impersonateUserId));
        } elseif (!empty($impersonateEmail)) {
            $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [Query::equal('email', [\strtolower($impersonateEmail)])]));
        } elseif (!empty($impersonatePhone)) {
            $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [Query::equal('phone', [$impersonatePhone])]));
        }
        if ($targetUser !== null && !$targetUser->isEmpty()) {
            $impersonator = clone $user;
            $user = clone $targetUser;
            $user->setAttribute('impersonatorUserId', $impersonator->getId());
            $user->setAttribute('impersonatorUserInternalId', $impersonator->getSequence());
            $user->setAttribute('impersonatorUserName', $impersonator->getAttribute('name', ''));
            $user->setAttribute('impersonatorUserEmail', $impersonator->getAttribute('email', ''));
            $user->setAttribute('impersonatorAccessedAt', $impersonator->getAttribute('accessedAt', 0));
        }
    }

    $dbForProject->setMetadata('user', $user->getId());
    $dbForPlatform->setMetadata('user', $user->getId());

    return $user;
}, ['mode', 'project', 'console', 'request', 'response', 'dbForProject', 'dbForPlatform', 'store', 'proofForToken', 'authorization']);

Http::setResource('project', function ($dbForPlatform, $request, $console, $authorization, Http $utopia) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Utopia\Database\Database $dbForPlatform */
    /** @var Utopia\Database\Document $console */
    $projectId = $request->getParam('project', $request->getHeader('x-appwrite-project', ''));
    // Realtime channel "project" can send project=Query array
    if (! \is_string($projectId)) {
        $projectId = $request->getHeader('x-appwrite-project', '');
    }

    // Backwards compatibility for new services, originally project resources
    // These endpoints moved from /v1/projects/:projectId/<resource> to /v1/<resource>
    // When accessed via the old alias path, extract projectId from the URI
    $deprecatedProjectPathPrefix = '/v1/projects/';
    $route = $utopia->match($request);
    if (!empty($route)) {
        $isDeprecatedAlias = \str_starts_with($request->getURI(), $deprecatedProjectPathPrefix) &&
            !\str_starts_with($route->getPath(), $deprecatedProjectPathPrefix);

        if ($isDeprecatedAlias) {
            $projectId = \explode('/', $request->getURI(), 5)[3] ?? '';
        }
    }

    if (empty($projectId) || $projectId === 'console') {
        return $console;
    }

    $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

    return $project;
}, ['dbForPlatform', 'request', 'console', 'authorization', 'utopia']);

Http::setResource('session', function (User $user, Store $store, Token $proofForToken) {
    if ($user->isEmpty()) {
        return;
    }

    $sessions = $user->getAttribute('sessions', []);
    $sessionId = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken);

    if (! $sessionId) {
        return;
    }
    foreach ($sessions as $session) {
        /** @var Document $session */
        if ($sessionId === $session->getId()) {
            return $session;
        }
    }

}, ['user', 'store', 'proofForToken']);

Http::setResource('store', function (): Store {
    return new Store();
});

Http::setResource('proofForPassword', function (): Password {
    $hash = new Argon2();
    $hash
        ->setMemoryCost(7168)
        ->setTimeCost(5)
        ->setThreads(1);

    $password = new Password();
    $password
        ->setHash($hash);

    return $password;
});

Http::setResource('proofForToken', function (): Token {
    $token = new Token();
    $token->setHash(new Sha());

    return $token;
});

Http::setResource('proofForCode', function (): Code {
    $code = new Code();
    $code->setHash(new Sha());

    return $code;
});

$container->set('console', function () {
    return new Document(Config::getParam('console'));
}, []);

$container->set('authorization', function () {
    return new Authorization();
}, []);

$container->set('dbForPlatform', function (Group $pools, Cache $cache, Authorization $authorization) {

    $adapter = new DatabasePool($pools->get('console'));
    $database = new Database($adapter, $cache);

    $database
        ->setDatabase(APP_DATABASE)
        ->setAuthorization($authorization)
        ->setNamespace('_console')
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', 'console')
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
        ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

    $database->setDocumentType('users', User::class);

    return $database;
}, ['pools', 'cache', 'authorization']);

$container->set('getLogsDB', function (Group $pools, Cache $cache, Authorization $authorization) {
    $database = null;

    return function (?Document $project = null) use ($pools, $cache, $authorization, &$database) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
            return $database;
        }

        $adapter = new DatabasePool($pools->get('logs'));
        $database = new Database($adapter, $cache);

        $database
            ->setDatabase(APP_DATABASE)
            ->setAuthorization($authorization)
            ->setSharedTables(true)
            ->setNamespace('logsV1')
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
        }

        return $database;
    };
}, ['pools', 'cache', 'authorization']);

$container->set('telemetry', fn () => new NoTelemetry());

$container->set('cache', function (Group $pools, Telemetry $telemetry) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = new CachePool($pools->get($value));
    }

    $cache = new Cache(new Sharding($adapters));
    $cache->setTelemetry($telemetry);

    return $cache;
}, ['pools', 'telemetry']);

$container->set('redis', function () {
    $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
    $port = System::getEnv('_APP_REDIS_PORT', 6379);
    $pass = System::getEnv('_APP_REDIS_PASS', '');

    $redis = new \Redis();
    @$redis->pconnect($host, (int) $port);
    if ($pass) {
        $redis->auth($pass);
    }
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

$container->set('timelimit', function (\Redis $redis) {
    return function (string $key, int $limit, int $time) use ($redis) {
        return new TimeLimitRedis($key, $limit, $time, $redis);
    };
}, ['redis']);

$container->set('deviceForLocal', function (Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, new Local());
}, ['telemetry']);
function getDevice(string $root, string $connection = ''): Device
{
    $connection = ! empty($connection) ? $connection : System::getEnv('_APP_CONNECTIONS_STORAGE', '');

    if (! empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';
        $url = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $bucket = $dsn->getPath() ?? '';
            $region = $dsn->getParam('region');
        } catch (\Throwable $e) {
            Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                if (! empty($url)) {
                    $bucketRoot = (! empty($bucket) ? $bucket . '/' : '') . \ltrim($root, '/');

                    return new S3($bucketRoot, $accessKey, $accessSecret, $url, $region, $acl);
                } else {
                    return new AWS($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                }
                // no break
            case STORAGE::DEVICE_DO_SPACES:
                $device = new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                $device->setHttpVersion(S3::HTTP_VERSION_1_1);

                return $device;
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    } else {
        switch (strtolower(System::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = System::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = System::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = System::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = System::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                $s3EndpointUrl = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');
                if (! empty($s3EndpointUrl)) {
                    $bucketRoot = (! empty($s3Bucket) ? $s3Bucket . '/' : '') . \ltrim($root, '/');

                    return new S3($bucketRoot, $s3AccessKey, $s3SecretKey, $s3EndpointUrl, $s3Region, $s3Acl);
                } else {
                    return new AWS($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
                }
                // no break
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = System::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = System::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = System::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                $device = new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
                $device->setHttpVersion(S3::HTTP_VERSION_1_1);

                return $device;
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = System::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = System::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = System::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = System::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';

                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = System::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = System::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = System::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = System::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';

                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = System::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = System::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = System::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = System::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';

                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}

$container->set('geodb', function ($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('geodb');
}, ['register']);

$container->set('passwordsDictionary', function ($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('passwordsDictionary');
}, ['register']);

$container->set('servers', function () {
    $platforms = Config::getParam('sdks');
    $server = $platforms[APP_SDK_PLATFORM_SERVER];

    $languages = array_map(function ($language) {
        return strtolower($language['name']);
    }, $server['sdks']);

    return $languages;
});

$container->set('promiseAdapter', function ($register) {
    return $register->get('promiseAdapter');
}, ['register']);

$container->set('gitHub', function (Cache $cache) {
    return new VcsGitHub($cache);
}, ['cache']);

$container->set('plan', function (array $plan = []) {
    return [];
});

$container->set('smsRates', function () {
    return [];
});

$container->set(
    'isResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false
);

$container->set('executor', fn () => new Executor());
