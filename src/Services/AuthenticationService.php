<?php

namespace MrWolfGb\HillstoneFirewall\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MrWolfGb\HillstoneFirewall\Exceptions\CouldNotAuthenticateException;
use Carbon\Carbon;

class AuthenticationService
{
    protected array $config;
    protected ?array $authCookies = null;
    protected ?Carbon $tokenExpiresAt = null;
    protected string $cacheKey;

    public function __construct(array $config = null)
    {
        $this->config = $config ?? config('hillstone');
        $this->cacheKey = $this->config['cache']['prefix'] . 'auth_token';
    }

    /**
     * Authenticate with the Hillstone firewall API
     */
    public function authenticate(): bool
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // Check if we have valid cached authentication
        if ($this->isAuthenticated()) {
            $this->logAuthenticationEvent('cache_hit', null, null, [
                'duration_seconds' => round(microtime(true) - $startTime, 4),
                'expires_at' => $this->tokenExpiresAt?->toISOString(),
                'expires_in_seconds' => $this->tokenExpiresAt ? max(0, $this->tokenExpiresAt->diffInSeconds(now())) : 0,
            ]);
            return true;
        }

        $this->logAuthenticationEvent('cache_miss', null, null, [
            'reason' => 'no_valid_cached_token',
        ]);

        $maxAttempts = $this->config['authentication']['max_auth_attempts'] ?? 3;
        $retryDelay = $this->config['authentication']['auth_retry_delay'] ?? 5;

        $this->logAuthenticationEvent('authentication_started', null, null, [
            'max_attempts' => $maxAttempts,
            'retry_delay' => $retryDelay,
            'memory_usage_start' => $startMemory,
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attemptStartTime = microtime(true);
            
            try {
                if ($this->performAuthentication()) {
                    $attemptDuration = microtime(true) - $attemptStartTime;
                    $totalDuration = microtime(true) - $startTime;
                    $endMemory = memory_get_usage(true);
                    
                    $this->logAuthenticationEvent('success', $attempt, null, [
                        'attempt_duration_seconds' => round($attemptDuration, 3),
                        'total_duration_seconds' => round($totalDuration, 3),
                        'memory_usage_delta' => $endMemory - $startMemory,
                        'token_expires_at' => $this->tokenExpiresAt?->toISOString(),
                        'token_ttl_seconds' => $this->config['authentication']['token_cache_ttl'] ?? 1200,
                    ]);
                    return true;
                }
            } catch (\Exception $e) {
                $attemptDuration = microtime(true) - $attemptStartTime;
                $sleepTime = $retryDelay * $attempt;
                
                $this->logAuthenticationEvent('failed', $attempt, $e->getMessage(), [
                    'attempt_duration_seconds' => round($attemptDuration, 3),
                    'error_class' => get_class($e),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'will_retry' => $attempt < $maxAttempts,
                    'sleep_before_retry' => $attempt < $maxAttempts ? $sleepTime : 0,
                ]);
                
                if ($attempt < $maxAttempts) {
                    $this->logAuthenticationEvent('retry_delay_started', $attempt, null, [
                        'sleep_seconds' => $sleepTime,
                        'next_attempt' => $attempt + 1,
                    ]);
                    sleep($sleepTime); // Exponential backoff
                } else {
                    $totalDuration = microtime(true) - $startTime;
                    $endMemory = memory_get_usage(true);
                    
                    $this->logAuthenticationEvent('authentication_exhausted', $attempt, $e->getMessage(), [
                        'total_duration_seconds' => round($totalDuration, 3),
                        'memory_usage_delta' => $endMemory - $startMemory,
                        'final_error' => $e->getMessage(),
                    ]);
                    
                    throw new CouldNotAuthenticateException(
                        "Failed to authenticate after {$maxAttempts} attempts: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        throw new CouldNotAuthenticateException("Authentication failed after {$maxAttempts} attempts");
    }

    /**
     * Check if currently authenticated with valid token
     */
    public function isAuthenticated(): bool
    {
        // Check memory cache first
        if ($this->authCookies && $this->tokenExpiresAt && $this->tokenExpiresAt->isFuture()) {
            return true;
        }

        // Check persistent cache
        $cachedAuth = Cache::get($this->cacheKey);
        if ($cachedAuth && isset($cachedAuth['cookies'], $cachedAuth['expires_at'])) {
            $expiresAt = Carbon::parse($cachedAuth['expires_at']);
            if ($expiresAt->isFuture()) {
                $this->authCookies = $cachedAuth['cookies'];
                $this->tokenExpiresAt = $expiresAt;
                return true;
            }
        }

        return false;
    }

    /**
     * Get authentication cookies for API requests
     */
    public function getAuthCookies(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return $this->authCookies;
    }

    /**
     * Clear authentication cache and force re-authentication
     */
    public function clearAuthentication(): void
    {
        $this->authCookies = null;
        $this->tokenExpiresAt = null;
        Cache::forget($this->cacheKey);
        
        $this->logAuthenticationEvent('cleared');
    }

    /**
     * Get authentication status information
     */
    public function getAuthStatus(): array
    {
        return [
            'authenticated' => $this->isAuthenticated(),
            'expires_at' => $this->tokenExpiresAt?->toISOString(),
            'expires_in_seconds' => $this->tokenExpiresAt ? 
                max(0, $this->tokenExpiresAt->diffInSeconds(now())) : 0,
            'cache_key' => $this->cacheKey,
        ];
    }

    /**
     * Perform the actual authentication request
     */
    protected function performAuthentication(): bool
    {
        $baseUrl = rtrim($this->config['connection']['base_url'], '/');
        $loginUrl = $baseUrl . '/login';

        $response = Http::timeout($this->config['connection']['timeout'])
            ->withOptions([
                'verify' => $this->config['connection']['verify_ssl'],
                'connect_timeout' => $this->config['connection']['connect_timeout'],
            ])
            ->post($loginUrl, [
                'username' => $this->config['authentication']['username'],
                'password' => $this->config['authentication']['password'],
                'domain' => $this->config['connection']['domain'],
            ]);

        if (!$response->successful()) {
            throw new CouldNotAuthenticateException(
                "Authentication request failed with status {$response->status()}: {$response->body()}"
            );
        }

        // Extract cookies from response
        $cookies = $this->extractCookiesFromResponse($response);
        
        if (empty($cookies)) {
            throw new CouldNotAuthenticateException('No authentication cookies received from server');
        }

        // Validate authentication by checking response or making a test request
        if (!$this->validateAuthentication($cookies)) {
            throw new CouldNotAuthenticateException('Authentication validation failed');
        }

        // Cache the authentication
        $this->cacheAuthentication($cookies);

        return true;
    }

    /**
     * Extract cookies from HTTP response
     */
    protected function extractCookiesFromResponse($response): array
    {
        $cookies = [];
        $setCookieHeaders = $response->header('Set-Cookie');
        
        if (!$setCookieHeaders) {
            return $cookies;
        }

        // Handle both single cookie and array of cookies
        if (!is_array($setCookieHeaders)) {
            $setCookieHeaders = [$setCookieHeaders];
        }

        foreach ($setCookieHeaders as $cookieHeader) {
            $cookieParts = explode(';', $cookieHeader);
            $cookieNameValue = explode('=', $cookieParts[0], 2);
            
            if (count($cookieNameValue) === 2) {
                $cookies[trim($cookieNameValue[0])] = trim($cookieNameValue[1]);
            }
        }

        return $cookies;
    }

    /**
     * Validate authentication by making a test request
     */
    protected function validateAuthentication(array $cookies): bool
    {
        try {
            $baseUrl = rtrim($this->config['connection']['base_url'], '/');
            $testUrl = $baseUrl . '/api/system/status'; // Assuming this endpoint exists
            
            $cookieString = $this->buildCookieString($cookies);
            
            $response = Http::timeout($this->config['connection']['timeout'])
                ->withHeaders(['Cookie' => $cookieString])
                ->withOptions([
                    'verify' => $this->config['connection']['verify_ssl'],
                ])
                ->get($testUrl);

            // If we get a successful response or specific auth-related status, consider it valid
            return $response->successful() || $response->status() === 200;
        } catch (\Exception $e) {
            // If validation fails, we'll assume cookies are invalid
            return false;
        }
    }

    /**
     * Cache authentication cookies and expiration
     */
    protected function cacheAuthentication(array $cookies): void
    {
        $ttl = $this->config['authentication']['token_cache_ttl'] ?? 1200;
        $expiresAt = now()->addSeconds($ttl);

        $this->authCookies = $cookies;
        $this->tokenExpiresAt = $expiresAt;

        // Cache persistently
        Cache::put($this->cacheKey, [
            'cookies' => $cookies,
            'expires_at' => $expiresAt->toISOString(),
        ], $ttl);
    }

    /**
     * Build cookie string for HTTP requests
     */
    public function buildCookieString(array $cookies = null): string
    {
        $cookies = $cookies ?? $this->authCookies ?? [];
        
        $cookiePairs = [];
        foreach ($cookies as $name => $value) {
            $cookiePairs[] = "{$name}={$value}";
        }

        return implode('; ', $cookiePairs);
    }

    /**
     * Log authentication events with enhanced context and performance metrics
     */
    protected function logAuthenticationEvent(string $event, int $attempt = null, string $error = null, array $additionalContext = []): void
    {
        if (!($this->config['logging']['log_authentication'] ?? true)) {
            return;
        }

        $context = array_merge([
            'service' => 'AuthenticationService',
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'username' => $this->config['authentication']['username'] ?? 'unknown',
            'domain' => $this->config['connection']['domain'] ?? 'unknown',
            'base_url' => $this->config['connection']['base_url'] ?? 'unknown',
            'cache_key' => $this->cacheKey,
        ], $additionalContext);

        if ($attempt !== null) {
            $context['attempt'] = $attempt;
            $context['max_attempts'] = $this->config['authentication']['max_auth_attempts'] ?? 3;
        }

        if ($error !== null) {
            $context['error'] = $error;
        }

        // Add authentication status information
        $context['auth_status'] = [
            'is_authenticated' => $this->isAuthenticated(),
            'token_expires_at' => $this->tokenExpiresAt?->toISOString(),
            'cache_hit' => Cache::has($this->cacheKey),
        ];

        // Add performance metrics if enabled
        if ($this->config['logging']['log_performance_metrics'] ?? false) {
            $context['memory_usage'] = memory_get_usage(true);
            $context['peak_memory'] = memory_get_peak_usage(true);
        }

        $channel = $this->config['logging']['channel'] ?? 'default';
        
        switch ($event) {
            case 'success':
                Log::channel($channel)->info('Hillstone authentication successful', $context);
                break;
            case 'failed':
                Log::channel($channel)->warning('Hillstone authentication failed', $context);
                break;
            case 'cleared':
                Log::channel($channel)->info('Hillstone authentication cleared', $context);
                break;
            case 'cache_hit':
                Log::channel($channel)->debug('Hillstone authentication cache hit', $context);
                break;
            case 'cache_miss':
                Log::channel($channel)->debug('Hillstone authentication cache miss', $context);
                break;
            case 'token_expired':
                Log::channel($channel)->info('Hillstone authentication token expired', $context);
                break;
            case 'validation_failed':
                Log::channel($channel)->warning('Hillstone authentication validation failed', $context);
                break;
            default:
                Log::channel($channel)->debug("Hillstone authentication event: {$event}", $context);
        }
    }
}