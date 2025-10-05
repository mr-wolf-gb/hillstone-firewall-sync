<?php

namespace MrWolfGb\HillstoneFirewallSync\Exceptions;

use Throwable;

/**
 * Exception thrown when authentication with the Hillstone firewall API fails.
 * 
 * This exception is thrown when:
 * - Invalid credentials are provided
 * - Authentication tokens expire and cannot be refreshed
 * - Authentication service is unavailable
 * - Maximum authentication attempts are exceeded
 */
class CouldNotAuthenticateException extends HillstoneException
{
    /**
     * Create a new authentication exception.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param Throwable|null $previous The previous exception
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'Could not authenticate with Hillstone firewall API',
        int $code = 401,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Create an exception for invalid credentials.
     *
     * @param string $username
     * @param array $context
     * @return static
     */
    public static function invalidCredentials(string $username, array $context = []): static
    {
        return new static(
            "Authentication failed for user '{$username}'. Please verify your credentials.",
            401,
            null,
            array_merge(['username' => $username], $context)
        );
    }

    /**
     * Create an exception for token expiration.
     *
     * @param array $context
     * @return static
     */
    public static function tokenExpired(array $context = []): static
    {
        return new static(
            'Authentication token has expired and could not be refreshed.',
            401,
            null,
            $context
        );
    }

    /**
     * Create an exception for authentication service unavailability.
     *
     * @param string $endpoint
     * @param array $context
     * @return static
     */
    public static function serviceUnavailable(string $endpoint, array $context = []): static
    {
        return new static(
            "Authentication service is unavailable at endpoint '{$endpoint}'.",
            503,
            null,
            array_merge(['endpoint' => $endpoint], $context)
        );
    }

    /**
     * Create an exception for maximum authentication attempts exceeded.
     *
     * @param int $maxAttempts
     * @param array $context
     * @return static
     */
    public static function maxAttemptsExceeded(int $maxAttempts, array $context = []): static
    {
        return new static(
            "Maximum authentication attempts ({$maxAttempts}) exceeded. Please try again later.",
            429,
            null,
            array_merge(['max_attempts' => $maxAttempts], $context)
        );
    }
}