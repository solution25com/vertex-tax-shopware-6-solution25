<?php

declare(strict_types=1);

namespace VertexTax\Service\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Reads promotion line items from the cart and returns how muchdiscount
 * was allocated to each product, keyed by the product's referencedId.
 *
 * The composition payload on each promotion line item already maps directly
 * to the product's referencedId, so no remapping is needed in the cart context.
 */
final class CartPromotionDiscountExtractor
{
    /**
     * @return array<string, float> referencedId => accumulated discount (>= 0)
     */
    public function getDiscountsPerLineItem(Cart $cart): array
    {
        return $this->getDiscountsFromLineItems($cart->getLineItems());
    }

    public function getDiscountsFromLineItems(iterable $lineItems): array
    {
        $discounts = [];

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== LineItem::PROMOTION_LINE_ITEM_TYPE) {
                continue;
            }

            $payload = $lineItem->getPayload();
            if (empty($payload['composition']) || !\is_array($payload['composition'])) {
                continue;
            }

            foreach ($payload['composition'] as $entry) {
                $referencedId = $entry['id'] ?? null;
                $discountAmount = abs((float) ($entry['discount'] ?? 0));

                if ($referencedId) {
                    $discounts[$referencedId] = ($discounts[$referencedId] ?? 0.0) + $discountAmount;
                }
            }
        }

        return $discounts;
    }
}
