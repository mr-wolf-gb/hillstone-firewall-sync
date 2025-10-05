<?php

namespace MrWolfGb\HillstoneFirewall\Http\Client\Concerns;

use MrWolfGb\HillstoneFirewall\Exceptions\CouldNotAuthenticateException;
use MrWolfGb\HillstoneFirewall\Services\AuthenticationService;

trait HandlesAuthentication
{
    protected AuthenticationService $authService;

    /**
     * Ensure we have valid authentication before making requests
     */
    protected function ensureAuthenticated(): void
    {
        if (!$this->authService->isAuthenticated()) {
            $this->authService->authenticate();
        }
    }

    /**
     * Handle authentication-related errors in responses
     */
    protected function handleAuthenticationError($response): void
    {
        // Check for authentication failure status codes
        if (in_array($response->status(), [401, 403])) {
            // Clear current authentication
            $this->authService->clearAuthentication();
            
            // Try to re-authenticate once
            try {
                $this->authService->authenticate();
            } catch (CouldNotAuthenticateException $e) {
                throw new CouldNotAuthenticateException(
                    'Authentication failed after receiving ' . $response->status() . ' response: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Execute a request with automatic authentication handling
     */
    protected function executeWithAuth(callable $requestCallback)
    {
        // Ensure we're authenticated before the first attempt
        $this->ensureAuthenticated();

        try {
            $response = $requestCallback();
            
            // Check if the response indicates authentication failure
            if (in_array($response->status(), [401, 403])) {
                // Try to re-authenticate and retry once
                $this->authService->clearAuthentication();
                $this->authService->authenticate();
                
                // Retry the request
                $response = $requestCallback();
                
                // If it still fails, throw an exception
                if (in_array($response->status(), [401, 403])) {
                    throw new CouldNotAuthenticateException(
                        'Request failed with authentication error after re-authentication attempt'
                    );
                }
            }
            
            return $response;
        } catch (CouldNotAuthenticateException $e) {
            throw $e;
        } catch (\Exception $e) {
            // For other exceptions, check if they might be auth-related
            if ($this->isAuthenticationException($e)) {
                $this->authService->clearAuthentication();
                throw new CouldNotAuthenticateException(
                    'Request failed with possible authentication error: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            
            throw $e;
        }
    }

    /**
     * Check if an exception might be authentication-related
     */
    protected function isAuthenticationException(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        
        $authKeywords = [
            'unauthorized',
            'forbidden',
            'authentication',
            'login',
            'session',
            'cookie',
            'token',
        ];

        foreach ($authKeywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current authentication status
     */
    public function getAuthenticationStatus(): array
    {
        return $this->authService->getAuthStatus();
    }

    /**
     * Force re-authentication
     */
    public function forceReauthentication(): bool
    {
        $this->authService->clearAuthentication();
        return $this->authService->authenticate();
    }

    /**
     * Check if currently authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->authService->isAuthenticated();
    }
}