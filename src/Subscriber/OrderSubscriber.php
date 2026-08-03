<?php

declare(strict_types=1);

namespace VertexTax\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use VertexTax\Exception\VertexApiException;
use VertexTax\Service\Api\VertexApiClient;
use VertexTax\Service\Builder\VertexTransactionBuilder;
use VertexTax\Service\Cart\CartPromotionDiscountExtractor;
use VertexTax\Service\Log\TaxLogWriter;

class OrderSubscriber implements EventSubscriberInterface
{
    public const ORDER_CREATE_REQUEST_TYPE = 'Order Create Transaction';
    public const ORDER_REFUND_REQUEST_TYPE = 'Order Refund Transaction';
    public const ORDER_CANCEL_REQUEST_TYPE = 'Order Cancel Transaction';
    public const ORDER_SHIPPED_REQUEST_TYPE = 'Order Shipped Transaction';

    private SystemConfigService $systemConfigService;
    private TaxLogWriter $taxLogWriter;
    private EntityRepository $orderRepository;
    private VertexApiClient $apiClient;
    private VertexTransactionBuilder $transactionBuilder;
    private CartPromotionDiscountExtractor $discountExtractor;
    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        TaxLogWriter $taxLogWriter,
        EntityRepository $orderRepository,
        VertexApiClient $apiClient,
        VertexTransactionBuilder $transactionBuilder,
        CartPromotionDiscountExtractor $discountExtractor,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->taxLogWriter = $taxLogWriter;
        $this->orderRepository = $orderRepository;
        $this->apiClient = $apiClient;
        $this->transactionBuilder = $transactionBuilder;
        $this->discountExtractor = $discountExtractor;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            'state_enter.order_transaction.state.paid' => 'onOrderPaid',
            'state_enter.order_transaction.state.cancelled' => 'onOrderCancelled',
            'state_enter.order_transaction.state.refunded' => 'onOrderRefunded',
            'state_enter.order_delivery.state.shipped' => 'onDeliveryShipped',
            CheckoutCartPageLoadedEvent::class => 'onStorefrontCartLoaded',
            CheckoutConfirmPageLoadedEvent::class => 'onStorefrontCartLoaded',
        ];
    }

    public function onStorefrontCartLoaded($event): void
    {
        $this->removeEmptyZeroTaxRow($event->getPage()->getCart());
    }

    private function removeEmptyZeroTaxRow(Cart $cart): void
    {
        if (!$this->cartHasVertexTax($cart)) {
            return;
        }

        $taxes = $cart->getPrice()->getCalculatedTaxes();

        $hasRealRate = false;
        foreach ($taxes as $tax) {
            if ($tax->getTaxRate() > 0.0) {
                $hasRealRate = true;
                break;
            }
        }
        if (!$hasRealRate) {
            return;
        }

        foreach ($taxes->getElements() as $tax) {
            if (\abs($tax->getTaxRate()) < 0.0001 && \abs($tax->getTax()) < 0.0001) {
                $taxes->removeElement($tax);
            }
        }
    }

    private function cartHasVertexTax(Cart $cart): bool
    {
        foreach ($cart->getLineItems() as $lineItem) {
            $rate = $lineItem->getPayloadValue('vertexTaxRate');
            if ($rate !== null && (float) $rate > 0.0) {
                return true;
            }
        }

        return false;
    }

    private function stripZeroTaxFromOrder(OrderEntity $order, Context $context): void
    {
        $payload = ['id' => $order->getId()];
        $changed = false;

        $price = $order->getPrice();
        if ($this->removeEmptyZeroTax($price->getCalculatedTaxes(), true)) {
            $payload['price'] = $price;
            $changed = true;
        }

        $deliveryPayload = [];
        foreach ($order->getDeliveries() ?? [] as $delivery) {
            $costs = $delivery->getShippingCosts();
            if ($this->removeEmptyZeroTax($costs->getCalculatedTaxes(), false)) {
                $deliveryPayload[] = ['id' => $delivery->getId(), 'shippingCosts' => $costs];
                $changed = true;
            }
        }
        if ($deliveryPayload !== []) {
            $payload['deliveries'] = $deliveryPayload;
        }

        if (!$changed) {
            return;
        }

        try {
            $this->orderRepository->update([$payload], $context);
        } catch (\Throwable $e) {
            $this->logger->error('Vertex: Failed to strip 0% tax from order', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function removeEmptyZeroTax(CalculatedTaxCollection $taxes, bool $requireRealRate): bool
    {
        if ($requireRealRate) {
            $hasRealRate = false;
            foreach ($taxes as $tax) {
                if ($tax->getTaxRate() > 0.0) {
                    $hasRealRate = true;
                    break;
                }
            }
            if (!$hasRealRate) {
                return false;
            }
        }

        $removed = false;
        foreach ($taxes->getElements() as $tax) {
            if (\abs($tax->getTaxRate()) < 0.0001 && \abs($tax->getTax()) < 0.0001) {
                $taxes->removeElement($tax);
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Handle order placed event - commit transaction to Vertex
     *
     * @param CheckoutOrderPlacedEvent $event
     * @return void
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $context = $event->getContext();

        $marked = $this->markOrderWithVertexTax($order, $context);

        if ($marked === null) {
            return;
        }

        $this->stripZeroTaxFromOrder($order, $context);

        if ($marked['status'] !== 'success') {
            return;
        }

        $commitFlow = $this->systemConfigService->get(
            'VertexTax.config.commitFlow',
            $order->getSalesChannelId()
        ) ?? 'immediate';

        if ($commitFlow === 'immediate') {
            $this->commitTransaction($order, $context);
        } else {
            $this->orderRepository->update([[
                'id' => $order->getId(),
                'customFields' => array_merge(
                    $order->getCustomFields() ?? [],
                    ['vertex_pending_commit' => true]
                ),
            ]], $context);
        }
    }

    /**
     * Handle order cancelled event - reverse transaction
     *
     * @param OrderStateMachineStateChangeEvent $event
     * @return void
     */
    public function onOrderCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $this->getOrder($event->getOrderId(), $event->getContext());

        if (!$order || !$this->hasVertexTax($order)) {
            return;
        }

        $this->reverseTransaction($order, $event->getContext());
    }

    public function onOrderPaid(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();
        $context = $event->getContext();

        $marked = $this->markOrderWithVertexTax($order, $context);

        if ($marked === null || $marked['status'] !== 'success') {
            return;
        }

        $commitFlow = $this->systemConfigService->get(
            'VertexTax.config.commitFlow',
            $order->getSalesChannelId()
        );

        if ($commitFlow !== 'paid') {
            return;
        }

        $reloaded = $this->getOrder($event->getOrderId(), $context) ?? $order;
        $custom = $reloaded->getCustomFields() ?? [];
        if (!empty($custom['vertex_pending_commit']) && empty($custom['vertex_committed'])) {
            $this->commitTransaction($reloaded, $context);
        }
    }

    public function onDeliveryShipped(OrderStateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $order = $this->getOrder($event->getOrderId(), $context);
        if (!$order) {
            return;
        }

        $marked = $this->markOrderWithVertexTax($order, $context);

        if ($marked === null || $marked['status'] !== 'success') {
            return;
        }

        $commitFlow = $this->systemConfigService->get(
            'VertexTax.config.commitFlow',
            $order->getSalesChannelId()
        );

        if ($commitFlow !== 'shipped') {
            return;
        }

        $reloaded = $this->getOrder($event->getOrderId(), $context) ?? $order;
        $custom = $reloaded->getCustomFields() ?? [];
        if (!empty($custom['vertex_pending_commit'])) {
            $this->commitTransaction($reloaded, $context);
        }
    }


    /**
     * Handle order refunded event - reverse transaction
     *
     * @param OrderStateMachineStateChangeEvent $event
     * @return void
     */
    public function onOrderRefunded(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $this->getOrder($event->getOrderId(), $event->getContext());

        if (!$order || !$this->hasVertexTax($order)) {
            return;
        }

        $this->reverseTransaction($order, $event->getContext());
    }

    /**
     * Commit transaction to Vertex
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return void
     */
    private function commitTransaction(OrderEntity $order, Context $context): void
    {
        try {
            // Reload order with required associations so line item products and addresses are available
            $loadedOrder = $this->getOrder($order->getId(), $context);
            if ($loadedOrder !== null) {
                $order = $loadedOrder;
            }

            $this->apiClient->setSalesChannelId($order->getSalesChannelId());
            $this->transactionBuilder->setSalesChannelId($order->getSalesChannelId());

            $transactionRequest = $this->buildTransactionFromOrder($order, 'INVOICE', $context);

            $response = $this->apiClient->post('supplies', $transactionRequest);

            $transactionId = $response['data']['transactionId'] ?? null;
            $documentNumber = $response['data']['documentNumber'] ?? ($transactionRequest['documentNumber'] ?? null);

            $vertexUpdates = [];
            if ($documentNumber !== null && $documentNumber !== '') {
                $vertexUpdates['vertex_sequence'] = (string) $documentNumber;
            }
            if ($transactionId) {
                $vertexUpdates['vertex_transaction_id'] = $transactionId;
                $vertexUpdates['vertex_committed'] = true;
                $vertexUpdates['vertex_pending_commit'] = false;
            }

            if ($vertexUpdates !== []) {
                $this->orderRepository->update([[
                    'id' => $order->getId(),
                    'customFields' => array_merge($order->getCustomFields() ?? [], $vertexUpdates),
                ]], $context);
            }

            $this->logTransaction($order, $transactionRequest, $response, self::ORDER_CREATE_REQUEST_TYPE, $context);
        } catch (VertexApiException $e) {
            $this->logger->error('Vertex: Failed to commit transaction', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reverse transaction in Vertex
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return void
     */
    private function reverseTransaction(OrderEntity $order, Context $context): void
    {
        try {
            $customFields = $order->getCustomFields() ?? [];
            $transactionId = $customFields['vertex_transaction_id'] ?? null;
//
            if (!$transactionId) {
                $this->logger->warning('Vertex: No transaction ID found for reversal', [
                    'order_id' => $order->getId(),
                ]);
                return;
            }

            $this->apiClient->setSalesChannelId($order->getSalesChannelId());
            $this->transactionBuilder->setSalesChannelId($order->getSalesChannelId());

            $reversalRequest = $this->buildReversalRequest($order, $transactionId);

            $response = $this->apiClient->post(
                'transactions/' . $transactionId . '/reversal',
                $reversalRequest
            );

            $this->logTransaction($order, $reversalRequest, $response, self::ORDER_REFUND_REQUEST_TYPE, $context);
        } catch (VertexApiException $e) {
            $this->logger->error('Vertex: Failed to reverse transaction', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build transaction request from order
     *
     * @param OrderEntity $order
     * @param string $messageType
     * @param Context $context
     * @return array
     */
    private function buildTransactionFromOrder(OrderEntity $order, string $messageType, Context $context): array
    {
        $salesChannelId = $order->getSalesChannelId();
        $this->transactionBuilder->setSalesChannelId($salesChannelId);

        $delivery = $order->getDeliveries()->first();
        $shippingAddress = $delivery?->getShippingOrderAddress() ?? $order->getBillingAddress();
//        $billingAddress = $order->getBillingAddress();

        if (!$shippingAddress) {
            throw new \RuntimeException('Shipping address is required for tax calculation');
        }

        $transaction = [
            'saleMessageType' => $messageType,
            'transactionType' => 'SALE',
            'documentNumber' => $order->getOrderNumber() ?? ('SW-ORDER-' . $order->getId()),
            'transactionId' => Uuid::randomHex(),
            'documentDate' => date('Y-m-d'),
            'seller' => [
                'company' => $this->systemConfigService->get('VertexTax.config.companyCode') ?? 'DEFAULT',
            ]
        ];

        $transaction['lineItems'] = [];
        $index = 1;
        $country = $shippingAddress->getCountry();
        $lineItemDiscounts = $this->discountExtractor->getDiscountsFromLineItems($order->getLineItems());
        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== "product") {
                continue;
            }

            $customFields = $lineItem->getCustomFields() ?? [];
            $taxCode = $customFields['vertex_tax_code']
                ?? $this->systemConfigService->get('VertexTax.config.defaultTaxCode', $salesChannelId)
                ?? 'DEFAULT';

            $allocatedDiscount = $lineItemDiscounts[$lineItem->getReferencedId()] ?? 0.0;
            $grossExtended     = $lineItem->getUnitPrice() * $lineItem->getQuantity();

            $transaction['lineItems'][] = [
                'lineItemId' => $lineItem->getId(),
                'lineItemNumber' => (string)$index++,
                'product' => [
                    'productClass' => $taxCode,
                ],
                'quantity' => [
                    'value' => $lineItem->getQuantity(),
                    'unitOfMeasure' => 'EA',
                ],
                'extendedPrice' => ($grossExtended - $allocatedDiscount),
                'customer' => [
                    'customerCode' => [
                        'classCode' => $order->getOrderCustomer()->getCustomer()?->getGroup()?->getName() ?? 'B2C',
                        'value' => $order->getOrderCustomer()?->getCustomer()?->getCustomerNumber() ?? 'CUST-TEST-1'
                    ],
                    'destination' => [
                        'streetAddress1' => $shippingAddress->getStreet(),
                        'city' => $shippingAddress->getCity(),
                        'postalCode' => $shippingAddress->getZipcode(),
                        'country' => $this->normalizeCountryCode($country?->getIso() ?? 'US'),
                    ]
                ]
            ];
        }

        $shippingTotal = $order->getShippingCosts()->getTotalPrice();
        if ($shippingTotal > 0) {
            $shippingTaxCode = $this->systemConfigService->get('VertexTax.config.shippingTaxCode', $salesChannelId) ?? 'FREIGHT';
            $transaction['lineItems'][] = [
                'lineItemId' => 'shipping',
                'lineItemNumber' => '999',
                'product' => [
                    'productClass' => $shippingTaxCode,
                ],
                'quantity' => [
                    'value' => 1,
                    'unitOfMeasure' => 'EA',
                ],
                'extendedPrice' => $shippingTotal,
                'customer' => [
                    'customerCode' => [
                        'classCode' => $order->getOrderCustomer()->getCustomer()?->getGroup()?->getName() ?? 'B2C',
                        'value' => $order->getOrderCustomer()?->getCustomer()?->getCustomerNumber() ?? 'CUST-TEST-1'
                    ],
                    'destination' => [
                        'streetAddress1' => $shippingAddress->getStreet(),
                        'city' => $shippingAddress->getCity(),
                        'postalCode' => $shippingAddress->getZipcode(),
                        'country' => $this->normalizeCountryCode($country?->getIso() ?? 'US'),
                    ]
                ]
            ];
        }

        return $transaction;
    }

    /**
     * Build reversal payload for Vertex /v2/transactions/{id}/reversal
     *
     * @param OrderEntity $order
     * @param string $originalTransactionId
     * @return array
     */
    private function buildReversalRequest(
        OrderEntity $order,
        string $originalTransactionId,
    ): array {
        $postingDate = $order->getCreatedAt()?->format('Y-m-d') ?? date('Y-m-d');
        $documentNumber = $order->getOrderNumber() ?? ('SW-ORDER-' . $order->getId());

        return [
            'data' => [
                'transactionId' => $originalTransactionId,
                'postingDate' => $postingDate,
                'documentNumber' => $documentNumber,
            ],
        ];
    }

    /**
     * Normalize country code to ISO 3166-1 alpha-3
     *
     * @param string $countryCode
     * @return string
     */
    private function normalizeCountryCode(string $countryCode): string
    {
        $countryMap = [
            'US' => 'USA',
            'CA' => 'CAN',
            'MX' => 'MEX',
        ];

        return $countryMap[strtoupper($countryCode)] ?? strtoupper($countryCode);
    }




    /**
     * Whether the order was successfully calculated by Vertex (not fallback). Commit/reverse only in this case.
     *
     * @param OrderEntity $order
     * @return bool
     */
    private function hasVertexTax(OrderEntity $order): bool
    {
        $customFields = $order->getCustomFields() ?? [];

        return isset($customFields['vertex_tax_calculated']) && $customFields['vertex_tax_calculated'] === true;
    }

    /**
     * Calculate and persist Vertex tax metadata on the order.
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return float|null Total Vertex tax, or null when no Vertex tax is present
     */
    /**
     * @return array{total: float, status: 'success'|'failed'}|null null when the order is not under Vertex
     */
    private function markOrderWithVertexTax(OrderEntity $order, Context $context): ?array
    {
        $result = $this->calculateVertexTaxTotals($order);

        if ($result['status'] === 'none') {
            return null;
        }

        $base = $order->getCustomFields() ?? [];
        if ($result['status'] === 'success') {
            $customFields = \array_merge($base, [
                'vertex_tax_calculated' => true,
                'vertex_total_tax' => $result['total'],
                'vertex_fallback_used' => false,
                'vertex_error' => '',
            ]);
        } else {
            $customFields = \array_merge($base, [
                'vertex_tax_calculated' => 'failed',
                'vertex_total_tax' => $result['total'],
                'vertex_fallback_used' => true,
                'vertex_error' => $result['error'] ?? 'unknown',
            ]);
        }

        $this->orderRepository->update([[
            'id' => $order->getId(),
            'customFields' => $customFields,
        ]], $context);

        return [
            'total' => $result['total'],
            'status' => $result['status'],
        ];
    }

    /**
     * @return array{status: 'none'|'success'|'failed', total: float, error: string|null}
     */
    private function calculateVertexTaxTotals(OrderEntity $order): array
    {
        $anySuccess = false;
        $anyFallback = false;
        $error = null;
        $totalTax = 0.0;

        foreach ($order->getLineItems() as $lineItem) {
            $payload = $lineItem->getPayload() ?? [];
            if (!empty($payload['vertex_fallback_used'])) {
                $anyFallback = true;
                if (isset($payload['vertex_error']) && $payload['vertex_error'] !== '') {
                    $error = (string) $payload['vertex_error'];
                }
                $lineItemTaxes = $lineItem->getPrice()->getCalculatedTaxes();
                foreach ($lineItemTaxes as $tax) {
                    $totalTax += $tax->getTax();
                }
            } elseif (isset($payload['vertex_tax_calculated']) && $payload['vertex_tax_calculated'] === true) {
                $anySuccess = true;
                $lineItemTaxes = $lineItem->getPrice()->getCalculatedTaxes();
                foreach ($lineItemTaxes as $tax) {
                    $totalTax += $tax->getTax();
                }
            }
        }

        $shippingCosts = $order->getShippingCosts();
        if ($anySuccess || $anyFallback) {
            foreach ($shippingCosts->getCalculatedTaxes() as $tax) {
                $totalTax += $tax->getTax();
            }
        } else {
            foreach ($shippingCosts->getCalculatedTaxes() as $tax) {
                if ($tax->getTax() > 0) {
                    $anySuccess = true;
                    $totalTax += $tax->getTax();
                }
            }
        }

        if (!$anySuccess && !$anyFallback) {
            return ['status' => 'none', 'total' => 0.0, 'error' => null];
        }

        if ($anyFallback) {
            return ['status' => 'failed', 'total' => $totalTax, 'error' => $error];
        }

        return ['status' => 'success', 'total' => $totalTax, 'error' => null];
    }

    /**
     * Get order entity
     *
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity|null
     */
    private function getOrder(string $orderId, Context $context): ?OrderEntity
    {
        try {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('deliveries.shippingOrderAddress');
            $criteria->addAssociation('billingAddress');

            $order = $this->orderRepository->search($criteria, $context)->get($orderId);

            return $order instanceof OrderEntity ? $order : null;
        } catch (\Exception $e) {
            $this->logger->error('Vertex: Failed to load order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log transaction request/response
     *
     * @param OrderEntity $order
     * @param array $request
     * @param array $response
     * @param string $type
     * @param Context $context
     * @return void
     */
    private function logTransaction(
        OrderEntity $order,
        array $request,
        array $response,
        string $type,
        Context $context
    ): void {
        $orderCustomer = $order->getOrderCustomer();

        $logData = [
            'requestKey' => serialize($request),
            'customerName' => $orderCustomer?->getFirstName() . ' ' . $orderCustomer?->getLastName(),
            'customerEmail' => $orderCustomer?->getEmail() ?? '',
            'remoteIp' => $orderCustomer?->getRemoteAddress() ?? '',
            'request' => json_encode($request),
            'response' => json_encode($response),
            'type' => $type,
            'orderNumber' => $order->getOrderNumber(),
            'orderId' => $order->getId(),
        ];

        $this->taxLogWriter->write($logData, $context);
    }
}
