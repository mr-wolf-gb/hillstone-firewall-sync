<?php

namespace MrWolfGb\HillstoneFirewallSync\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait LogsHillstoneEvents
{
    /**
     * Log service events with structured context and performance metrics.
     * 
     * @param string $event
     * @param array $context
     * @param string $level
     * @param string|null $serviceName
     */
    protected function logHillstoneEvent(string $event, array $context = [], string $level = 'info', ?string $serviceName = null): void
    {
        $config = $this->getLoggingConfig();
        
        if (!($config['log_sync_operations'] ?? true)) {
            return;
        }

        $serviceName = $serviceName ?? $this->getServiceName();
        $channel = $config['channel'] ?? 'default';
        
        $logContext = $this->buildLogContext($event, $context, $serviceName, $config);
        
        // Filter sensitive data if configured
        if ($config['exclude_sensitive_data'] ?? true) {
            $logContext = $this->filterSensitiveData($logContext);
        }
        
        // Truncate large payloads if configured
        if ($config['truncate_long_messages'] ?? true) {
            $logContext = $this->truncateLargePayloads($logContext, $config['max_log_payload_size'] ?? 10240);
        }

        $this->writeLog($channel, $level, $serviceName, $event, $logContext);
    }

    /**
     * Log performance metrics for operations.
     * 
     * @param string $operation
     * @param float $startTime
     * @param int $startMemory
     * @param array $additionalMetrics
     */
    protected function logPerformanceMetrics(string $operation, float $startTime, int $startMemory, array $additionalMetrics = []): void
    {
        $config = $this->getLoggingConfig();
        
        if (!($config['log_performance_metrics'] ?? false)) {
            return;
        }

        $duration = microtime(true) - $startTime;
        $endMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        $metrics = array_merge([
            'operation' => $operation,
            'duration_seconds' => round($duration, 4),
            'memory_usage_start' => $startMemory,
            'memory_usage_end' => $endMemory,
            'memory_usage_peak' => $peakMemory,
            'memory_usage_delta' => $endMemory - $startMemory,
            'memory_efficiency' => $startMemory > 0 ? round(($endMemory - $startMemory) / $startMemory * 100, 2) : 0,
        ], $additionalMetrics);

        // Add performance categorization
        if ($duration > 30) {
            $metrics['performance_category'] = 'very_slow';
        } elseif ($duration > 10) {
            $metrics['performance_category'] = 'slow';
        } elseif ($duration > 5) {
            $metrics['performance_category'] = 'moderate';
        } else {
            $metrics['performance_category'] = 'fast';
        }

        $this->logHillstoneEvent('performance_metrics', $metrics, 'info');
    }

    /**
     * Log error events with enhanced context and stack traces.
     * 
     * @param string $operation
     * @param \Throwable $exception
     * @param array $additionalContext
     */
    protected function logError(string $operation, \Throwable $exception, array $additionalContext = []): void
    {
        $config = $this->getLoggingConfig();
        
        $errorContext = array_merge([
            'operation' => $operation,
            'error_message' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'error_code' => $exception->getCode(),
        ], $additionalContext);

        // Add stack trace if configured
        if ($config['log_stack_traces'] ?? true) {
            $errorContext['stack_trace'] = $exception->getTraceAsString();
        }

        // Add error context if configured
        if ($config['log_error_context'] ?? true) {
            $errorContext['error_context'] = [
                'previous_exception' => $exception->getPrevious() ? [
                    'message' => $exception->getPrevious()->getMessage(),
                    'class' => get_class($exception->getPrevious()),
                    'file' => $exception->getPrevious()->getFile(),
                    'line' => $exception->getPrevious()->getLine(),
                ] : null,
                'trace_count' => count($exception->getTrace()),
            ];
        }

        $this->logHillstoneEvent('error_occurred', $errorContext, 'error');
    }

    /**
     * Build the complete log context.
     * 
     * @param string $event
     * @param array $context
     * @param string $serviceName
     * @param array $config
     * @return array
     */
    private function buildLogContext(string $event, array $context, string $serviceName, array $config): array
    {
        $logContext = array_merge([
            'service' => $serviceName,
            'event' => $event,
            'timestamp' => now()->toISOString(),
        ], $context);

        // Add correlation ID if configured
        if ($config['log_correlation_id'] ?? false) {
            $logContext['correlation_id'] = $this->getCorrelationId();
        }

        // Add system information if configured
        if ($config['include_system_info'] ?? false) {
            $logContext['system_info'] = [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ];
        }

        // Add performance metrics if enabled
        if ($config['log_performance_metrics'] ?? false) {
            $logContext['runtime_metrics'] = [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'memory_usage_formatted' => $this->formatBytes(memory_get_usage(true)),
                'peak_memory_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
            ];
        }

        return $logContext;
    }

    /**
     * Get the logging configuration.
     * 
     * @return array
     */
    private function getLoggingConfig(): array
    {
        if (isset($this->config['logging'])) {
            return $this->config['logging'];
        }

        return config('hillstone.logging', []);
    }

    /**
     * Get the service name for logging.
     * 
     * @return string
     */
    private function getServiceName(): string
    {
        $className = class_basename(static::class);
        return Str::snake($className);
    }

    /**
     * Filter sensitive data from log context.
     * 
     * @param array $context
     * @return array
     */
    private function filterSensitiveData(array $context): array
    {
        $sensitiveKeys = [
            'password', 'token', 'secret', 'key', 'auth', 'credential',
            'cookie', 'session', 'authorization', 'x-api-key'
        ];

        return $this->recursiveFilter($context, $sensitiveKeys);
    }

    /**
     * Recursively filter sensitive data.
     * 
     * @param array $data
     * @param array $sensitiveKeys
     * @return array
     */
    private function recursiveFilter(array $data, array $sensitiveKeys): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->recursiveFilter($value, $sensitiveKeys);
            } elseif (is_string($key)) {
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (stripos($key, $sensitiveKey) !== false) {
                        $data[$key] = '[REDACTED]';
                        break;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Truncate large payloads to prevent log bloat.
     * 
     * @param array $context
     * @param int $maxSize
     * @return array
     */
    private function truncateLargePayloads(array $context, int $maxSize): array
    {
        $serialized = json_encode($context);
        
        if (strlen($serialized) > $maxSize) {
            // Try to truncate specific large fields first
            $largeFields = ['stack_trace', 'response_body', 'request_body', 'raw_data'];
            
            foreach ($largeFields as $field) {
                if (isset($context[$field]) && is_string($context[$field])) {
                    $context[$field] = substr($context[$field], 0, 1000) . '... [TRUNCATED]';
                }
            }
            
            // If still too large, add truncation notice
            $serialized = json_encode($context);
            if (strlen($serialized) > $maxSize) {
                $context['_truncated'] = true;
                $context['_original_size'] = strlen($serialized);
            }
        }

        return $context;
    }

    /**
     * Write the log entry.
     * 
     * @param string $channel
     * @param string $level
     * @param string $serviceName
     * @param string $event
     * @param array $context
     */
    private function writeLog(string $channel, string $level, string $serviceName, string $event, array $context): void
    {
        $message = "{$serviceName}: {$event}";

        switch ($level) {
            case 'debug':
                Log::channel($channel)->debug($message, $context);
                break;
            case 'info':
                Log::channel($channel)->info($message, $context);
                break;
            case 'warning':
                Log::channel($channel)->warning($message, $context);
                break;
            case 'error':
                Log::channel($channel)->error($message, $context);
                break;
            case 'critical':
                Log::channel($channel)->critical($message, $context);
                break;
            default:
                Log::channel($channel)->info($message, $context);
        }
    }

    /**
     * Get or generate a correlation ID for request tracking.
     * 
     * @return string
     */
    private function getCorrelationId(): string
    {
        // Try to get from request headers first
        if (function_exists('request') && request()->hasHeader('X-Correlation-ID')) {
            return request()->header('X-Correlation-ID');
        }

        // Generate a new one if not available
        return Str::uuid()->toString();
    }

    /**
     * Format bytes into human readable format.
     * 
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}