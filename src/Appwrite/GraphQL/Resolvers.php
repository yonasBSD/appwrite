<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Exception as GQLException;
use Appwrite\Promises\Swoole;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use stdClass;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\DI\Container;
use Utopia\Http\Exception;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\System\System;

class Resolvers
{
    /**
     * Get the current request container.
     */
    private static function getResolverContainer(Http $utopia): Container
    {
        /** @var callable(): Container $getContainer */
        $getContainer = $utopia->getResource('container');

        return $getContainer();
    }

    /**
     * Get the request-scoped lock shared by GraphQL resolver coroutines
     * for the current HTTP request.
     *
     * @return stdClass{channel: Channel, owner: int|null, depth: int}
     */
    private static function getLock(Http $utopia): stdClass
    {
        $container = self::getResolverContainer($utopia);

        if (!$container->has('graphql:lock')) {
            $lock = new stdClass();
            $lock->channel = new Channel(1);
            $lock->owner = null;
            $lock->depth = 0;

            $container->set('graphql:lock', static fn () => $lock);
        }

        /** @var stdClass{channel: Channel, owner: int|null, depth: int} $lock */
        $lock = $container->get('graphql:lock');

        return $lock;
    }

    /**
     * Acquire the request-scoped resolver lock. Re-entering from the
     * same coroutine only increments depth to avoid self-deadlock.
     *
     * @param stdClass{channel: Channel, owner: int|null, depth: int} $lock
     */
    private static function acquireLock(stdClass $lock): void
    {
        $cid = Coroutine::getCid();

        if ($lock->owner === $cid) {
            $lock->depth++;
            return;
        }

        $lock->channel->push(true);
        $lock->owner = $cid;
        $lock->depth = 1;
    }

    /**
     * Release the request-scoped resolver lock.
     *
     * @param stdClass{channel: Channel, owner: int|null, depth: int} $lock
     */
    private static function releaseLock(stdClass $lock): void
    {
        if ($lock->owner !== Coroutine::getCid()) {
            return;
        }

        $lock->depth--;

        if ($lock->depth > 0) {
            return;
        }

        $lock->owner = null;
        $lock->channel->pop();
    }
    /**
     * Create a resolver for a given API {@see Route}.
     *
     * @param Http $utopia
     * @param ?Route $route
     * @return callable
     */
    public static function api(
        Http $utopia,
        ?Route $route,
    ): callable {
        return static fn ($type, $args, $context, $info) => (function () use ($utopia, $route, $args) {
            $lock = self::getLock($utopia);

            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $route, $args, $lock) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $path = $route->getPath();
                foreach ($args as $key => $value) {
                    if (\str_contains($path, '/:' . $key)) {
                        $path = \str_replace(':' . $key, $value, $path);
                    }
                }

                $request->setMethod($route->getMethod());
                $request->setURI($path);

                switch ($route->getMethod()) {
                    case 'GET':
                        $request->setQueryString($args);
                        break;
                    default:
                        $request->setPayload($args);
                        break;
                }

                self::resolve($utopia, $request, $response, $lock, $resolve, $reject);
            });
        })();
    }

    /**
     * Create a resolver for a document in a specified database and collection with a specific method type.
     *
     * @param Http $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @param string $methodType
     * @return callable
     */
    public static function document(
        Http $utopia,
        string $databaseId,
        string $collectionId,
        string $methodType,
    ): callable {
        return [self::class, 'document' . \ucfirst($methodType)](
            $utopia,
            $databaseId,
            $collectionId
        );
    }

    /**
     * Create a resolver for getting a document in a specified database and collection.
     *
     * @param Http $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @return callable
     */
    public static function documentGet(
        Http $utopia,
        string $databaseId,
        string $collectionId,
        callable $url,
    ): callable {
        return static fn ($type, $args, $context, $info) => (function () use ($utopia, $databaseId, $collectionId, $url, $args) {
            $lock = self::getLock($utopia);

            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $args, $lock) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $request->setMethod('GET');
                $request->setURI($url($databaseId, $collectionId, $args));

                self::resolve($utopia, $request, $response, $lock, $resolve, $reject);
            });
        })();
    }

    /**
     * Create a resolver for listing documents in a specified database and collection.
     *
     * @param Http $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @param callable $params
     * @return callable
     */
    public static function documentList(
        Http $utopia,
        string $databaseId,
        string $collectionId,
        callable $url,
        callable $params,
    ): callable {
        return static fn ($type, $args, $context, $info) => (function () use ($utopia, $databaseId, $collectionId, $url, $params, $args) {
            $lock = self::getLock($utopia);

            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $params, $args, $lock) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $request->setMethod('GET');
                $request->setURI($url($databaseId, $collectionId, $args));
                $request->setQueryString($params($databaseId, $collectionId, $args));

                $beforeResolve = function ($payload) {
                    return $payload['documents'];
                };

                self::resolve($utopia, $request, $response, $lock, $resolve, $reject, $beforeResolve);
            });
        })();
    }

    /**
     * Create a resolver for creating a document in a specified database and collection.
     *
     * @param Http $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @param callable $params
     * @return callable
     */
    public static function documentCreate(
        Http $utopia,
        string $databaseId,
        string $collectionId,
        callable $url,
        callable $params,
    ): callable {
        return static fn ($type, $args, $context, $info) => (function () use ($utopia, $databaseId, $collectionId, $url, $params, $args) {
            $lock = self::getLock($utopia);

            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $params, $args, $lock) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $request->setMethod('POST');
                $request->setURI($url($databaseId, $collectionId, $args));
                $request->setPayload($params($databaseId, $collectionId, $args));

                self::resolve($utopia, $request, $response, $lock, $resolve, $reject);
            });
        })();
    }

    /**
     * Create a resolver for updating a document in a specified database and collection.
     *
     * @param Http $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @param callable $params
     * @return callable
     */
    public static function documentUpdate(
        Http $utopia,
        string $databaseId,
        string $collectionId,
        callable $url,
        callable $params,
    ): callable {
        return static fn ($type, $args, $context, $info) => (function () use ($utopia, $databaseId, $collectionId, $url, $params, $args) {
            $lock = self::getLock($utopia);

            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $params, $args, $lock) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $request->setMethod('PATCH');
                $request->setURI($url($databaseId, $collectionId, $args));
                $request->setPayload($params($databaseId, $collectionId, $args));

                self::resolve($utopia, $request, $response, $lock, $resolve, $reject);
            });
        })();
    }

    /**
     * Create a resolver for deleting a document in a specified database and collection.
     *
     * @param Http $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @return callable
     */
    public static function documentDelete(
        Http $utopia,
        string $databaseId,
        string $collectionId,
        callable $url,
    ): callable {
        return static fn ($type, $args, $context, $info) => (function () use ($utopia, $databaseId, $collectionId, $url, $args) {
            $lock = self::getLock($utopia);

            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $args, $lock) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $request->setMethod('DELETE');
                $request->setURI($url($databaseId, $collectionId, $args));

                self::resolve($utopia, $request, $response, $lock, $resolve, $reject);
            });
        })();
    }

    /**
     * @param Http $utopia
     * @param Request $request
     * @param Response $response
     * @param stdClass{channel: Channel, owner: int|null, depth: int} $lock
     * @param callable $resolve
     * @param callable $reject
     * @param callable|null $beforeResolve
     * @param callable|null $beforeReject
     * @return void
     * @throws Exception
     */
    private static function resolve(
        Http $utopia,
        Request $request,
        Response $response,
        stdClass $lock,
        callable $resolve,
        callable $reject,
        ?callable $beforeResolve = null,
        ?callable $beforeReject = null,
    ): void {
        // Drop json content type so post args are used directly
        if (\str_starts_with($request->getHeader('content-type'), 'application/json')) {
            $request->removeHeader('content-type');
        }

        $request = clone $request;
        $utopia->setResource('request', static fn () => $request);

        self::acquireLock($lock);
        try {
            $response->setContentType(Response::CONTENT_TYPE_NULL);
            $response->clearSent();

            $route = $utopia->match($request, fresh: true);

            $utopia->execute($route, $request, $response);

            $payload = $response->getPayload();
            $statusCode = $response->getStatusCode();
        } catch (\Throwable $e) {
            self::releaseLock($lock);
            if ($beforeReject) {
                $e = $beforeReject($e);
            }
            $reject($e);
            return;
        }
        self::releaseLock($lock);

        if ($statusCode < 200 || $statusCode >= 400) {
            if ($beforeReject) {
                $payload = $beforeReject($payload);
            }
            $reject(new GQLException(
                message: $payload['message'],
                code: $statusCode
            ));
            return;
        }

        $payload = self::escapePayload($payload, 1);

        if ($beforeResolve) {
            $payload = $beforeResolve($payload);
        }

        $resolve($payload);
    }

    private static function escapePayload(array $payload, int $depth)
    {
        if ($depth > System::getEnv('_APP_GRAPHQL_MAX_DEPTH', 3)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (\str_starts_with($key, '$')) {
                $escapedKey = \str_replace('$', '_', $key);
                $payload[$escapedKey] = $value;
                unset($payload[$key]);
            }

            if (\is_array($value)) {
                $payload[$key] = self::escapePayload($value, $depth + 1);
            }
        }

        return $payload;
    }
}
