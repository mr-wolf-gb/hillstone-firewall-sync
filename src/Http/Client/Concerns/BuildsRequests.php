<?php

namespace MrWolfGb\HillstoneFirewall\Http\Client\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

trait BuildsRequests
{
    /**
     * Build a base HTTP request with common configuration
     */
    protected function buildBaseRequest(): PendingRequest
    {
        $request = Http::timeout($this->config['connection']['timeout'] ?? 30)
            ->connectTimeout($this->config['connection']['connect_timeout'] ?? 10)
            ->withOptions([
                'verify' => $this->config['connection']['verify_ssl'] ?? true,
                'read_timeout' => $this->config['connection']['read_timeout'] ?? 30,
            ]);

        // Add request logging if enabled
        if ($this->config['logging']['log_requests'] ?? false) {
            $request->beforeSending(function ($request, $options) {
                $this->logRequest($request, $options);
            });
        }

        // Add response logging if enabled
        if ($this->config['logging']['log_responses'] ?? false) {
            $request->withResponseMiddleware(function ($response) {
                $this->logResponse($response);
                return $response;
            });
        }

        return $request;
    }

    /**
     * Build an authenticated request with cookies
     */
    protected function buildAuthenticatedRequest(): PendingRequest
    {
        $request = $this->buildBaseRequest();

        // Add authentication cookies if available
        $cookies = $this->authService->getAuthCookies();
        if ($cookies) {
            $cookieString = $this->authService->buildCookieString($cookies);
            $request->withHeaders(['Cookie' => $cookieString]);
        }

        return $request;
    }

    /**
     * Build request URL with base URL and endpoint
     */
    protected function buildUrl(string $endpoint): string
    {
        $baseUrl = rtrim($this->config['connection']['base_url'], '/');
        $endpoint = ltrim($endpoint, '/');
        
        return "{$baseUrl}/{$endpoint}";
    }

    /**
     * Build request with retry configuration
     */
    protected function buildRetryableRequest(): PendingRequest
    {
        $request = $this->buildAuthenticatedRequest();
        
        $retryAttempts = $this->config['sync']['retry_attempts'] ?? 3;
        $retryDelay = $this->config['sync']['retry_delay'] ?? 5;
        $retryMultiplier = $this->config['sync']['retry_multiplier'] ?? 2;
        $maxRetryDelay = $this->config['sync']['max_retry_delay'] ?? 60;

        return $request->retry($retryAttempts, function ($attempt) use ($retryDelay, $retryMultiplier, $maxRetryDelay) {
            $delay = min($retryDelay * pow($retryMultiplier, $attempt - 1), $maxRetryDelay);
            return $delay * 1000; // Convert to milliseconds
        }, function ($exception, $request) {
            // Determine if we should retry based on the exception/response
            return $this->shouldRetryRequest($exception, $request);
        });
    }

    /**
     * Add common headers to request
     */
    protected function addCommonHeaders(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-Hillstone-Client/1.0',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    /**
     * Determine if a request should be retried
     */
    protected function shouldRetryRequest($exception, $request): bool
    {
        // Retry on network errors
        if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return true;
        }

        // Retry on timeout errors
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $response = $exception->response;
            
            // Retry on server errors (5xx)
            if ($response && $response->status() >= 500) {
                return true;
            }
            
            // Retry on rate limiting (429)
            if ($response && $response->status() === 429) {
                return true;
            }
            
            // Don't retry on authentication errors (401, 403)
            if ($response && in_array($response->status(), [401, 403])) {
                return false;
            }
        }

        return false;
    }

    /**
     * Log outgoing request details
     */
    protected function logRequest($request, $options): void
    {
        if (!($this->config['logging']['log_requests'] ?? false)) {
            return;
        }

        $channel = $this->config['logging']['channel'] ?? 'default';
        
        \Illuminate\Support\Facades\Log::channel($channel)->debug('Hillstone API Request', [
            'method' => $request->method(),
            'url' => $request->url(),
            'headers' => $this->sanitizeHeaders($request->headers()),
            'body' => $this->sanitizeRequestBody($request->body()),
        ]);
    }

    /**
     * Log incoming response details
     */
    protected function logResponse($response): void
    {
        if (!($this->config['logging']['log_responses'] ?? false)) {
            return;
        }

        $channel = $this->config['logging']['channel'] ?? 'default';
        
        \Illuminate\Support\Facades\Log::channel($channel)->debug('Hillstone API Response', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $this->sanitizeResponseBody($response->body()),
            'response_time' => $response->transferStats?->getTransferTime(),
        ]);
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['cookie', 'authorization', 'x-api-key'];
        
        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = '[REDACTED]';
            }
        }

        return $headers;
    }

    /**
     * Sanitize request body for logging
     */
    protected function sanitizeRequestBody($body): string
    {
        if (empty($body)) {
            return '';
        }

        // If it's JSON, try to parse and sanitize
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $sensitiveFields = ['password', 'token', 'secret', 'key'];
            
            foreach ($sensitiveFields as $field) {
                if (isset($decoded[$field])) {
                    $decoded[$field] = '[REDACTED]';
                }
            }
            
            return json_encode($decoded);
        }

        // For non-JSON, just return truncated version
        return strlen($body) > 1000 ? substr($body, 0, 1000) . '...[TRUNCATED]' : $body;
    }

    /**
     * Sanitize response body for logging
     */
    protected function sanitizeResponseBody($body): string
    {
        if (empty($body)) {
            return '';
        }

        // Truncate large responses
        return strlen($body) > 2000 ? substr($body, 0, 2000) . '...[TRUNCATED]' : $body;
    }
}