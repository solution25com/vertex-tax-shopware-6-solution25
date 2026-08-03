<?php

declare(strict_types=1);

namespace VertexTax\Core\Checkout\Cart\Collector;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use VertexTax\Core\Content\Extension\TaxExtensionEntity;
use VertexTax\Core\Content\TaxProvider\TaxProviderEntity;
use VertexTax\Core\Tax\TaxCalculatorRegistry;

class VertexTaxCollector implements CartProcessorInterface
{
    private EntityRepository $taxRepository;
    private EntityRepository $taxProviderRepository;
    private TaxCalculatorRegistry $taxCalculatorRegistry;

    public function __construct(
        EntityRepository $taxRepository,
        EntityRepository $taxProviderRepository,
        TaxCalculatorRegistry $taxCalculatorRegistry
    ) {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->taxCalculatorRegistry = $taxCalculatorRegistry;
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $products = $toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $taxProviderMapping = [];

        $taxIds = [];
        foreach ($products as $product) {
            $taxId = $product->getPayloadValue('taxId');
            if ($taxId) {
                $taxIds[] = $taxId;
                $taxProviderMapping[$taxId][] = [
                    'id' => $product->getReferencedId(),
                    'quantity' => $product->getPrice()->getQuantity(),
                    'unit_price' => $product->getPrice()->getUnitPrice(),
                    'discount' => 0,
                ];
            }
        }

        if (empty($taxIds)) {
            return;
        }

        $taxCriteria = new Criteria(array_unique($taxIds));
        $taxCriteria->addAssociation('extensions.taxExtension.taxProvider');
        $taxRules = $this->taxRepository->search($taxCriteria, $context->getContext())->getElements();

        $providerIds = [];
        foreach ($taxRules as $taxRule) {
            $extension = $taxRule->getExtension('taxExtension');
            if ($extension instanceof TaxExtensionEntity && $extension->getProviderId()) {
                $providerIds[] = $extension->getProviderId();
            }
        }

        $taxProviders = [];
        if (!empty($providerIds)) {
            $providerCriteria = new Criteria(array_unique($providerIds));
            $taxProviders = $this->taxProviderRepository->search($providerCriteria, $context->getContext())->getElements();
        }

        foreach ($taxProviderMapping as $taxId => $requestDetails) {
            $taxProviderClass = $this->getTaxProviderClass($taxId, $taxRules, $taxProviders);
            if ($taxProviderClass) {
                $lineItems = $requestDetails;
                $lineItemsTax = $taxProviderClass->calculate($lineItems, $context, $original);
                $this->addRateToCart($lineItemsTax, $toCalculate);

                if (!empty($lineItemsTax)) {
                    $isVertexFallback = !empty($lineItemsTax['vertex_fallback']);

                    $displayRate = null;
                    if ($isVertexFallback) {
                        $displayRate = (float) number_format((float) ($lineItemsTax['display_tax_rate_percent'] ?? 0.0), 2, '.', '');
                    } elseif (isset($lineItemsTax['rate'])) {
                        $displayRate = (float) number_format((float) $lineItemsTax['rate'] * 100, 2, '.', '');
                    }

                    $shippingTaxToFold = \array_key_exists('shippingTax', $lineItemsTax)
                        ? (float) $lineItemsTax['shippingTax']
                        : 0.0;

                    foreach ($products as $product) {
                        $productId = $product->getReferencedId();
                        if (!\is_string($productId)) {
                            continue;
                        }
                        if (!\array_key_exists($productId, $lineItemsTax)) {
                            continue;
                        }

                        if ($isVertexFallback) {
                            $product->setPayloadValue('vertex_tax_calculated', false);
                            $product->setPayloadValue('vertex_fallback_used', true);
                            $product->setPayloadValue('vertex_error', (string) ($lineItemsTax['vertex_error'] ?? ''));
                        } else {
                            $product->setPayloadValue('vertex_tax_calculated', true);
                            $product->setPayloadValue('vertex_fallback_used', false);
                            $product->setPayloadValue('vertex_error', '');
                        }

                        $productTax = (float) $lineItemsTax[$productId];
                        if ($shippingTaxToFold !== 0.0) {
                            $productTax += $shippingTaxToFold;
                            $shippingTaxToFold = 0.0;
                        }

                        $rate = $displayRate ?? ($product->getPrice()->getCalculatedTaxes()->first()?->getTaxRate() ?? 0.0);
                        $this->applyBlendedTax($product->getPrice(), $productTax, $rate);
                    }
                }
            }
        }
    }

    private function applyBlendedTax(CalculatedPrice $price, float $tax, float $rate): void
    {
        $taxes = $price->getCalculatedTaxes();
        $taxes->clear();
        $taxes->add(new CalculatedTax($tax, $rate, $price->getTotalPrice()));
    }

    private function getTaxProviderClass(string $taxRuleId, array $taxRules, array $taxProviders)
    {
        if (!$taxRuleId || !isset($taxRules[$taxRuleId])) {
            return false;
        }

        $taxRule = $taxRules[$taxRuleId];
        $extension = $taxRule->getExtension('taxExtension');

        if (!$extension instanceof TaxExtensionEntity || !$extension->getProviderId()) {
            return false;
        }

        $providerId = $extension->getProviderId();

        if (!isset($taxProviders[$providerId])) {
            return false;
        }

        $taxProvider = $taxProviders[$providerId];

        if (!$taxProvider instanceof TaxProviderEntity || !$taxProvider->getBaseClass()) {
            return false;
        }

        return $this->taxCalculatorRegistry->getCalculatorFor($taxProvider->getBaseClass());
    }

    private function addRateToCart($lineItemsTax, Cart $toCalculate): void
    {
        if (isset($lineItemsTax['rate'])) {
            $rate = $lineItemsTax['rate'];
            if ($rate) {
                foreach ($toCalculate->getLineItems() as $lineItem) {
                    $lineItem->setPayloadValue('vertexTaxRate', $rate);
                }
            }
        }
    }
}
