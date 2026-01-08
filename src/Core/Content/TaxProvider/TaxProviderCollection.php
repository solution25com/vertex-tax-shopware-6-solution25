<?php

declare(strict_types=1);

namespace VertexTax\Core\Content\TaxProvider;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                    add(TaxProviderEntity $entity)
 * @method void                    set(string $key, TaxProviderEntity $entity)
 * @method TaxProviderEntity[]     getIterator()
 * @method TaxProviderEntity[]     getElements()
 * @method TaxProviderEntity|null  get(string $key)
 * @method TaxProviderEntity|null  first()
 * @method TaxProviderEntity|null  last()
 */
class TaxProviderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxProviderEntity::class;
    }
}
