<?php

declare(strict_types=1);

namespace VertexTax\Core\Vertex;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use VertexTax\Core\Tax\TaxCalculatorInterface;
use VertexTax\Exception\VertexApiException;
use VertexTax\Exception\VertexAuthenticationException;
use VertexTax\Exception\VertexServerException;
use VertexTax\Exception\VertexTimeoutException;
use VertexTax\Exception\VertexValidationException;
use VertexTax\Service\Api\VertexApiClient;
use VertexTax\Service\Builder\VertexTransactionBuilder;
use VertexTax\Service\Log\TaxLogWriter;

class Calculator implements TaxCalculatorInterface
{
    private const CACHE_ID_PREFIX = 'vertex_tax_response_';

    private SystemConfigService $systemConfigService;
    private TaxLogWriter $taxLogWriter;
    private CacheItemPoolInterface $cache;
    private VertexApiClient $apiClient;
    private VertexTransactionBuilder $transactionBuilder;
    private LoggerInterface $logger;
    private ?string $salesChannelId = null;

    public function __construct(
        SystemConfigService $systemConfigService,
        TaxLogWriter $taxLogWriter,
        CacheItemPoolInterface $cache,
        VertexApiClient $apiClient,
        VertexTransactionBuilder $transactionBuilder,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->taxLogWriter = $taxLogWriter;
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

        if ($this->isSimulateVertexFailure()) {
            return $this->buildFallbackTaxResult(
                $lineItems,
                $original,
                'simulated_failure'
            );
        }

        try {
            $transactionRequest = $this->transactionBuilder->buildFromCart($original, $context, 'QUOTATION');

            $cacheKey = $this->getCacheKey($transactionRequest);
            $cachedResponse = $this->getResponseFromCache($cacheKey);

            if ($cachedResponse !== null) {
                $fromCache = $this->processResponse($cachedResponse, $lineItems, $original);
                if (empty($fromCache)) {
                    return $this->buildFallbackTaxResult(
                        $lineItems,
                        $original,
                        'invalid_vertex_response'
                    );
                }

                return $fromCache;
            }

            $response = $this->apiClient->post('supplies', $transactionRequest);

            $this->setResponseIntoCache($cacheKey, $response);

            if ($this->isDebugMode()) {
                $this->logRequestResponse($transactionRequest, $response, $context);
            }

            $result = $this->processResponse($response, $lineItems, $original);

            if (empty($result)) {
                return $this->buildFallbackTaxResult(
                    $lineItems,
                    $original,
                    'invalid_vertex_response'
                );
            }

            return $result;
        } catch (VertexApiException $e) {
            $this->logger->error('Vertex Tax Calculation Error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return $this->buildFallbackTaxResult(
                $lineItems,
                $original,
                $this->getErrorLabelFromException($e)
            );
        } catch (\Exception $e) {
            $this->logger->error('Vertex Tax Calculation Unexpected Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->buildFallbackTaxResult(
                $lineItems,
                $original,
                'unexpected_error'
            );
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

        $transaction = $response['data'] ?? null;

        if (!$transaction) {
            $this->logger->warning('Vertex API: Invalid response structure', ['response' => $response]);
            return $processedResponse;
        }

        $lineItemTaxes = $transaction['lineItems'] ?? $transaction['LineItems'] ?? [];
        $overallTaxes = $transaction['taxes'] ?? $transaction['Taxes'] ?? [];

        foreach ($lineItemTaxes as $vertexLineItem) {
            $lineItemId = $vertexLineItem['lineItemId'] ?? $vertexLineItem['LineItemId'] ?? null;
            if (!$lineItemId || $lineItemId === 'shipping') {
                continue;
            }

            $totalTax = 0;

            $lineItemTaxList = $vertexLineItem['taxes'] ?? $vertexLineItem['Taxes'] ?? [];
            foreach ($lineItemTaxList as $tax) {
                $totalTax += (float)($tax['calculatedTax'] ?? $tax['CalculatedTax'] ?? $tax['tax'] ?? $tax['amount'] ?? 0);
            }

            $totalTax = round($totalTax, 2, PHP_ROUND_HALF_UP);

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
            $lineItemId = $vertexLineItem['lineItemId'] ?? $vertexLineItem['LineItemId'] ?? '';
            if ($lineItemId === 'shipping') {
                $lineItemTaxList = $vertexLineItem['taxes'] ?? $vertexLineItem['Taxes'] ?? [];
                foreach ($lineItemTaxList as $tax) {
                    $shippingTax += (float)($tax['calculatedTax'] ?? $tax['CalculatedTax'] ?? $tax['tax'] ?? $tax['amount'] ?? 0);
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

        $shippingTax = round($shippingTax, 2, PHP_ROUND_HALF_UP);

        if ($shippingTax > 0) {
            $processedResponse['shippingTax'] = $shippingTax;
        }

        $totalTax = array_sum(array_filter($processedResponse, function ($key) {
            return $key !== 'shippingTax' && $key !== 'rate';
        }, ARRAY_FILTER_USE_KEY));

        $totalTax += $processedResponse['shippingTax'] ?? 0;
        $totalTax = round($totalTax, 2, PHP_ROUND_HALF_UP);

        $taxableBase = (float) ($transaction['subTotal'] ?? $transaction['SubTotal'] ?? 0.0);
        if ($taxableBase <= 0.0) {
            $taxableBase = $cart->getPrice()->getTotalPrice();
        }
        if ($taxableBase > 0 && $totalTax > 0) {
            $processedResponse['rate'] = $totalTax / $taxableBase;
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

    private function isSimulateVertexFailure(): bool
    {
        return (bool)$this->systemConfigService->get('VertexTax.config.simulateVertexFailure', $this->salesChannelId);
    }

    private function getFallbackTaxRatePercent(): float
    {
        $value = $this->systemConfigService->get('VertexTax.config.fallbackTaxRate', $this->salesChannelId);
        if ($value === null || $value === '') {
            return 0.0;
        }
        $float = (float)$value;
        if ($float < 0.0) {
            return 0.0;
        }
        if ($float > 100.0) {
            return 100.0;
        }

        return $float;
    }

    /**
     * Apply shop-configured tax rate to net (gross minus current tax) per line and shipping.
     *
     * @param array $lineItems Request rows as built for Vertex (id = product line reference id)
     * @return array same shape as processResponse plus vertex_fallback, vertex_error
     */
    private function buildFallbackTaxResult(array $lineItems, Cart $original, string $errorReason): array
    {
        $percent = $this->getFallbackTaxRatePercent();
        $out = [
            'vertex_fallback' => true,
            'vertex_error' => $this->shortenErrorReason($errorReason),
        ];

        $refIds = [];
        foreach ($lineItems as $row) {
            if (!empty($row['id'])) {
                $refIds[] = (string) $row['id'];
            }
        }
        $refIds = array_unique($refIds, SORT_STRING);
        if ($refIds === []) {
            return $out;
        }

        $totalTax = 0.0;

        foreach ($original->getLineItems()->getElements() as $line) {
            if ($line->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }
            $refId = (string) $line->getReferencedId();
            if (!\in_array($refId, $refIds, true)) {
                continue;
            }

            $gross = $line->getPrice()->getTotalPrice();
            $oldTax = 0.0;
            foreach ($line->getPrice()->getCalculatedTaxes() as $t) {
                $oldTax += $t->getTax();
            }
            $net = $gross - $oldTax;
            $newTax = \round(\max(0.0, $net * ($percent / 100.0)), 2);
            $out[$refId] = $newTax;
            $totalTax += $newTax;
        }

        $shipping = $original->getShippingCosts();
        $grossS = $shipping->getTotalPrice();
        $oldS = 0.0;
        foreach ($shipping->getCalculatedTaxes() as $t) {
            $oldS += $t->getTax();
        }
        $netS = $grossS - $oldS;
        $st = \round(\max(0.0, $netS * ($percent / 100.0)), 2);
        $out['shippingTax'] = $st;
        $totalTax += $st;

        $out['display_tax_rate_percent'] = $percent;
        $out['rate'] = $percent / 100.0;

        return $out;
    }

    private function getErrorLabelFromException(VertexApiException $e): string
    {
        if ($e instanceof VertexTimeoutException) {
            return 'timeout';
        }
        if ($e instanceof VertexAuthenticationException) {
            return 'authentication';
        }
        if ($e instanceof VertexServerException) {
            return 'server_error';
        }
        if ($e instanceof VertexValidationException) {
            return 'validation';
        }

        return 'api_error';
    }

    private function shortenErrorReason(string $errorReason): string
    {
        $trimmed = \trim($errorReason);
        if ($trimmed === '') {
            return 'unknown';
        }
        if (\strlen($trimmed) > 120) {
            return \substr($trimmed, 0, 120);
        }

        return $trimmed;
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
            'orderNumber' => null,
            'orderId' => null,
        ];

        $this->taxLogWriter->write($logData, $context->getContext());
    }
}
