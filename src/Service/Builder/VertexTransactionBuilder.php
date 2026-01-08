<?php

declare(strict_types=1);

namespace VertexTax\Service\Builder;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class VertexTransactionBuilder
{
    private SystemConfigService $systemConfigService;
    private EntityRepository $productRepository;
    private ?string $salesChannelId = null;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $productRepository
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->productRepository = $productRepository;
    }

    public function setSalesChannelId(?string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    /**
     * Build hardcoded dummy data for testing Vertex API
     * This is a temporary method to test the API connection
     * Hardcoded values to get ANY tax response working
     * Using PascalCase field names as per Vertex REST API v2 documentation
     *
     * @return array
     */
    public function buildVertexDummyData(): array
    {
        $companyCode = $this->getCompanyCode();
        if (!$companyCode) {
            $companyCode = 'DEFAULT';
        }

        return [
            'SaleMessageType' => 'Quotation',
            'TransactionId' => 'SW_TEST_' . time(),
            'TransactionDate' => date('Y-m-d'),
            'Currency' => 'USD',
            'CompanyCode' => $companyCode,
            'Seller' => [
                'PhysicalOrigin' => [
                    'StreetAddress1' => '123 Main St',
                    'City' => 'San Francisco',
                    'MainDivision' => 'CA',
                    'PostalCode' => '94105',
                    'Country' => 'USA'
                ]
            ],
            'Customer' => [
                'Destination' => [
                    'StreetAddress1' => '456 Oak Ave',
                    'City' => 'Seattle',
                    'MainDivision' => 'WA',
                    'PostalCode' => '98101',
                    'Country' => 'USA'
                ]
            ],
            'LineItems' => [
                [
                    'LineItemNumber' => '1',
                    'Product' => [
                        'ProductClass' => 'DEFAULT'
                    ],
                    'Quantity' => [
                        'Value' => 1,
                        'UnitOfMeasure' => 'EA'
                    ],
                    'ExtendedPrice' => 100.00
                ]
            ]
        ];
    }

    /**
     * Build Vertex transaction request from Shopware cart
     *
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @param string $messageType
     * @return array
     */
    public function buildFromCart(Cart $cart, SalesChannelContext $context, string $messageType = 'Quotation'): array
    {
        $this->salesChannelId = $context->getSalesChannelId();

        $customer = $context->getCustomer();
        $shippingAddress = $this->resolveShippingAddress($cart, $context, $customer);
        $billingAddress = $customer?->getActiveBillingAddress() ?? $customer?->getDefaultBillingAddress();

        if (!$shippingAddress) {
            throw new \RuntimeException('Shipping address is required for tax calculation');
        }

        $transaction = [
            'saleMessageType' => $messageType,
            'transactionId' => $this->generateTransactionId($cart, $context),
            'transactionDate' => (new \DateTime())->format('Y-m-d'),
            'currency' => $context->getCurrency()->getIsoCode(),
            'companyCode' => $this->getCompanyCode(),
        ];

        if ($customer) {
            $transaction['customer'] = $this->buildCustomerData($customer, $billingAddress);
        }

        $transaction['lineItems'] = $this->buildLineItems($cart, $context);

        $transaction['origin'] = $this->buildOriginAddress();

        $transaction['destination'] = $this->buildDestinationAddress($shippingAddress);

        if ($cart->getShippingCosts()->getTotalPrice() > 0) {
            $transaction['lineItems'][] = $this->buildShippingLineItem($cart);
        }

        return $transaction;
    }

    /**
     * Build customer data
     *
     * @param \Shopware\Core\Checkout\Customer\CustomerEntity $customer
     * @param \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity|null $billingAddress
     * @return array
     */
    private function buildCustomerData($customer, $billingAddress): array
    {
        $customerData = [
            'code' => $customer->getCustomerNumber() ?: $customer->getId(),
            'email' => $customer->getEmail(),
        ];

        $customFields = $customer->getCustomFields() ?? [];
        if (isset($customFields['vertex_vat_id']) && !empty($customFields['vertex_vat_id'])) {
            $customerData['taxRegistrations'] = [
                [
                    'taxRegistrationNumber' => $customFields['vertex_vat_id'],
                    'hasPhysicalPresenceIndicator' => true,
                ],
            ];
        }

        if (isset($customFields['vertex_tax_exempt']) && $customFields['vertex_tax_exempt']) {
            $customerData['entityUseCode'] = $customFields['vertex_entity_use_code'] ?? 'A';
            if (isset($customFields['vertex_exemption_certificate'])) {
                $customerData['exemptionCertificate'] = $customFields['vertex_exemption_certificate'];
            }
        }

        return $customerData;
    }

    /**
     * Build line items from cart
     *
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @return array
     */
    private function buildLineItems(Cart $cart, SalesChannelContext $context): array
    {
        $lineItems = [];
        $index = 1;

        foreach ($cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $product = $this->getProduct($lineItem->getReferencedId(), $context);

            $lineItemData = [
                'lineItemId' => $lineItem->getId(),
                'lineItemNumber' => (string)$index++,
                'product' => [
                    'productCode' => $product?->getProductNumber() ?? $lineItem->getReferencedId(),
                    'productClass' => $this->getProductTaxCode($product, $lineItem),
                ],
                'quantity' => [
                    'value' => $lineItem->getQuantity(),
                    'unitOfMeasure' => 'EA',
                ],
                'extendedPrice' => $lineItem->getPrice()->getTotalPrice(),
            ];

            if ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() < 0) {
                $lineItemData['discount'] = [
                    'discountValue' => abs($lineItem->getPrice()->getCalculatedTaxes()->getAmount()),
                ];
            }

            $lineItems[] = $lineItemData;
        }

        return $lineItems;
    }

    /**
     * Build shipping line item
     *
     * @param Cart $cart
     * @return array
     */
    private function buildShippingLineItem(Cart $cart): array
    {
        $shippingCosts = $cart->getShippingCosts();
        $shippingTaxCode = $this->systemConfigService->get('VertexTax.config.shippingTaxCode', $this->salesChannelId) ?? 'FREIGHT';

        return [
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
            'extendedPrice' => $shippingCosts->getTotalPrice(),
        ];
    }

    /**
     * Build origin address (ship-from)
     *
     * @return array
     */
    private function buildOriginAddress(): array
    {
        return [
            'streetAddress1' => $this->systemConfigService->get('VertexTax.config.originStreet1', $this->salesChannelId) ?? '',
            'streetAddress2' => $this->systemConfigService->get('VertexTax.config.originStreet2', $this->salesChannelId) ?? '',
            'city' => $this->systemConfigService->get('VertexTax.config.originCity', $this->salesChannelId) ?? '',
            'mainDivision' => $this->systemConfigService->get('VertexTax.config.originState', $this->salesChannelId) ?? '',
            'postalCode' => $this->systemConfigService->get('VertexTax.config.originPostalCode', $this->salesChannelId) ?? '',
            'country' => $this->systemConfigService->get('VertexTax.config.originCountry', $this->salesChannelId) ?? 'USA',
        ];
    }

    /**
     * Build destination address (ship-to)
     *
     * @param \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity|\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity $address
     * @return array
     */
    private function buildDestinationAddress($address): array
    {
        $country = $address->getCountry();
        $state = $address->getCountryState();

        $destination = [
            'streetAddress1' => $address->getStreet(),
            'city' => $address->getCity(),
            'postalCode' => $address->getZipcode() ?? '',
            'country' => $this->normalizeCountryCode($country?->getIso() ?? 'US'),
        ];

        if ($state) {
            $destination['mainDivision'] = $this->normalizeStateCode($state->getShortCode());
        } elseif ($destination['country'] === 'USA') {
            $destination['mainDivision'] = $this->extractStateFromAddress($address);
        }

        return $destination;
    }

    /**
     * Get product tax code
     *
     * @param ProductEntity|null $product
     * @param LineItem $lineItem
     * @return string
     */
    private function getProductTaxCode(?ProductEntity $product, LineItem $lineItem): string
    {
        if ($product) {
            $customFields = $product->getCustomFields() ?? [];
            if (isset($customFields['vertex_tax_code']) && !empty($customFields['vertex_tax_code'])) {
                return $customFields['vertex_tax_code'];
            }

            if ($product->getParentId()) {
                $parentProduct = $this->getProduct($product->getParentId(), null);
                if ($parentProduct) {
                    $parentCustomFields = $parentProduct->getCustomFields() ?? [];
                    if (isset($parentCustomFields['vertex_tax_code']) && !empty($parentCustomFields['vertex_tax_code'])) {
                        return $parentCustomFields['vertex_tax_code'];
                    }
                }
            }
        }

        return $this->systemConfigService->get('VertexTax.config.defaultTaxCode', $this->salesChannelId) ?? 'DEFAULT';
    }

    /**
     * Get product entity
     *
     * @param string $productId
     * @param SalesChannelContext|null $context
     * @return ProductEntity|null
     */
    private function getProduct(string $productId, ?SalesChannelContext $context): ?ProductEntity
    {
        if (!$context) {
            return null;
        }

        try {
            /** @var ProductEntity|null $product */
            $product = $this->productRepository
                ->search(new Criteria([$productId]), $context->getContext())
                ->get($productId);
            return $product;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate transaction ID
     *
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @return string
     */
    private function generateTransactionId(Cart $cart, SalesChannelContext $context): string
    {
        $prefix = $this->systemConfigService->get('VertexTax.config.transactionIdPrefix', $context->getSalesChannelId()) ?? 'SW';
        return $prefix . '_' . $cart->getToken();
    }

    /**
     * Get company code from configuration
     *
     * @return string
     */
    private function getCompanyCode(): string
    {
        return $this->systemConfigService->get('VertexTax.config.companyCode', $this->salesChannelId) ?? 'DEFAULT';
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
            return end($parts);
        }

        return strtoupper($stateCode);
    }

    /**
     * Extract state from address if not available
     *
     * @param mixed $address
     * @return string
     */
    private function extractStateFromAddress($address): string
    {
        return '';
    }

    /**
     * Try to resolve the shipping address for the current cart context.
     *
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @param CustomerEntity|null $customer
     * @return CustomerAddressEntity|null
     */
    private function resolveShippingAddress(
        Cart $cart,
        SalesChannelContext $context,
        ?CustomerEntity $customer
    ): ?CustomerAddressEntity {
        $shippingLocation = $context->getShippingLocation();
        /* @phpstan-ignore-next-line */
        if ($shippingLocation && $shippingLocation->getAddress()) {
            return $shippingLocation->getAddress();
        }

        if ($customer) {
            if ($customer->getActiveShippingAddress()) {
                return $customer->getActiveShippingAddress();
            }

            if ($customer->getDefaultShippingAddress()) {
                return $customer->getDefaultShippingAddress();
            }
        }

        $deliveries = $cart->getDeliveries();
        /* @phpstan-ignore-next-line */
        if ($deliveries && $deliveries->count() > 0) {
            $deliveryAddress = $deliveries->getAddresses()->first();
            if ($deliveryAddress instanceof CustomerAddressEntity) {
                return $deliveryAddress;
            }
        }

        return null;
    }
}
