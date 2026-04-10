<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Pools\Group;
use Utopia\System\System;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * Register per-connection resources on the given container.
 * These resources depend on the realtime connection context
 * and must be fresh for each websocket connection.
 */
return function (Container $container): void {
    $container->set('authorization', function () {
        return new Authorization();
    }, []);

    $container->set('store', function (): Store {
        return new Store();
    }, []);

    $container->set('proofForToken', function (): Token {
        $token = new Token();
        $token->setHash(new Sha());

        return $token;
    });

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

    $container->set('projectId', function (Request $request) {
        $projectId = $request->getHeader('x-appwrite-project', '');

        if (!empty($projectId)) {
            return $projectId;
        }

        $projectId = $request->getParam('project', '');

        return \is_string($projectId) ? $projectId : '';
    }, ['request']);

    $container->set('project', function (Database $dbForPlatform, string $projectId, Document $console, Authorization $authorization) {
        if (empty($projectId) || $projectId === 'console') {
            return $console;
        }

        return $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
    }, ['dbForPlatform', 'projectId', 'console', 'authorization']);

    $container->set('mode', function (Request $request, Document $project, string $projectId) {
        $mode = $request->getParam('mode', $request->getHeader('x-appwrite-mode', APP_MODE_DEFAULT));

        if (!empty($projectId) && $project->getId() !== $projectId) {
            $mode = APP_MODE_ADMIN;
        }

        return $mode;
    }, ['request', 'project', 'projectId']);

    $container->set('dbForProject', function (Group $pools, Database $dbForPlatform, Cache $cache, Document $project, Authorization $authorization) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForPlatform;
        }

        $database = $project->getAttribute('database', '');
        if (empty($database)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Project database is not configured');
        }

        try {
            $dsn = new DSN($database);
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $database);
        }

        $adapter = new DatabasePool($pools->get($dsn->getHost()));
        $database = new Database($adapter, $cache);

        $database
            ->setDatabase(APP_DATABASE)
            ->setAuthorization($authorization)
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId())
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);
        $database->setDocumentType('users', User::class);

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $database
                ->setSharedTables(true)
                ->setTenant($project->getSequence())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getSequence());
        }

        return $database;
    }, ['pools', 'dbForPlatform', 'cache', 'project', 'authorization']);

    $container->set('allowedHostnames', function (array $platform, Document $project, Document $rule, Document $devKey, Request $request) {
        $allowed = [...($platform['hostnames'] ?? [])];

        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $allowed = [...$allowed, ...Platform::getHostnames($project->getAttribute('platforms', []))];
        }

        if (!$devKey->isEmpty()) {
            $allowed[] = $request->getHostname();
        }

        $originHostname = \parse_url($request->getOrigin(), PHP_URL_HOST);
        $refererHostname = \parse_url($request->getReferer(), PHP_URL_HOST);
        $hostname = $originHostname ?: $refererHostname;

        if ($request->getMethod() === 'OPTIONS' && !empty($hostname)) {
            $allowed[] = $hostname;
        }

        if (!$rule->isEmpty() && !empty($rule->getAttribute('domain', ''))) {
            $allowed[] = $rule->getAttribute('domain', '');
        }

        if (!$devKey->isEmpty() && !empty($hostname)) {
            $allowed[] = $hostname;
        }

        return \array_unique($allowed);
    }, ['platform', 'project', 'rule', 'devKey', 'request']);

    $container->set('allowedSchemes', function (array $platform, Document $project) {
        $allowed = [...($platform['schemas'] ?? [])];

        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $allowed[] = 'exp';
            $allowed[] = 'appwrite-callback-' . $project->getId();
            $allowed = [...$allowed, ...Platform::getSchemes($project->getAttribute('platforms', []))];
        }

        return \array_unique($allowed);
    }, ['platform', 'project']);

    $container->set('rule', function (Request $request, Database $dbForPlatform, Document $project, Authorization $authorization) {
        $domain = \parse_url($request->getOrigin(), PHP_URL_HOST);

        if (empty($domain)) {
            $domain = \parse_url($request->getReferer(), PHP_URL_HOST);
        }

        if (empty($domain)) {
            return new Document();
        }

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

        if (!$permitsCurrentProject && !$rule->isEmpty() && !empty($rule->getAttribute('projectId', ''))) {
            $trustedProjects = [];
            foreach (\explode(',', System::getEnv('_APP_CONSOLE_TRUSTED_PROJECTS', '')) as $trustedProject) {
                if (empty($trustedProject)) {
                    continue;
                }
                $trustedProjects[] = $trustedProject;
            }

            if (\in_array($rule->getAttribute('projectId', ''), $trustedProjects, true)) {
                $permitsCurrentProject = true;
            }
        }

        if (!$permitsCurrentProject) {
            return new Document();
        }

        return $rule;
    }, ['request', 'dbForPlatform', 'project', 'authorization']);

    $container->set('devKey', function (Request $request, Document $project, array $servers, Database $dbForPlatform, Authorization $authorization) {
        $devKey = $request->getHeader('x-appwrite-dev-key', $request->getParam('devKey', ''));

        $key = $project->find('secret', $devKey, 'devKeys');
        if (!$key) {
            return new Document([]);
        }

        $expire = $key->getAttribute('expire');
        if (!empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
            return new Document([]);
        }

        $accessedAt = $key->getAttribute('accessedAt', 0);
        if (empty($accessedAt) || DatabaseDateTime::formatTz(DatabaseDateTime::addSeconds(new \DateTime(), -APP_KEY_ACCESS)) > $accessedAt) {
            $key->setAttribute('accessedAt', DatabaseDateTime::now());
            $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), new Document([
                'accessedAt' => $key->getAttribute('accessedAt'),
            ])));
            $dbForPlatform->purgeCachedDocument('projects', $project->getId());
        }

        $sdkValidator = new WhiteList($servers, true);
        $sdk = \strtolower($request->getHeader('x-sdk-name', 'UNKNOWN'));

        if ($sdk !== 'UNKNOWN' && $sdkValidator->isValid($sdk)) {
            $sdks = $key->getAttribute('sdks', []);

            if (!\in_array($sdk, $sdks, true)) {
                $sdks[] = $sdk;
                $key->setAttribute('sdks', $sdks);
                $key->setAttribute('accessedAt', DatabaseDateTime::now());

                $key = $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), new Document([
                    'sdks' => $key->getAttribute('sdks'),
                    'accessedAt' => $key->getAttribute('accessedAt'),
                ])));
                $dbForPlatform->purgeCachedDocument('projects', $project->getId());
            }
        }

        return $key;
    }, ['request', 'project', 'servers', 'dbForPlatform', 'authorization']);

    $container->set('originValidator', function (Document $devKey, array $allowedHostnames, array $allowedSchemes) {
        if (!$devKey->isEmpty()) {
            return new URL();
        }

        return new Origin($allowedHostnames, $allowedSchemes);
    }, ['devKey', 'allowedHostnames', 'allowedSchemes']);

    $container->set('user', function (string $mode, Document $project, Document $console, Request $request, Database $dbForProject, Database $dbForPlatform, Store $store, Token $proofForToken, Authorization $authorization) {
        $authorization->setDefaultStatus(true);

        $store->setKey('a_session_' . $project->getId());

        if ($mode === APP_MODE_ADMIN) {
            $store->setKey('a_session_' . $console->getId());
        }

        $store->decode(
            $request->getCookie(
                $store->getKey(),
                $request->getCookie($store->getKey() . '_legacy', '')
            )
        );

        if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
            $sessionHeader = $request->getHeader('x-appwrite-session', '');

            if (!empty($sessionHeader)) {
                $store->decode($sessionHeader);
            }
        }

        if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
            $fallback = \json_decode($request->getHeader('x-fallback-cookies', ''), true);
            $store->decode((\is_array($fallback) && isset($fallback[$store->getKey()])) ? $fallback[$store->getKey()] : '');
        }

        $user = null;
        if ($mode === APP_MODE_ADMIN) {
            /** @var User $user */
            $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
        } else {
            if ($project->isEmpty()) {
                $user = new User([]);
            } elseif (!empty($store->getProperty('id', ''))) {
                if ($project->getId() === 'console') {
                    /** @var User $user */
                    $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
                } else {
                    /** @var User $user */
                    $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
                }
            }
        }

        if (
            !$user
            || $user->isEmpty()
            || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
        ) {
            $user = new User([]);
        }

        $authJWT = $request->getHeader('x-appwrite-jwt', '');
        if (!empty($authJWT) && !$project->isEmpty()) {
            if (!$user->isEmpty()) {
                throw new Exception(Exception::USER_JWT_AND_COOKIE_SET);
            }

            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);
            try {
                $payload = $jwt->decode($authJWT);
            } catch (JWTException $error) {
                throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
            }

            $jwtUserId = $payload['userId'] ?? '';
            if (!empty($jwtUserId)) {
                if ($mode === APP_MODE_ADMIN) {
                    $user = $dbForPlatform->getDocument('users', $jwtUserId);
                } else {
                    $user = $dbForProject->getDocument('users', $jwtUserId);
                }
            }

            $jwtSessionId = $payload['sessionId'] ?? '';
            if (!empty($jwtSessionId) && empty($user->find('$id', $jwtSessionId, 'sessions'))) {
                $user = new User([]);
            }
        }

        $accountKey = $request->getHeader('x-appwrite-key', '');
        $accountKeyUserId = $request->getHeader('x-appwrite-user', '');
        if (!empty($accountKeyUserId) && !empty($accountKey)) {
            if (!$user->isEmpty()) {
                throw new Exception(Exception::USER_API_KEY_AND_SESSION_SET);
            }

            $accountKeyUser = $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->getDocument('users', $accountKeyUserId));
            if (!$accountKeyUser->isEmpty()) {
                $key = $accountKeyUser->find(
                    key: 'secret',
                    find: $accountKey,
                    subject: 'keys',
                );

                if (!empty($key)) {
                    $expire = $key->getAttribute('expire');
                    if (!empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
                        throw new Exception(Exception::ACCOUNT_KEY_EXPIRED);
                    }

                    $user = $accountKeyUser;
                }
            }
        }

        $impersonateUserId = $request->getHeader('x-appwrite-impersonate-user-id', '');
        $impersonateEmail = $request->getHeader('x-appwrite-impersonate-user-email', '');
        $impersonatePhone = $request->getHeader('x-appwrite-impersonate-user-phone', '');
        if (!$user->isEmpty() && $user->getAttribute('impersonator', false)) {
            $userDb = ($mode === APP_MODE_ADMIN || $project->getId() === 'console') ? $dbForPlatform : $dbForProject;
            $targetUser = null;

            if (!empty($impersonateUserId)) {
                $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->getDocument('users', $impersonateUserId));
            } elseif (!empty($impersonateEmail)) {
                $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [
                    Query::equal('email', [\strtolower($impersonateEmail)]),
                ]));
            } elseif (!empty($impersonatePhone)) {
                $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [
                    Query::equal('phone', [$impersonatePhone]),
                ]));
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
    }, ['mode', 'project', 'console', 'request', 'dbForProject', 'dbForPlatform', 'store', 'proofForToken', 'authorization']);
};
