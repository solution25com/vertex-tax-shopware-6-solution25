<?php

declare(strict_types=1);

namespace VertexTax\Service\Api;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use VertexTax\Exception\VertexApiException;
use VertexTax\Exception\VertexAuthenticationException;
use VertexTax\Exception\VertexServerException;
use VertexTax\Exception\VertexTimeoutException;
use VertexTax\Exception\VertexValidationException;
use VertexTax\Service\Auth\VertexAuthService;

class VertexApiClient
{
    private const DEFAULT_TIMEOUT = 10;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [1, 2, 4];

    private Client $httpClient;
    private VertexAuthService $authService;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private ?string $salesChannelId = null;

    public function __construct(
        VertexAuthService $authService,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->httpClient = new Client();
        $this->authService = $authService;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    public function setSalesChannelId(?string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
        $this->authService->setSalesChannelId($salesChannelId);
    }

    /**
     * Make POST request to Vertex API
     *
     * @param string $endpoint
     * @param array $data
     * @param array $options
     * @return array
     * @throws VertexApiException
     */
    public function post(string $endpoint, array $data, array $options = []): array
    {
        return $this->request('POST', $endpoint, $data, $options);
    }

    /**
     * Make GET request to Vertex API
     *
     * @param string $endpoint
     * @param array $params
     * @param array $options
     * @return array
     * @throws VertexApiException
     */
    public function get(string $endpoint, array $params = [], array $options = []): array
    {
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->request('GET', $endpoint, null, $options);
    }

    /**
     * Make DELETE request to Vertex API
     *
     * @param string $endpoint
     * @param array $options
     * @return bool
     * @throws VertexApiException
     */
    public function delete(string $endpoint, array $options = []): bool
    {
        $this->request('DELETE', $endpoint, null, $options);
        return true;
    }

    /**
     * Execute HTTP request with retry logic
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param array $options
     * @return array
     * @throws VertexApiException
     */
    private function request(string $method, string $endpoint, ?array $data = null, array $options = []): array
    {
        $url = $this->getBaseUrl() . '/' . ltrim($endpoint, '/');
        $requestId = $this->generateRequestId();

        $lastException = null;
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $accessToken = $this->authService->getAccessToken();
                if (!$accessToken) {
                    throw new VertexAuthenticationException('Failed to obtain access token');
                }

                $accessToken = trim($accessToken);

                $requestOptions = array_merge([
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $accessToken,
                        'X-Request-ID' => $requestId,
                    ],
                    'timeout' => $options['timeout'] ?? self::DEFAULT_TIMEOUT,
                ], $options);

                if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    $requestOptions['json'] = $data;
                }

                $this->logRequest($method, $url, $this->sanitizeData($data), $requestId);


                $response = $this->httpClient->request($method, $url, $requestOptions);
                $responseBody = $response->getBody()->getContents();
                $responseData = json_decode($responseBody, true) ?? [];

                $this->logResponse($method, $url, $response->getStatusCode(), $responseData, $requestId);

                return $responseData;
            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;

                if ($statusCode >= 400 && $statusCode < 500 && !in_array($statusCode, [408, 429])) {
                    throw $this->createExceptionFromResponse($e, $statusCode);
                }

                if ($this->shouldRetry($statusCode)) {
                    $attempt++;
                    if ($attempt < self::MAX_RETRIES) {
                        $delay = self::RETRY_DELAYS[$attempt - 1];
                        $this->logger->warning("Vertex API: Retrying request after {$delay}s", [
                            'attempt' => $attempt,
                            'url' => $url,
                            'status' => $statusCode,
                        ]);
                        sleep($delay);
                        continue;
                    }
                }

                throw $this->createExceptionFromResponse($e, $statusCode);
            } catch (GuzzleException $e) {
                $lastException = $e;
                if ($e->getCode() === CURLE_OPERATION_TIMEOUTED || strpos($e->getMessage(), 'timeout') !== false) {
                    throw new VertexTimeoutException('Request timeout: ' . $e->getMessage(), 0, $e);
                }
                throw new VertexApiException('API request failed: ' . $e->getMessage(), 0, $e);
            } catch (InvalidArgumentException $e) {
                if ($e->getCode() === CURLE_OPERATION_TIMEOUTED) {
                    throw new VertexTimeoutException('Request timeout: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        if ($lastException) {
            throw $this->createExceptionFromResponse($lastException, 0);
        }

        throw new VertexApiException('Request failed after ' . self::MAX_RETRIES . ' attempts');
    }

    /**
     * Check if request should be retried
     *
     * @param int $statusCode
     * @return bool
     */
    private function shouldRetry(int $statusCode): bool
    {
        return in_array($statusCode, [408, 409, 429, 500, 502, 503, 504]);
    }

    /**
     * Create appropriate exception from response
     *
     * @param Exception $exception
     * @param int $statusCode
     * @return VertexApiException
     */
    private function createExceptionFromResponse(Exception $exception, int $statusCode): VertexApiException
    {
        $responseBody = '';
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $responseBody = $exception->getResponse()->getBody()->getContents();
        }

        $responseData = [];
        if ($responseBody) {
            $responseData = json_decode($responseBody, true) ?? [];
        }

        switch ($statusCode) {
            case 401:
            case 403:
                return new VertexAuthenticationException(
                    'Authentication failed: ' . ($responseData['message'] ?? $exception->getMessage()),
                    $statusCode,
                    $exception
                );
            case 400:
                return new VertexValidationException(
                    'Validation error: ' . ($responseData['message'] ?? $exception->getMessage()),
                    $statusCode,
                    $exception,
                    $responseData['errors'] ?? []
                );
            case 408:
            case 504:
                return new VertexTimeoutException(
                    'Request timeout: ' . ($responseData['message'] ?? $exception->getMessage()),
                    $statusCode,
                    $exception
                );
            case 500:
            case 502:
            case 503:
                return new VertexServerException(
                    'Server error: ' . ($responseData['message'] ?? $exception->getMessage()),
                    $statusCode,
                    $exception
                );
            default:
                return new VertexApiException(
                    'API error: ' . ($responseData['message'] ?? $exception->getMessage()),
                    $statusCode,
                    $exception
                );
        }
    }

    /**
     * Get base API URL
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        $environment = $this->systemConfigService->get('VertexTax.config.environment', $this->salesChannelId) ?? 'production';

        if ($environment === 'sandbox') {
            return 'https://calcconnect.vertexsmb.com/vertex-ws/v2';
        }

        return $this->systemConfigService->get('VertexTax.config.productionApiUrl', $this->salesChannelId)
            ?? 'https://calcconnect.vertexsmb.com/vertex-ws/v2';
    }

    /**
     * @return string
     */
    private function generateRequestId(): string
    {
        return uniqid('vertex_', true);
    }

    /**
     *
     * @param array|null $data
     * @return array|null
     */
    private function sanitizeData(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $sensitiveKeys = ['password', 'client_secret', 'access_token', 'token'];
        $sanitized = $data;

        foreach ($sensitiveKeys as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '***REDACTED***';
            }
        }

        return $sanitized;
    }

    /**
     * Log API request
     *
     * @param string $method
     * @param string $url
     * @param array|null $data
     * @param string $requestId
     * @return void
     */
    private function logRequest(string $method, string $url, ?array $data, string $requestId): void
    {
        if (!$this->isDebugMode()) {
            return;
        }

        $this->logger->debug('Vertex API Request', [
            'method' => $method,
            'url' => $url,
            'data' => $data,
            'request_id' => $requestId,
        ]);
    }

    /**
     * Log API response
     *
     * @param string $method
     * @param string $url
     * @param int $statusCode
     * @param array $data
     * @param string $requestId
     * @return void
     */
    private function logResponse(string $method, string $url, int $statusCode, array $data, string $requestId): void
    {
        if (!$this->isDebugMode()) {
            return;
        }

        $this->logger->debug('Vertex API Response', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'data' => $data,
            'request_id' => $requestId,
        ]);
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    private function isDebugMode(): bool
    {
        return (bool)$this->systemConfigService->get('VertexTax.config.debug', $this->salesChannelId);
    }

    /**
     * Test API connection
     *
     * @return array
     * @throws VertexApiException
     */
    public function testConnection(): array
    {
        try {
            $token = $this->authService->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Failed to obtain access token',
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'token_obtained' => true,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
