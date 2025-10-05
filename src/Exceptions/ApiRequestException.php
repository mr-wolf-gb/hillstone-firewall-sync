<?php

namespace MrWolfGb\HillstoneFirewallSync\Exceptions;

use Throwable;

/**
 * Exception thrown when API communication with Hillstone firewall fails.
 * 
 * This exception is thrown when:
 * - Network connectivity issues occur
 * - API endpoints return error responses
 * - Request timeouts occur
 * - Rate limits are exceeded
 * - Invalid API responses are received
 */
class ApiRequestException extends HillstoneException
{
    /**
     * The HTTP status code of the failed request.
     *
     * @var int|null
     */
    protected ?int $httpStatusCode = null;

    /**
     * The response body of the failed request.
     *
     * @var string|null
     */
    protected ?string $responseBody = null;

    /**
     * Create a new API request exception.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param Throwable|null $previous The previous exception
     * @param array $context Additional context data
     * @param int|null $httpStatusCode The HTTP status code
     * @param string|null $responseBody The response body
     */
    public function __construct(
        string $message = 'API request failed',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = [],
        ?int $httpStatusCode = null,
        ?string $responseBody = null
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->httpStatusCode = $httpStatusCode;
        $this->responseBody = $responseBody;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int|null
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get the response body.
     *
     * @return string|null
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * Create an exception for network connectivity issues.
     *
     * @param string $endpoint
     * @param Throwable|null $previous
     * @param array $context
     * @return static
     */
    public static function networkError(string $endpoint, ?Throwable $previous = null, array $context = []): static
    {
        return new static(
            "Network error occurred while connecting to '{$endpoint}'.",
            0,
            $previous,
            array_merge(['endpoint' => $endpoint], $context)
        );
    }

    /**
     * Create an exception for request timeouts.
     *
     * @param string $endpoint
     * @param int $timeout
     * @param array $context
     * @return static
     */
    public static function timeout(string $endpoint, int $timeout, array $context = []): static
    {
        return new static(
            "Request to '{$endpoint}' timed out after {$timeout} seconds.",
            408,
            null,
            array_merge(['endpoint' => $endpoint, 'timeout' => $timeout], $context),
            408
        );
    }

    /**
     * Create an exception for rate limit exceeded.
     *
     * @param int $retryAfter
     * @param array $context
     * @return static
     */
    public static function rateLimitExceeded(int $retryAfter = 0, array $context = []): static
    {
        $message = 'API rate limit exceeded.';
        if ($retryAfter > 0) {
            $message .= " Retry after {$retryAfter} seconds.";
        }

        return new static(
            $message,
            429,
            null,
            array_merge(['retry_after' => $retryAfter], $context),
            429
        );
    }

    /**
     * Create an exception for invalid API responses.
     *
     * @param string $endpoint
     * @param int $statusCode
     * @param string $responseBody
     * @param array $context
     * @return static
     */
    public static function invalidResponse(
        string $endpoint,
        int $statusCode,
        string $responseBody,
        array $context = []
    ): static {
        return new static(
            "Invalid response from '{$endpoint}' (HTTP {$statusCode}).",
            $statusCode,
            null,
            array_merge(['endpoint' => $endpoint], $context),
            $statusCode,
            $responseBody
        );
    }

    /**
     * Create an exception for server errors.
     *
     * @param string $endpoint
     * @param int $statusCode
     * @param string $responseBody
     * @param array $context
     * @return static
     */
    public static function serverError(
        string $endpoint,
        int $statusCode,
        string $responseBody,
        array $context = []
    ): static {
        return new static(
            "Server error from '{$endpoint}' (HTTP {$statusCode}).",
            $statusCode,
            null,
            array_merge(['endpoint' => $endpoint], $context),
            $statusCode,
            $responseBody
        );
    }

    /**
     * Create an exception for client errors.
     *
     * @param string $endpoint
     * @param int $statusCode
     * @param string $responseBody
     * @param array $context
     * @return static
     */
    public static function clientError(
        string $endpoint,
        int $statusCode,
        string $responseBody,
        array $context = []
    ): static {
        return new static(
            "Client error for '{$endpoint}' (HTTP {$statusCode}).",
            $statusCode,
            null,
            array_merge(['endpoint' => $endpoint], $context),
            $statusCode,
            $responseBody
        );
    }

    /**
     * Convert the exception to an array including HTTP-specific data.
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        
        if ($this->httpStatusCode !== null) {
            $data['http_status_code'] = $this->httpStatusCode;
        }
        
        if ($this->responseBody !== null) {
            $data['response_body'] = $this->responseBody;
        }

        return $data;
    }
}