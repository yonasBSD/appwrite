<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Exception as GQLException;
use Appwrite\Promises\Swoole;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Coroutine;
use Utopia\DI\Container;
use Utopia\Http\Exception;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\System\System;

class Resolvers
{
    /**
     * Request-scoped locks keyed by the per-request GraphQL Http instance.
     *
     * @var \WeakMap<Http, ResolverLock>|null
     */
    private static ?\WeakMap $locks = null;

    /**
     * Clone the shared GraphQL response so each resolver writes into an
     * isolated payload and status buffer.
     */
    private static function createResolverResponse(Http $utopia): Response
    {
        /** @var Response $response */
        $response = clone $utopia->getResource('response');

        return $response;
    }

    /**
     * Preserve response side effects that callers depend on, such as session
     * cookies created by account auth routes.
     */
    private static function mergeResponseSideEffects(Response $from, Response $to): void
    {
        foreach ($from->getCookies() as $cookie) {
            $to->removeCookie($cookie['name']);
            $to->addCookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly'],
                $cookie['samesite']
            );
        }

        $headers = $from->getHeaders();
        $fallbackCookies = $headers['X-Fallback-Cookies'] ?? null;
        if ($fallbackCookies === null) {
            return;
        }

        $to->removeHeader('X-Fallback-Cookies');
        foreach ((array) $fallbackCookies as $value) {
            $to->addHeader('X-Fallback-Cookies', $value);
        }
    }

    /**
     * Get the current request container.
     */
    private static function getResolverContainer(Http $utopia): Container
    {
        $container = $utopia->getResource('container');

        if ($container instanceof Container || (\is_object($container) && \method_exists($container, 'get') && \method_exists($container, 'set'))) {
            /** @var Container $container */
            return $container;
        }

        /** @var callable(): Container $container */
        return $container();
    }

    /**
     * Get the request-scoped lock shared by GraphQL resolver coroutines
     * for the current HTTP request.
     */
    private static function getLock(Http $utopia): ResolverLock
    {
        self::$locks ??= new \WeakMap();
        if (!isset(self::$locks[$utopia])) {
            self::$locks[$utopia] = new ResolverLock();
        }

        return self::$locks[$utopia];
    }

    /**
     * Acquire the request-scoped resolver lock. Re-entering from the
     * same coroutine only increments depth to avoid self-deadlock.
     */
    private static function acquireLock(ResolverLock $lock): void
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
     */
    private static function releaseLock(ResolverLock $lock): void
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
            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $route, $args) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                self::resolve(
                    $utopia,
                    $request,
                    $response,
                    $resolve,
                    $reject,
                    prepareRequest: static function (Request $request) use ($route, $args): void {
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
                    }
                );
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
            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $args) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                self::resolve(
                    $utopia,
                    $request,
                    $response,
                    $resolve,
                    $reject,
                    prepareRequest: static function (Request $request) use ($databaseId, $collectionId, $url, $args): void {
                        $request->setMethod('GET');
                        $request->setURI($url($databaseId, $collectionId, $args));
                    }
                );
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
            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $params, $args) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                $beforeResolve = function ($payload) {
                    return $payload['documents'];
                };

                self::resolve(
                    $utopia,
                    $request,
                    $response,
                    $resolve,
                    $reject,
                    beforeResolve: $beforeResolve,
                    prepareRequest: static function (Request $request) use ($databaseId, $collectionId, $url, $params, $args): void {
                        $request->setMethod('GET');
                        $request->setURI($url($databaseId, $collectionId, $args));
                        $request->setQueryString($params($databaseId, $collectionId, $args));
                    }
                );
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
            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $params, $args) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                self::resolve(
                    $utopia,
                    $request,
                    $response,
                    $resolve,
                    $reject,
                    prepareRequest: static function (Request $request) use ($databaseId, $collectionId, $url, $params, $args): void {
                        $request->setMethod('POST');
                        $request->setURI($url($databaseId, $collectionId, $args));
                        $request->setPayload($params($databaseId, $collectionId, $args));
                    }
                );
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
            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $params, $args) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                self::resolve(
                    $utopia,
                    $request,
                    $response,
                    $resolve,
                    $reject,
                    prepareRequest: static function (Request $request) use ($databaseId, $collectionId, $url, $params, $args): void {
                        $request->setMethod('PATCH');
                        $request->setURI($url($databaseId, $collectionId, $args));
                        $request->setPayload($params($databaseId, $collectionId, $args));
                    }
                );
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
            return new Swoole(function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $url, $args) {
                $utopia = $utopia->getResource('utopia:graphql');
                $request = $utopia->getResource('request');
                $response = $utopia->getResource('response');

                self::resolve(
                    $utopia,
                    $request,
                    $response,
                    $resolve,
                    $reject,
                    prepareRequest: static function (Request $request) use ($databaseId, $collectionId, $url, $args): void {
                        $request->setMethod('DELETE');
                        $request->setURI($url($databaseId, $collectionId, $args));
                    }
                );
            });
        })();
    }

    /**
     * @param Http $utopia
     * @param Request $request
     * @param Response $response
     * @param callable $resolve
     * @param callable $reject
     * @param callable|null $beforeResolve
     * @param callable|null $prepareRequest
     * @return void
     * @throws Exception
     */
    private static function resolve(
        Http $utopia,
        Request $request,
        Response $response,
        callable $resolve,
        callable $reject,
        ?callable $beforeResolve = null,
        ?callable $prepareRequest = null,
    ): void {
        $lock = self::getLock($utopia);

        self::acquireLock($lock);

        $original = $utopia->getRoute();
        try {
            $request = clone $request;

            // Drop json content type so post args are used directly.
            if (\str_starts_with($request->getHeader('content-type'), 'application/json')) {
                $request->removeHeader('content-type');
            }

            if ($prepareRequest) {
                $prepareRequest($request);
            }

            $resolverResponse = self::createResolverResponse($utopia);
            $container = self::getResolverContainer($utopia);
            $container->set('request', static fn () => $request);
            $container->set('response', static fn () => $resolverResponse);
            $resolverResponse->setContentType(Response::CONTENT_TYPE_NULL);
            $resolverResponse->clearSent();

            $route = $utopia->match($request, fresh: true);
            $request->setRoute($route);

            $utopia->execute($route, $request, $resolverResponse);

            self::mergeResponseSideEffects($resolverResponse, $response);

            if ($resolverResponse->isSent()) {
                $response
                    ->setStatusCode($resolverResponse->getStatusCode())
                    ->markSent();

                $resolve(null);
                return;
            }

            $payload = $resolverResponse->getPayload();
            $statusCode = $resolverResponse->getStatusCode();
        } catch (\Throwable $e) {
            $reject($e);
            return;
        } finally {
            if ($original !== null) {
                $utopia->setRoute($original);
            }

            self::releaseLock($lock);
        }

        if ($statusCode < 200 || $statusCode >= 400) {
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
