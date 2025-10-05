<?php

namespace MrWolfGb\HillstoneFirewall\Http\Client\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

trait ManagesRateLimit
{
    protected string $rateLimitCacheKey;
    protected int $requestCount = 0;
    protected ?Carbon $rateLimitResetTime = null;

    /**
     * Initialize rate limiting
     */
    protected function initializeRateLimit(): void
    {
        $this->rateLimitCacheKey = ($this->config['cache']['prefix'] ?? 'hillstone_') . 'rate_limit';
    }

    /**
     * Check if we can make a request without hitting rate limits
     */
    protected function canMakeRequest(): bool
    {
        if (!($this->config['rate_limiting']['enabled'] ?? true)) {
            return true;
        }

        $this->loadRateLimitState();
        
        $requestsPerMinute = $this->config['rate_limiting']['requests_per_minute'] ?? 60;
        $burstLimit = $this->config['rate_limiting']['burst_limit'] ?? 10;

        // Check if we're within burst limit
        if ($this->requestCount < $burstLimit) {
            return true;
        }

        // Check if we're within the per-minute limit
        if ($this->requestCount < $requestsPerMinute) {
            return true;
        }

        // Check if the rate limit window has reset
        if ($this->rateLimitResetTime && $this->rateLimitResetTime->isPast()) {
            $this->resetRateLimit();
            return true;
        }

        return false;
    }

    /**
     * Wait if necessary to respect rate limits
     */
    protected function respectRateLimit(): void
    {
        if (!$this->canMakeRequest()) {
            $waitTime = $this->calculateWaitTime();
            
            if ($waitTime > 0) {
                $this->logRateLimit('waiting', $waitTime);
                sleep($waitTime);
                $this->resetRateLimit();
            }
        }
    }

    /**
     * Record a request for rate limiting purposes
     */
    protected function recordRequest(): void
    {
        if (!($this->config['rate_limiting']['enabled'] ?? true)) {
            return;
        }

        $this->requestCount++;
        
        // Set reset time if not already set
        if (!$this->rateLimitResetTime) {
            $this->rateLimitResetTime = now()->addMinute();
        }

        $this->saveRateLimitState();
    }

    /**
     * Handle rate limit response from server
     */
    protected function handleRateLimitResponse($response): void
    {
        if ($response->status() === 429) {
            // Extract rate limit information from headers
            $retryAfter = $response->header('Retry-After');
            $rateLimitReset = $response->header('X-RateLimit-Reset');
            $rateLimitRemaining = $response->header('X-RateLimit-Remaining');

            $waitTime = 0;
            
            if ($retryAfter) {
                $waitTime = (int) $retryAfter;
            } elseif ($rateLimitReset) {
                $resetTime = Carbon::createFromTimestamp($rateLimitReset);
                $waitTime = max(0, $resetTime->diffInSeconds(now()));
            } else {
                // Default wait time based on configuration
                $waitTime = $this->calculateBackoffTime();
            }

            $this->logRateLimit('server_limited', $waitTime, [
                'retry_after' => $retryAfter,
                'rate_limit_reset' => $rateLimitReset,
                'rate_limit_remaining' => $rateLimitRemaining,
            ]);

            if ($waitTime > 0) {
                sleep($waitTime);
            }

            // Reset our local rate limit tracking
            $this->resetRateLimit();
        }
    }

    /**
     * Calculate wait time based on current rate limit state
     */
    protected function calculateWaitTime(): int
    {
        if (!$this->rateLimitResetTime) {
            return 0;
        }

        $waitTime = max(0, $this->rateLimitResetTime->diffInSeconds(now()));
        
        // Add a small buffer to avoid edge cases
        return $waitTime + 1;
    }

    /**
     * Calculate backoff time using configured strategy
     */
    protected function calculateBackoffTime(): int
    {
        $strategy = $this->config['rate_limiting']['backoff_strategy'] ?? 'exponential';
        $baseDelay = $this->config['sync']['retry_delay'] ?? 5;
        
        switch ($strategy) {
            case 'linear':
                return $baseDelay * $this->requestCount;
                
            case 'exponential':
            default:
                $multiplier = $this->config['sync']['retry_multiplier'] ?? 2;
                $maxDelay = $this->config['sync']['max_retry_delay'] ?? 60;
                return min($baseDelay * pow($multiplier, min($this->requestCount, 5)), $maxDelay);
        }
    }

    /**
     * Reset rate limit counters
     */
    protected function resetRateLimit(): void
    {
        $this->requestCount = 0;
        $this->rateLimitResetTime = now()->addMinute();
        $this->saveRateLimitState();
    }

    /**
     * Load rate limit state from cache
     */
    protected function loadRateLimitState(): void
    {
        $state = Cache::get($this->rateLimitCacheKey);
        
        if ($state) {
            $this->requestCount = $state['request_count'] ?? 0;
            $this->rateLimitResetTime = isset($state['reset_time']) 
                ? Carbon::parse($state['reset_time']) 
                : null;
                
            // Check if the rate limit window has expired
            if ($this->rateLimitResetTime && $this->rateLimitResetTime->isPast()) {
                $this->resetRateLimit();
            }
        }
    }

    /**
     * Save rate limit state to cache
     */
    protected function saveRateLimitState(): void
    {
        $state = [
            'request_count' => $this->requestCount,
            'reset_time' => $this->rateLimitResetTime?->toISOString(),
        ];

        // Cache for slightly longer than the reset time to handle clock skew
        $ttl = $this->rateLimitResetTime 
            ? max(60, $this->rateLimitResetTime->diffInSeconds(now()) + 10)
            : 60;

        Cache::put($this->rateLimitCacheKey, $state, $ttl);
    }

    /**
     * Get current rate limit status
     */
    public function getRateLimitStatus(): array
    {
        $this->loadRateLimitState();
        
        return [
            'enabled' => $this->config['rate_limiting']['enabled'] ?? true,
            'request_count' => $this->requestCount,
            'requests_per_minute' => $this->config['rate_limiting']['requests_per_minute'] ?? 60,
            'burst_limit' => $this->config['rate_limiting']['burst_limit'] ?? 10,
            'reset_time' => $this->rateLimitResetTime?->toISOString(),
            'can_make_request' => $this->canMakeRequest(),
            'wait_time_seconds' => $this->canMakeRequest() ? 0 : $this->calculateWaitTime(),
        ];
    }

    /**
     * Log rate limiting events
     */
    protected function logRateLimit(string $event, int $waitTime = null, array $context = []): void
    {
        $channel = $this->config['logging']['channel'] ?? 'default';
        
        $logContext = array_merge([
            'event' => $event,
            'request_count' => $this->requestCount,
            'reset_time' => $this->rateLimitResetTime?->toISOString(),
        ], $context);

        if ($waitTime !== null) {
            $logContext['wait_time_seconds'] = $waitTime;
        }

        switch ($event) {
            case 'waiting':
                Log::channel($channel)->info('Rate limit reached, waiting before next request', $logContext);
                break;
            case 'server_limited':
                Log::channel($channel)->warning('Server returned rate limit response', $logContext);
                break;
            default:
                Log::channel($channel)->debug("Rate limit event: {$event}", $logContext);
        }
    }
}