<?php

declare(strict_types=1);

namespace VertexTax\Service\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class VertexAuthService
{
    private const CACHE_KEY_PREFIX = 'vertex_auth_token_';
    private const TOKEN_CACHE_TTL = 3600;

    private Client $httpClient;
    private SystemConfigService $systemConfigService;
    private CacheItemPoolInterface $cache;
    private LoggerInterface $logger;
    private ?string $salesChannelId = null;

    public function __construct(
        SystemConfigService $systemConfigService,
        CacheItemPoolInterface $cache,
        LoggerInterface $logger
    ) {
        $this->httpClient = new Client();
        $this->systemConfigService = $systemConfigService;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function setSalesChannelId(?string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    /**
     * Get OAuth access token with caching
     *
     * @return string|null
     * @throws InvalidArgumentException
     */
    public function getAccessToken(): ?string
    {
        $cacheKey = $this->getCacheKey();
        $cachedToken = $this->cache->getItem($cacheKey);

        if ($cachedToken->isHit()) {
            $tokenData = $cachedToken->get();
            if (isset($tokenData['access_token']) && isset($tokenData['expires_at'])) {
                if ($tokenData['expires_at'] > (time() + 60)) {
                    return $tokenData['access_token'];
                }
            }
        }

        return $this->requestNewToken($cacheKey);
    }

    /**
     * Request a new OAuth token from Vertex
     *
     * @param string $cacheKey
     * @return string|null
     * @throws InvalidArgumentException
     */
    private function requestNewToken(string $cacheKey): ?string
    {
        try {
            $tokenEndpoint = $this->getTokenEndpoint();
            $credentials = $this->getCredentials();

            if (!$credentials) {
                $this->logger->error('Vertex Auth: Missing credentials');
                return null;
            }

            $grantType = 'client_credentials';

            $requestParams = [
            'grant_type' => $grantType,
            'audience' => 'verx://migration-api',
            ];

            $authHeader = 'Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);

            $this->logger->debug('Vertex Auth: Requesting token', [
            'endpoint' => $tokenEndpoint,
            'grant_type' => $grantType,
            ]);

            $response = $this->httpClient->post($tokenEndpoint, [
            'form_params' => $requestParams,
            'headers' => [
              'Content-Type' => 'application/x-www-form-urlencoded',
              'Accept' => 'application/json',
              'Authorization' => $authHeader,
            ],
            ]);

            $responseBody = $response->getBody()->getContents();
            $tokenData = json_decode($responseBody, true);

            if (!isset($tokenData['access_token'])) {
                  $this->logger->error('Vertex Auth: Invalid token response', [
                    'response' => $tokenData,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $responseBody,
                  ]);
                  return null;
            }

            $expiresIn = $tokenData['expires_in'] ?? self::TOKEN_CACHE_TTL;
            $expiresAt = time() + $expiresIn;

            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set([
            'access_token' => $tokenData['access_token'],
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'expires_at' => $expiresAt,
            'expires_in' => $expiresIn,
            ]);
            $cacheItem->expiresAfter($expiresIn - 60);
            $this->cache->save($cacheItem);

            $this->logger->debug('Vertex Auth: Token obtained successfully', [
            'expires_in' => $expiresIn,
            ]);

            return $tokenData['access_token'];
        } catch (GuzzleException $e) {
            $errorDetails = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            ];

            if ($e instanceof RequestException && $e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($responseBody, true);
                $errorDetails['response'] = $errorData;
                $errorDetails['status_code'] = $e->getResponse()->getStatusCode();
                $errorDetails['response_body'] = $responseBody;
            }

            $this->logger->error('Vertex Auth: Failed to obtain token', $errorDetails);
            return null;
        }
    }

    /**
     * @return string
     */
    private function getTokenEndpoint(): string
    {
        $environment = $this->systemConfigService->get('VertexTax.config.environment', $this->salesChannelId) ?? 'production';

        if ($environment === 'sandbox') {
            return 'https://tokenguard.vertexcloud.com/cached/oauth/token';
        }

        $productionBaseUrl = $this->systemConfigService->get('VertexTax.config.productionBaseUrl', $this->salesChannelId);
        if ($productionBaseUrl) {
            $baseUrl = rtrim($productionBaseUrl, '/');
            return $baseUrl . '/oauth/token';
        }

        return 'https://auth.vertexcloud.com/oauth/token';
    }

    /**
     * @return array|null
     */
    private function getCredentials(): ?array
    {
        $clientId = $this->systemConfigService->get('VertexTax.config.clientId', $this->salesChannelId);
        $clientSecret = $this->systemConfigService->get('VertexTax.config.clientSecret', $this->salesChannelId);
        $username = $this->systemConfigService->get('VertexTax.config.username', $this->salesChannelId);
        $password = $this->systemConfigService->get('VertexTax.config.password', $this->salesChannelId);

        if (!$clientId || !$clientSecret) {
            return null;
        }

        $credentials = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        if ($username) {
            $credentials['username'] = $username;
        }
        if ($password) {
            $credentials['password'] = $password;
        }

        $scope = $this->systemConfigService->get('VertexTax.config.scope', $this->salesChannelId);
        if ($scope) {
            $credentials['scope'] = $scope;
        }

        return $credentials;
    }

    /**
     * Get cache key for token storage
     *
     * @return string
     */
    private function getCacheKey(): string
    {
        $salesChannelId = $this->salesChannelId ?? 'default';
        return self::CACHE_KEY_PREFIX . md5($salesChannelId);
    }

    /**
     * Clear cached token (useful for testing or credential changes)
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function clearTokenCache(): void
    {
        $cacheKey = $this->getCacheKey();
        $this->cache->deleteItem($cacheKey);
    }
}
