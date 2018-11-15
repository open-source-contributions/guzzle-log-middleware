<?php

namespace Gmponos\GuzzleLogger\Middleware;

use Closure;
use Gmponos\GuzzleLogger\Handler\ArrayHandler;
use Gmponos\GuzzleLogger\Handler\HandlerInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * A class to log HTTP Requests and Responses of Guzzle.
 *
 * @author George Mponos <gmponos@gmail.com>
 */
class LoggerMiddleware
{
    /**
     * @var bool
     */
    private $onFailureOnly;

    /**
     * Decides if you need to log statistics or not.
     *
     * @var bool
     */
    private $logStatistics;

    /**
     * @var HandlerInterface
     */
    private $handler;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Creates a callable middleware for logging requests and responses.
     *
     * @param LoggerInterface $logger
     * @param HandlerInterface $handler
     * @param bool $onFailureOnly The request and the response will be logged only in cases there is considered a failure.
     * @param bool $logStatistics If this is true an extra row will be added that will contain some HTTP statistics.
     */
    public function __construct(
        LoggerInterface $logger,
        HandlerInterface $handler = null,
        bool $onFailureOnly = false,
        bool $logStatistics = false
    ) {
        $this->logger = $logger;
        $this->onFailureOnly = $onFailureOnly;
        $this->logStatistics = $logStatistics;
        $this->handler = $handler === null ? new ArrayHandler() : $handler;
    }

    /**
     * Called when the middleware is handled by the client.
     *
     * @param callable $handler
     * @return callable
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $this->setOptions($options);

            if ($this->onFailureOnly === false) {
                $this->handler->log($this->logger, $request, $options);
                if ($this->logStatistics && !isset($options['on_stats'])) {
                    $options['on_stats'] = function (TransferStats $stats) {
                        $this->handler->log($this->logger, $stats);
                    };
                }
            }

            return $handler($request, $options)->then(
                $this->handleSuccess($request, $options),
                $this->handleFailure($request, $options)
            );
        };
    }

    /**
     * Returns a function which is handled when a request was successful.
     *
     * @param RequestInterface $request
     * @param array $options
     * @return Closure
     */
    private function handleSuccess(RequestInterface $request, array $options): callable
    {
        return function (ResponseInterface $response) use ($request, $options) {
            // On exception only is true then it must not log the response since it was successful.
            if ($this->onFailureOnly === false) {
                $this->handler->log($this->logger, $response, $options);
            }

            return $response;
        };
    }

    /**
     * Returns a function which is handled when a request was rejected.
     *
     * @param RequestInterface $request
     * @param array $options
     * @return Closure
     */
    private function handleFailure(RequestInterface $request, array $options): callable
    {
        return function (\Exception $reason) use ($request, $options) {
            if ($this->onFailureOnly === true) {
                // This means that the request was not logged and since an exception happened we need to log the request too.
                $this->handler->log($this->logger, $request, $options);
            }

            if ($reason instanceof RequestException && $reason->hasResponse()) {
                $this->handler->log($this->logger, $reason->getResponse(), $options);
                return \GuzzleHttp\Promise\rejection_for($reason);
            }

            $this->handler->log($this->logger, $reason, $options);
            return \GuzzleHttp\Promise\rejection_for($reason);
        };
    }

    /**
     * @param array $options
     * @return void
     */
    private function setOptions(array $options): void
    {
        if (!isset($options['log'])) {
            return;
        }

        $options = $options['log'];

        $options = array_merge([
            'on_exception_only' => $this->onFailureOnly,
            'statistics' => $this->logStatistics,
        ], $options);

        $this->onFailureOnly = $options['on_exception_only'];
        $this->logStatistics = $options['statistics'];
    }
}
