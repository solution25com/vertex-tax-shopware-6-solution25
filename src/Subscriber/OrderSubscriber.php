<?php

declare(strict_types=1);

namespace VertexTax\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use VertexTax\Exception\VertexApiException;
use VertexTax\Service\Api\VertexApiClient;
use VertexTax\Service\Builder\VertexTransactionBuilder;

class OrderSubscriber implements EventSubscriberInterface
{
    public const ORDER_CREATE_REQUEST_TYPE = 'Order Create Transaction';
    public const ORDER_REFUND_REQUEST_TYPE = 'Order Refund Transaction';
    public const ORDER_CANCEL_REQUEST_TYPE = 'Order Cancel Transaction';

    private SystemConfigService $systemConfigService;
    private EntityRepository $taxLogRepository;
    private EntityRepository $orderRepository;
    private VertexApiClient $apiClient;
    private VertexTransactionBuilder $transactionBuilder;
    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $taxLogRepository,
        EntityRepository $orderRepository,
        VertexApiClient $apiClient,
        VertexTransactionBuilder $transactionBuilder,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->taxLogRepository = $taxLogRepository;
        $this->orderRepository = $orderRepository;
        $this->apiClient = $apiClient;
        $this->transactionBuilder = $transactionBuilder;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            'state_enter.order_transaction.state.cancelled' => 'onOrderCancelled',
            'state_enter.order_transaction.state.refunded' => 'onOrderRefunded',
        ];
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

        $hasVertexTax = false;
        $totalVertexTax = 0.0;

        foreach ($order->getLineItems() as $lineItem) {
            $payload = $lineItem->getPayload();
            if (isset($payload['vertex_tax_calculated']) && $payload['vertex_tax_calculated'] === true) {
                $hasVertexTax = true;
                $lineItemTaxes = $lineItem->getPrice()->getCalculatedTaxes();
                foreach ($lineItemTaxes as $tax) {
                    $totalVertexTax += $tax->getTax();
                }
            }
        }

        $shippingCosts = $order->getShippingCosts();
        /* @phpstan-ignore-next-line */
        if ($shippingCosts) {
            $shippingTaxes = $shippingCosts->getCalculatedTaxes();
            foreach ($shippingTaxes as $tax) {
                if ($tax->getTax() > 0) {
                    $hasVertexTax = true;
                    $totalVertexTax += $tax->getTax();
                }
            }
        }

        if (!$hasVertexTax) {
            return;
        }

        $this->orderRepository->update([[
            'id' => $order->getId(),
            'customFields' => array_merge(
                $order->getCustomFields() ?? [],
                [
                    'vertex_tax_calculated' => true,
                    'vertex_total_tax' => $totalVertexTax,
                ]
            ),
        ]], $context);

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
            $this->apiClient->setSalesChannelId($order->getSalesChannelId());
            $this->transactionBuilder->setSalesChannelId($order->getSalesChannelId());

            $transactionRequest = $this->buildTransactionFromOrder($order, 'Invoice');

            $response = $this->apiClient->post('supplies', $transactionRequest);

            $transactionId = $response['data']['transaction']['transactionId'] ?? null;
            if ($transactionId) {
                $this->orderRepository->update([[
                    'id' => $order->getId(),
                    'customFields' => array_merge(
                        $order->getCustomFields() ?? [],
                        [
                            'vertex_transaction_id' => $transactionId,
                            'vertex_committed' => true,
                            'vertex_pending_commit' => false,
                        ]
                    ),
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

            if (!$transactionId) {
                $this->logger->warning('Vertex: No transaction ID found for reversal', [
                    'order_id' => $order->getId(),
                ]);
                return;
            }

            $this->apiClient->setSalesChannelId($order->getSalesChannelId());
            $this->transactionBuilder->setSalesChannelId($order->getSalesChannelId());

            $reversalRequest = $this->buildTransactionFromOrder($order, 'DistributeTax');
            $reversalRequest['transactionId'] = $transactionId . '_reversal';

            $response = $this->apiClient->post('supplies', $reversalRequest);

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
     * @return array
     */
    private function buildTransactionFromOrder(OrderEntity $order, string $messageType): array
    {
        $salesChannelId = $order->getSalesChannelId();
        $this->transactionBuilder->setSalesChannelId($salesChannelId);

        $orderCustomer = $order->getOrderCustomer();
        $delivery = $order->getDeliveries()->first();
        $shippingAddress = $delivery?->getShippingOrderAddress() ?? $order->getBillingAddress();
        $billingAddress = $order->getBillingAddress();

        if (!$shippingAddress) {
            throw new \RuntimeException('Shipping address is required for tax calculation');
        }

        $transaction = [
            'saleMessageType' => 'QUOTATION',
            'transactionId' => $this->generateOrderTransactionId($order),
            'transactionDate' => $order->getOrderDateTime()->format('Y-m-d'),
//            'currency' => $order->getCurrency()->getIsoCode(),
            'currency' => 'USD',
            'companyCode' => $this->systemConfigService->get(
                'VertexTax.config.companyCode',
                $salesChannelId
            ) ?? 'DEFAULT',
        ];

        if ($orderCustomer) {
            $transaction['customer'] = [
                'code' => $orderCustomer->getCustomerNumber() ?: $orderCustomer->getCustomerId(),
                'email' => $orderCustomer->getEmail(),
            ];

            $customFields = $orderCustomer->getCustomFields() ?? [];
            if (isset($customFields['vertex_vat_id']) && !empty($customFields['vertex_vat_id'])) {
                $transaction['customer']['taxRegistrations'] = [
                    [
                        'taxRegistrationNumber' => $customFields['vertex_vat_id'],
                        'hasPhysicalPresenceIndicator' => true,
                    ],
                ];
            }
        }

        $transaction['lineItems'] = [];
        $index = 1;
        foreach ($order->getLineItems() as $lineItem) {
            $product = $lineItem->getProduct();
            $customFields = $product?->getCustomFields() ?? [];
            $taxCode = $customFields['vertex_tax_code']
                ?? $this->systemConfigService->get('VertexTax.config.defaultTaxCode', $salesChannelId)
                ?? 'DEFAULT';

            $transaction['lineItems'][] = [
                'lineItemId' => $lineItem->getId(),
                'lineItemNumber' => (string)$index++,
                'product' => [
                    'productCode' => $product?->getProductNumber() ?? $lineItem->getProductId(),
                    'productClass' => $taxCode,
                ],
                'quantity' => [
                    'value' => $lineItem->getQuantity(),
                    'unitOfMeasure' => 'EA',
                ],
                'extendedPrice' => $lineItem->getUnitPrice() * $lineItem->getQuantity(),
            ];
        }

        if ($order->getShippingTotal() > 0) {
            $shippingTaxCode = $this->systemConfigService->get('VertexTax.config.shippingTaxCode', $salesChannelId) ?? 'FREIGHT';
            $transaction['lineItems'][] = [
                'lineItemId' => 'shipping',
                'lineItemNumber' => '999',
                'product' => [
                    'productCode' => 'SHIPPING',
                    'productClass' => $shippingTaxCode,
                ],
                'quantity' => [
                    'value' => 1,
                    'unitOfMeasure' => 'EA',
                ],
                'extendedPrice' => $order->getShippingTotal(),
            ];
        }

        $transaction['origin'] = [
            'streetAddress1' => $this->systemConfigService->get('VertexTax.config.originStreet1', $salesChannelId) ?? '',
            'streetAddress2' => $this->systemConfigService->get('VertexTax.config.originStreet2', $salesChannelId) ?? '',
            'city' => $this->systemConfigService->get('VertexTax.config.originCity', $salesChannelId) ?? '',
            'mainDivision' => $this->systemConfigService->get('VertexTax.config.originState', $salesChannelId) ?? '',
            'postalCode' => $this->systemConfigService->get('VertexTax.config.originPostalCode', $salesChannelId) ?? '',
            'country' => $this->systemConfigService->get('VertexTax.config.originCountry', $salesChannelId) ?? 'USA',
        ];

        $country = $shippingAddress->getCountry();
        $state = $shippingAddress->getCountryState();
        $transaction['destination'] = [
            'streetAddress1' => $shippingAddress->getStreet(),
            'city' => $shippingAddress->getCity(),
            'postalCode' => $shippingAddress->getZipcode() ?? '',
            'country' => $this->normalizeCountryCode($country?->getIso() ?? 'US'),
        ];

        if ($state) {
            $transaction['destination']['mainDivision'] = $this->normalizeStateCode($state->getShortCode());
        }

        return $transaction;
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
     * Normalize state code
     *
     * @param string $stateCode
     * @return string
     */
    private function normalizeStateCode(string $stateCode): string
    {
        if (strpos($stateCode, '-') !== false) {
            $parts = explode('-', $stateCode);
            return strtoupper(end($parts));
        }

        return strtoupper(trim($stateCode));
    }

    /**
     * Check if order uses Vertex tax
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

            /** @var OrderEntity $order */
            $order = $this->orderRepository->search($criteria, $context)->get($orderId);
            return $order;
        } catch (\Exception $e) {
            $this->logger->error('Vertex: Failed to load order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate transaction ID for order
     *
     * @param OrderEntity $order
     * @return string
     */
    private function generateOrderTransactionId(OrderEntity $order): string
    {
        $prefix = $this->systemConfigService->get(
            'VertexTax.config.transactionIdPrefix',
            $order->getSalesChannelId()
        ) ?? 'SW';

        return $prefix . '_' . $order->getOrderNumber();
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

        try {
            $this->taxLogRepository->create([$logData], $context);
        } catch (\Exception $e) {
            $this->logger->error('Vertex: Failed to log transaction', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
