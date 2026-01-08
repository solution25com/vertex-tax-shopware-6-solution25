<?php

declare(strict_types=1);

namespace VertexTax\Core\Vertex;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use VertexTax\Core\Tax\TaxCalculatorInterface;
use VertexTax\Exception\VertexApiException;
use VertexTax\Service\Api\VertexApiClient;
use VertexTax\Service\Builder\VertexTransactionBuilder;

class Calculator implements TaxCalculatorInterface
{
    private const CACHE_ID_PREFIX = 'vertex_tax_response_';

    private SystemConfigService $systemConfigService;
    private EntityRepository $taxLogRepository;
    /* @phpstan-ignore-next-line */
    private EntityRepository $productRepository;
    private CacheItemPoolInterface $cache;
    private VertexApiClient $apiClient;
    private VertexTransactionBuilder $transactionBuilder;
    private LoggerInterface $logger;
    private ?string $salesChannelId = null;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $taxLogRepository,
        EntityRepository $productRepository,
        CacheItemPoolInterface $cache,
        VertexApiClient $apiClient,
        VertexTransactionBuilder $transactionBuilder,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->taxLogRepository = $taxLogRepository;
        $this->productRepository = $productRepository;
        $this->cache = $cache;
        $this->apiClient = $apiClient;
        $this->transactionBuilder = $transactionBuilder;
        $this->logger = $logger;
    }

    public function supports(string $baseClass): bool
    {
        return static::class === $baseClass;
    }

    /**
     * Calculate tax for line items
     *
     * @param array $lineItems
     * @param SalesChannelContext $context
     * @param Cart $original
     * @return array
     */
    public function calculate(array $lineItems, SalesChannelContext $context, Cart $original): array
    {
        $this->salesChannelId = $context->getSalesChannelId();
        $this->apiClient->setSalesChannelId($this->salesChannelId);
        $this->transactionBuilder->setSalesChannelId($this->salesChannelId);

        if (!$this->isActive()) {
            return [];
        }

        if (!$context->getCustomer() || !$context->getCustomer()->getActiveShippingAddress()) {
            return [];
        }

        try {
//            $transactionRequest = $this->transactionBuilder->buildFromCart($original, $context, 'Quotation');
            $transactionRequest = $this->transactionBuilder->buildVertexDummyData();

            $cacheKey = $this->getCacheKey($transactionRequest);
            $cachedResponse = $this->getResponseFromCache($cacheKey);

            if ($cachedResponse !== null) {
                return $this->processResponse($cachedResponse, $lineItems, $original);
            }

            $response = $this->apiClient->post('supplies', $transactionRequest);

            $this->setResponseIntoCache($cacheKey, $response);

            if ($this->isDebugMode()) {
                $this->logRequestResponse($transactionRequest, $response, $context);
            }

            $result = $this->processResponse($response, $lineItems, $original);

            if (!empty($result)) {
            }

            return $result;
        } catch (VertexApiException $e) {
            $this->logger->error('Vertex Tax Calculation Error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return [];
        } catch (\Exception $e) {
            $this->logger->error('Vertex Tax Calculation Unexpected Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     *
     * @param array $response
     * @param array $lineItems
     * @param Cart $cart
     * @return array
     */
    private function processResponse(array $response, array $lineItems, Cart $cart): array
    {
        $processedResponse = [];

        $transaction = $response['data']['transaction'] ?? $response['transaction'] ?? null;

        if (!$transaction) {
            $this->logger->warning('Vertex API: Invalid response structure', ['response' => $response]);
            return $processedResponse;
        }

        $lineItemTaxes = $transaction['lineItems'] ?? [];
        $overallTaxes = $transaction['taxes'] ?? [];

        foreach ($lineItemTaxes as $vertexLineItem) {
            $lineItemId = $vertexLineItem['lineItemId'] ?? null;
            if (!$lineItemId || $lineItemId === 'shipping') {
                continue;
            }

            $totalTax = 0;

            if (isset($vertexLineItem['taxes']) && is_array($vertexLineItem['taxes'])) {
                foreach ($vertexLineItem['taxes'] as $tax) {
                    $totalTax += (float)($tax['calculatedTax'] ?? $tax['tax'] ?? $tax['amount'] ?? 0);
                }
            } elseif (isset($vertexLineItem['totalTax'])) {
                $totalTax = (float)$vertexLineItem['totalTax'];
            }

            foreach ($lineItems as $shopwareLineItem) {
                $productId = $shopwareLineItem['id'] ?? null;
                if ($productId && $productId === $lineItemId) {
                    $processedResponse[$productId] = $totalTax;
                    break;
                }
            }
        }

        $shippingTax = 0;
        foreach ($lineItemTaxes as $vertexLineItem) {
            if (($vertexLineItem['lineItemId'] ?? '') === 'shipping') {
                if (isset($vertexLineItem['taxes']) && is_array($vertexLineItem['taxes'])) {
                    foreach ($vertexLineItem['taxes'] as $tax) {
                        $shippingTax += (float)($tax['calculatedTax'] ?? $tax['tax'] ?? $tax['amount'] ?? 0);
                    }
                } elseif (isset($vertexLineItem['totalTax'])) {
                    $shippingTax = (float)$vertexLineItem['totalTax'];
                }
                break;
            }
        }

        if ($shippingTax === 0 && !empty($overallTaxes)) {
            foreach ($overallTaxes as $tax) {
                if (isset($tax['taxable']) && $tax['taxable'] === 0) {
                    $shippingTax += (float)($tax['calculatedTax'] ?? $tax['tax'] ?? $tax['amount'] ?? 0);
                }
            }
        }

        if ($shippingTax > 0) {
            $processedResponse['shippingTax'] = $shippingTax;
        }

        $totalTax = array_sum(array_filter($processedResponse, function ($key) {
            return $key !== 'shippingTax' && $key !== 'rate';
        }, ARRAY_FILTER_USE_KEY));

        $totalTax += $processedResponse['shippingTax'] ?? 0;

        $totalAmount = $cart->getPrice()->getTotalPrice();
        if ($totalAmount > 0 && $totalTax > 0) {
            $processedResponse['rate'] = $totalTax / $totalAmount;
        }

        return $processedResponse;
    }

    /**
     * Check if Vertex tax calculation is active
     *
     * @return bool
     */
    private function isActive(): bool
    {
        return (bool)$this->systemConfigService->get('VertexTax.config.active', $this->salesChannelId);
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
     * Get cache key for request
     *
     * @param array $request
     * @return string
     */
    private function getCacheKey(array $request): string
    {
        return self::CACHE_ID_PREFIX . hash('sha256', serialize($request));
    }

    /**
     * Get response from cache
     *
     * @param string $cacheKey
     * @return array|null
     * @throws InvalidArgumentException
     */
    private function getResponseFromCache(string $cacheKey): ?array
    {
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        return null;
    }

    /**
     * Store response in cache
     *
     * @param string $cacheKey
     * @param array $response
     * @return void
     * @throws InvalidArgumentException
     */
    private function setResponseIntoCache(string $cacheKey, array $response): void
    {
        $item = $this->cache->getItem($cacheKey);
        $item->set($response);
        $item->expiresAfter(3600); // Cache for 1 hour
        $this->cache->save($item);
    }

    /**
     * Log request and response
     *
     * @param array $request
     * @param array $response
     * @param SalesChannelContext $context
     * @return void
     */
    private function logRequestResponse(array $request, array $response, SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();
        if (!$customer) {
            return;
        }

        $logData = [
            'requestKey' => serialize($request),
            'customerName' => $customer->getFirstName() . ' ' . $customer->getLastName(),
            'customerEmail' => $customer->getEmail(),
            'remoteIp' => $customer->getRemoteAddress() ?? '',
            'request' => json_encode($request),
            'response' => json_encode($response),
            'type' => 'Tax Calculation',
            'orderNumber' => '',
            'orderId' => '',
        ];

        try {
            $this->taxLogRepository->create([$logData], $context->getContext());
        } catch (\Exception $e) {
            $this->logger->error('Failed to log Vertex tax request', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
