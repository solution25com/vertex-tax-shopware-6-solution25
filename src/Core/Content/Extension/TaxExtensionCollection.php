<?php

declare(strict_types=1);

namespace VertexTax\Core\Content\Extension;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                    add(TaxExtensionEntity $entity)
 * @method void                    set(string $key, TaxExtensionEntity $entity)
 * @method TaxExtensionEntity[]     getIterator()
 * @method TaxExtensionEntity[]     getElements()
 * @method TaxExtensionEntity|null  get(string $key)
 * @method TaxExtensionEntity|null  first()
 * @method TaxExtensionEntity|null  last()
 */
class TaxExtensionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxExtensionEntity::class;
    }
}
