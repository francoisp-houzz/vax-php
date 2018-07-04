<?php

namespace Vendasta\Vax;

use Exception;
use Google\Protobuf\Internal\GPBDecodeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Vendasta\Vax\Auth\FetchAuthTokenCache;
use Vendasta\Vax\Auth\FetchVendastaAuthToken;

/**
 * Class HTTPClient
 * @package Vendasta\Vax
 *
 * Base JSONClient class which adds authorization to all outbound http requests
 */
class HTTPClient extends VAXClient
{
    private $authed_client;
    private $unauthed_client;
    private $host;
    private $secure;

    /**
     * JSONClient constructor.
     * @param string $host
     * @param string $scope
     * @param bool $secure
     * @param float $default_timeout
     * @param bool $include_token
     */
    public function __construct(string $host, string $scope, bool $secure = true, float $default_timeout = 10000)
    {
        parent::__construct($default_timeout);
        $this->host = $host;
        $this->secure = $secure;

        // Build Authed Handler
        $authed_stack = HandlerStack::create();
        $this->addAuthMiddleware($authed_stack, $scope);
        $this->authed_client = new Client(['handler' => $authed_stack]);

        // Build Unauthed Handler
        $stack = HandlerStack::create();
        $this->unauthed_client = new Client(['handler' => $stack]);
    }

    /**
     * @param string $path
     * @param Message $request_class
     * @param Message $reply_class
     * @param array $options possible keys:
     *              \Vendasta\Vax\RequestOptions::*
     *              'method' => string method type
     * @return Message
     * @throws SDKException
     */
    protected function doRequest(string $path, Message $request_class, string $reply_class, array $options = []): Message
    {
        if (!class_exists($reply_class)) {
            throw new SDKException("$reply_class must exist");
        }

        $opts = $this->buildVAXOptions($options);

        $client = ($opts->include_token ? $this->authed_client : $this->unauthed_client);
        $method = (array_key_exists("method", $options) ? $options["method"] : "POST");

        $json = $request_class->serializeToJsonString();
        try {
            $response = $client->request(
                $method,
                $this->buildURL($path),
                [
                    RequestOptions::BODY => $json,
                    RequestOptions::TIMEOUT => $opts->timeout / 1000 // seconds
                ]
            );
        } catch (GuzzleException $e) {
            throw new SDKException("Error calling " . $path . " (code: ". $e->getCode() . "): " . $e->getMessage(), $e->getCode());
        }

        $obj = new $reply_class();
        try {
            if (method_exists($obj, 'mergeFromJsonString')) {
                $obj->mergeFromJsonString((string) $response->getBody());
            } else {
                throw new SDKException("Could not parse obj.");
            }
        } catch (GPBDecodeException $e) {
            throw new SDKException("Error decoding response for path " . $path . ": " . $e->getMessage());
        }

        return $obj;
    }

    private function buildURL(string $path): string {
        if ($this->secure) {
            $scheme = "https";
        } else {
            $scheme = "http";
        }

        return $scheme . "://" . $this->host . $path;
    }

    /**
     * @param HandlerStack $stack
     * @param string $scope
     * @param Environment $env
     */
    private function addAuthMiddleware(HandlerStack $stack, string $scope)
    {
        function authMiddleware(string $scope) {
            $auth = new FetchAuthTokenCache(new FetchVendastaAuthToken($scope));
            return function (callable $handler) use ($auth) {
                return function (RequestInterface $request, array $options) use ($handler, $auth) {
                    $request = $request->withHeader('authorization', 'Bearer ' . $auth->fetchToken());
                    $promise = $handler($request, $options);
                    return $promise->then(
                        function (ResponseInterface $response) use ($auth) {
                            return $response;
                        }
                    );
                };
            };
        }
        $stack->push(Middleware::retry(function($retry, $request, $value, $reason) {
            if ($value !== NULL) {
                return FALSE;
            }
            return $retry < 10;
        }));
        $stack->push(authMiddleware($scope));
    }
}