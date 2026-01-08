<?php

declare(strict_types=1);

namespace VertexTax\Service\Auth;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class VertexJwsSigner
{
    private SystemConfigService $systemConfigService;
    private ?string $salesChannelId = null;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function setSalesChannelId(?string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    /**
     * Generate HMAC-SHA256 signature for Vertex O Series REST API v2
     *
     * @param array $data Transaction data
     * @param string $method HTTP method
     * @param string $endpoint API endpoint path
     * @return string Base64 encoded signature
     */
    public function generateSignature(array $data, string $method, string $endpoint): string
    {
        $sharedSecret = $this->getSharedSecret();
        if (!$sharedSecret) {
            throw new \RuntimeException('Shared Secret is required for JWS signing');
        }

        $canonicalRequest = $this->createCanonicalRequest($method, $endpoint, $data);

        $signature = hash_hmac('sha256', $canonicalRequest, $sharedSecret, true);

        return base64_encode($signature);
    }

    /**
     * Vertex O Series format: METHOD + ENDPOINT + JSON_BODY
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return string Canonical request string
     */
    private function createCanonicalRequest(string $method, string $endpoint, array $data): string
    {
        $normalizedEndpoint = '/' . trim($endpoint, '/');

        $normalizedJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strtoupper($method) . "\n" . $normalizedEndpoint . "\n" . $normalizedJson;
    }

    /**
     * Get shared secret from configuration
     *
     * @return string|null
     */
    private function getSharedSecret(): ?string
    {
        return $this->systemConfigService->get('VertexTax.config.sharedSecret', $this->salesChannelId);
    }
}
