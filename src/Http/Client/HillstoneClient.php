<?php

namespace MrWolfGb\HillstoneFirewall\Http\Client;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewall\Services\AuthenticationService;
use MrWolfGb\HillstoneFirewall\Http\Client\Concerns\BuildsRequests;
use MrWolfGb\HillstoneFirewall\Http\Client\Concerns\HandlesAuthentication;
use MrWolfGb\HillstoneFirewall\Http\Client\Concerns\ManagesRateLimit;
use MrWolfGb\HillstoneFirewall\Exceptions\ApiRequestException;
use MrWolfGb\HillstoneFirewall\Exceptions\CouldNotAuthenticateException;
use Carbon\Carbon;

class HillstoneClient implements HillstoneClientInterface
{
    use BuildsRequests, HandlesAuthentication, ManagesRateLimit;

    protected array $config;
    protected AuthenticationService $authService;

    public function __construct(array $config = null)
    {
        $this->config = $config ?? config('hillstone');
        $this->authService = new AuthenticationService($this->config);
        $this->initializeRateLimit();
    }

    /**
     * Authenticate with the Hillstone firewall API
     */
    public function authenticate(): bool
    {
        try {
            return $this->authService->authenticate();
        } catch (CouldNotAuthenticateException $e) {
            $this->logApiEvent('authentication_failed', null, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if the client is currently authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->authService->isAuthenticated();
    }

    /**
     * Retrieve all address book objects from the firewall
     */
    public function getAllAddressBookObjects(): Collection
    {
        $startTime = microtime(true);
        
        try {
            $this->logApiEvent('get_all_objects_started');
            
            $response = $this->executeWithAuth(function () {
                $this->respectRateLimit();
                
                $request = $this->buildRetryableRequest();
                $request = $this->addCommonHeaders($request);
                
                $url = $this->buildUrl('/api/address-book/objects');
                
                $this->recordRequest();
                $response = $request->get($url);
                
                $this->handleRateLimitResponse($response);
                
                return $response;
            });

            if (!$response->successful()) {
                throw new ApiRequestException(
                    "Failed to retrieve address book objects. Status: {$response->status()}, Body: {$response->body()}"
                );
            }

            $data = $response->json();
            $objects = $this->processAddressBookObjectsResponse($data);
            
            $duration = microtime(true) - $startTime;
            $this->logApiEvent('get_all_objects_completed', $duration, [
                'object_count' => $objects->count(),
                'response_size' => strlen($response->body()),
            ]);

            return $objects;

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->logApiEvent('get_all_objects_failed', $duration, [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            
            if ($e instanceof CouldNotAuthenticateException || $e instanceof ApiRequestException) {
                throw $e;
            }
            
            throw new ApiRequestException(
                'Failed to retrieve address book objects: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Retrieve a specific address book object by name
     */
    public function getSpecificAddressBookObject(string $name): array
    {
        $startTime = microtime(true);
        
        try {
            $this->logApiEvent('get_specific_object_started', null, ['object_name' => $name]);
            
            $response = $this->executeWithAuth(function () use ($name) {
                $this->respectRateLimit();
                
                $request = $this->buildRetryableRequest();
                $request = $this->addCommonHeaders($request);
                
                $url = $this->buildUrl('/api/address-book/objects/' . urlencode($name));
                
                $this->recordRequest();
                $response = $request->get($url);
                
                $this->handleRateLimitResponse($response);
                
                return $response;
            });

            if ($response->status() === 404) {
                $this->logApiEvent('get_specific_object_not_found', microtime(true) - $startTime, [
                    'object_name' => $name,
                ]);
                return [];
            }

            if (!$response->successful()) {
                throw new ApiRequestException(
                    "Failed to retrieve address book object '{$name}'. Status: {$response->status()}, Body: {$response->body()}"
                );
            }

            $data = $response->json();
            $object = $this->processAddressBookObjectResponse($data);
            
            $duration = microtime(true) - $startTime;
            $this->logApiEvent('get_specific_object_completed', $duration, [
                'object_name' => $name,
                'response_size' => strlen($response->body()),
            ]);

            return $object;

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->logApiEvent('get_specific_object_failed', $duration, [
                'object_name' => $name,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            
            if ($e instanceof CouldNotAuthenticateException || $e instanceof ApiRequestException) {
                throw $e;
            }
            
            throw new ApiRequestException(
                "Failed to retrieve address book object '{$name}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Test the connection to the Hillstone firewall
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);
        
        try {
            $this->logApiEvent('connection_test_started');
            
            // First test basic connectivity
            $request = $this->buildBaseRequest();
            $url = $this->buildUrl('/api/system/status');
            
            $response = $request->get($url);
            
            $connectionStatus = [
                'connectivity' => $response->status() !== 0,
                'response_time' => microtime(true) - $startTime,
                'status_code' => $response->status(),
            ];

            // Test authentication if connectivity is good
            if ($connectionStatus['connectivity']) {
                try {
                    $authResult = $this->authenticate();
                    $connectionStatus['authentication'] = $authResult;
                } catch (\Exception $e) {
                    $connectionStatus['authentication'] = false;
                    $connectionStatus['auth_error'] = $e->getMessage();
                }
            }

            $this->logApiEvent('connection_test_completed', microtime(true) - $startTime, $connectionStatus);
            
            return $connectionStatus;

        } catch (\Exception $e) {
            $connectionStatus = [
                'connectivity' => false,
                'authentication' => false,
                'error' => $e->getMessage(),
                'response_time' => microtime(true) - $startTime,
            ];
            
            $this->logApiEvent('connection_test_failed', microtime(true) - $startTime, $connectionStatus);
            
            return $connectionStatus;
        }
    }

    /**
     * Process the response from getAllAddressBookObjects
     */
    protected function processAddressBookObjectsResponse(array $data): Collection
    {
        // Handle different possible response structures
        if (isset($data['data']) && is_array($data['data'])) {
            $objects = $data['data'];
        } elseif (isset($data['objects']) && is_array($data['objects'])) {
            $objects = $data['objects'];
        } elseif (isset($data['results']) && is_array($data['results'])) {
            $objects = $data['results'];
        } else {
            // Assume the entire response is the objects array
            $objects = is_array($data) ? $data : [];
        }

        return collect($objects)->map(function ($object) {
            return $this->normalizeAddressBookObject($object);
        });
    }

    /**
     * Process the response from getSpecificAddressBookObject
     */
    protected function processAddressBookObjectResponse(array $data): array
    {
        // Handle different possible response structures
        if (isset($data['data']) && is_array($data['data'])) {
            $object = $data['data'];
        } elseif (isset($data['object']) && is_array($data['object'])) {
            $object = $data['object'];
        } else {
            // Assume the entire response is the object
            $object = $data;
        }

        return $this->normalizeAddressBookObject($object);
    }

    /**
     * Normalize address book object data structure
     */
    protected function normalizeAddressBookObject(array $object): array
    {
        return [
            'name' => $object['name'] ?? '',
            'member' => $object['member'] ?? $object['members'] ?? [],
            'is_ipv6' => $object['is_ipv6'] ?? $object['ipv6'] ?? false,
            'predefined' => $object['predefined'] ?? $object['is_predefined'] ?? false,
            'description' => $object['description'] ?? '',
            'type' => $object['type'] ?? 'address',
            'last_modified' => isset($object['last_modified']) 
                ? Carbon::parse($object['last_modified']) 
                : null,
            'raw_data' => $object, // Keep original data for debugging
        ];
    }

    /**
     * Get client statistics and status
     */
    public function getClientStatus(): array
    {
        return [
            'authentication' => $this->getAuthenticationStatus(),
            'rate_limiting' => $this->getRateLimitStatus(),
            'configuration' => [
                'base_url' => $this->config['connection']['base_url'] ?? null,
                'domain' => $this->config['connection']['domain'] ?? null,
                'timeout' => $this->config['connection']['timeout'] ?? 30,
                'verify_ssl' => $this->config['connection']['verify_ssl'] ?? true,
            ],
        ];
    }

    /**
     * Log API events for monitoring and debugging with enhanced context
     */
    protected function logApiEvent(string $event, float $duration = null, array $context = []): void
    {
        if (!($this->config['logging']['log_sync_operations'] ?? true)) {
            return;
        }

        $channel = $this->config['logging']['channel'] ?? 'default';
        
        $logContext = array_merge([
            'service' => 'HillstoneClient',
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'base_url' => $this->config['connection']['base_url'] ?? 'unknown',
            'domain' => $this->config['connection']['domain'] ?? 'unknown',
            'timeout' => $this->config['connection']['timeout'] ?? 30,
            'verify_ssl' => $this->config['connection']['verify_ssl'] ?? true,
        ], $context);

        if ($duration !== null) {
            $logContext['duration_seconds'] = round($duration, 3);
            
            // Add performance categorization
            if ($duration > 10) {
                $logContext['performance_category'] = 'slow';
            } elseif ($duration > 5) {
                $logContext['performance_category'] = 'moderate';
            } else {
                $logContext['performance_category'] = 'fast';
            }
        }

        // Add authentication status
        $logContext['authentication_status'] = [
            'is_authenticated' => $this->isAuthenticated(),
            'auth_service_status' => $this->authService->getAuthStatus(),
        ];

        // Add rate limiting status if available
        if (method_exists($this, 'getRateLimitStatus')) {
            $logContext['rate_limit_status'] = $this->getRateLimitStatus();
        }

        // Log performance metrics if enabled
        if ($this->config['logging']['log_performance_metrics'] ?? false) {
            $logContext['memory_usage'] = memory_get_usage(true);
            $logContext['peak_memory'] = memory_get_peak_usage(true);
            
            // Add throughput calculations for completed operations
            if (isset($context['object_count']) && $duration > 0) {
                $logContext['throughput_objects_per_second'] = round($context['object_count'] / $duration, 2);
            }
            
            if (isset($context['response_size']) && $duration > 0) {
                $logContext['throughput_bytes_per_second'] = round($context['response_size'] / $duration, 0);
            }
        }

        // Add error context for failed operations
        if (strpos($event, '_failed') !== false && isset($context['error'])) {
            $logContext['error_context'] = [
                'error_message' => $context['error'],
                'error_class' => $context['exception_class'] ?? 'unknown',
            ];
            
            // Add stack trace for debugging if available
            if (isset($context['stack_trace'])) {
                $logContext['stack_trace'] = $context['stack_trace'];
            }
        }

        switch ($event) {
            case 'get_all_objects_completed':
            case 'get_specific_object_completed':
            case 'connection_test_completed':
                Log::channel($channel)->info("Hillstone API: {$event}", $logContext);
                break;
                
            case 'get_all_objects_failed':
            case 'get_specific_object_failed':
            case 'connection_test_failed':
            case 'authentication_failed':
                Log::channel($channel)->error("Hillstone API: {$event}", $logContext);
                break;
                
            case 'get_all_objects_started':
            case 'get_specific_object_started':
            case 'connection_test_started':
                Log::channel($channel)->info("Hillstone API: {$event}", $logContext);
                break;
                
            default:
                Log::channel($channel)->debug("Hillstone API: {$event}", $logContext);
        }
    }
}