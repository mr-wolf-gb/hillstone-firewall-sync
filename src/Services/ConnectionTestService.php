<?php

namespace MrWolfGb\HillstoneFirewallSync\Services;

use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Exceptions\CouldNotAuthenticateException;
use MrWolfGb\HillstoneFirewallSync\Exceptions\ApiRequestException;
use Exception;

/**
 * Connection Test Service
 * 
 * Provides methods to test connectivity and authentication with Hillstone firewall.
 */
class ConnectionTestService
{
    protected HillstoneClientInterface $client;

    public function __construct(HillstoneClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Test basic connectivity to the firewall
     *
     * @return array
     */
    public function testConnectivity(): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'details' => []
        ];

        try {
            $config = config('hillstone.connection');
            
            if (empty($config['domain']) || empty($config['base_url'])) {
                $result['message'] = 'Connection configuration is incomplete';
                $result['details'][] = 'Domain or base URL not configured';
                return $result;
            }

            // Test basic HTTP connectivity
            $url = rtrim($config['base_url'], '/');
            $timeout = $config['timeout'] ?? 30;

            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'method' => 'GET',
                    'ignore_errors' => true
                ]
            ]);

            $startTime = microtime(true);
            $response = @file_get_contents($url, false, $context);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($response !== false || !empty($http_response_header)) {
                $result['success'] = true;
                $result['message'] = 'Successfully connected to firewall';
                $result['details'][] = "Response time: {$responseTime}ms";
                $result['details'][] = "URL: {$url}";
                
                if (!empty($http_response_header)) {
                    $result['details'][] = "HTTP Status: " . $http_response_header[0];
                }
            } else {
                $result['message'] = 'Failed to connect to firewall';
                $result['details'][] = "URL: {$url}";
                $result['details'][] = "Timeout: {$timeout}s";
                $result['details'][] = 'Check if the firewall is accessible and the URL is correct';
            }

        } catch (Exception $e) {
            $result['message'] = 'Connection test failed: ' . $e->getMessage();
            $result['details'][] = 'Exception: ' . get_class($e);
        }

        return $result;
    }

    /**
     * Test authentication with the firewall
     *
     * @return array
     */
    public function testAuthentication(): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'details' => []
        ];

        try {
            $config = config('hillstone.authentication');
            
            if (empty($config['username']) || empty($config['password'])) {
                $result['message'] = 'Authentication configuration is incomplete';
                $result['details'][] = 'Username or password not configured';
                return $result;
            }

            $startTime = microtime(true);
            $authenticated = $this->client->authenticate();
            $authTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($authenticated) {
                $result['success'] = true;
                $result['message'] = 'Authentication successful';
                $result['details'][] = "Authentication time: {$authTime}ms";
                $result['details'][] = "Username: " . $config['username'];
            } else {
                $result['message'] = 'Authentication failed';
                $result['details'][] = "Authentication time: {$authTime}ms";
                $result['details'][] = 'Check username and password credentials';
            }

        } catch (CouldNotAuthenticateException $e) {
            $result['message'] = 'Authentication failed: ' . $e->getMessage();
            $result['details'][] = 'Invalid credentials or authentication method';
        } catch (ApiRequestException $e) {
            $result['message'] = 'API request failed: ' . $e->getMessage();
            $result['details'][] = 'Network or API communication error';
        } catch (Exception $e) {
            $result['message'] = 'Authentication test failed: ' . $e->getMessage();
            $result['details'][] = 'Exception: ' . get_class($e);
        }

        return $result;
    }

    /**
     * Test API functionality by retrieving address book objects
     *
     * @return array
     */
    public function testApiAccess(): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'details' => []
        ];

        try {
            // First ensure we're authenticated
            if (!$this->client->isAuthenticated()) {
                $authResult = $this->testAuthentication();
                if (!$authResult['success']) {
                    $result['message'] = 'Cannot test API access: ' . $authResult['message'];
                    return $result;
                }
            }

            $startTime = microtime(true);
            $objects = $this->client->getAllAddressBookObjects();
            $apiTime = round((microtime(true) - $startTime) * 1000, 2);

            $objectCount = $objects->count();
            
            $result['success'] = true;
            $result['message'] = 'API access successful';
            $result['details'][] = "API response time: {$apiTime}ms";
            $result['details'][] = "Retrieved {$objectCount} address book objects";

            if ($objectCount > 0) {
                $result['details'][] = 'Sample objects: ' . $objects->take(3)->pluck('name')->implode(', ');
            }

        } catch (ApiRequestException $e) {
            $result['message'] = 'API access failed: ' . $e->getMessage();
            $result['details'][] = 'API communication error';
        } catch (Exception $e) {
            $result['message'] = 'API test failed: ' . $e->getMessage();
            $result['details'][] = 'Exception: ' . get_class($e);
        }

        return $result;
    }

    /**
     * Run comprehensive connection tests
     *
     * @return array
     */
    public function runAllTests(): array
    {
        $results = [
            'overall_success' => false,
            'tests' => []
        ];

        // Test 1: Basic connectivity
        $results['tests']['connectivity'] = $this->testConnectivity();

        // Test 2: Authentication (only if connectivity passed)
        if ($results['tests']['connectivity']['success']) {
            $results['tests']['authentication'] = $this->testAuthentication();
            
            // Test 3: API access (only if authentication passed)
            if ($results['tests']['authentication']['success']) {
                $results['tests']['api_access'] = $this->testApiAccess();
            }
        }

        // Determine overall success
        $allPassed = true;
        foreach ($results['tests'] as $test) {
            if (!$test['success']) {
                $allPassed = false;
                break;
            }
        }

        $results['overall_success'] = $allPassed;

        return $results;
    }
}