<?php

namespace MrWolfGb\HillstoneFirewallSync\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception class for all Hillstone Firewall Sync package exceptions.
 * 
 * Provides common functionality and context handling for all package-specific exceptions.
 */
class HillstoneException extends Exception
{
    /**
     * Additional context data for the exception.
     *
     * @var array
     */
    protected array $context = [];

    /**
     * Create a new Hillstone exception instance.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param Throwable|null $previous The previous exception
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the exception context data.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context data for the exception.
     *
     * @param array $context
     * @return static
     */
    public function setContext(array $context): static
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Add a single context item.
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function addContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get a formatted string representation of the exception with context.
     *
     * @return string
     */
    public function getFormattedMessage(): string
    {
        $message = $this->getMessage();
        
        if (!empty($this->context)) {
            $contextString = json_encode($this->context, JSON_PRETTY_PRINT);
            $message .= "\nContext: " . $contextString;
        }

        return $message;
    }

    /**
     * Convert the exception to an array for logging or API responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
            'trace' => $this->getTraceAsString(),
        ];
    }
}