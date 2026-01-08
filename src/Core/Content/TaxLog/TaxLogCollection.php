<?php

declare(strict_types=1);

namespace VertexTax\Core\Content\TaxLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                add(TaxLogEntity $entity)
 * @method void                set(string $key, TaxLogEntity $entity)
 * @method TaxLogEntity[]      getIterator()
 * @method TaxLogEntity[]      getElements()
 * @method TaxLogEntity|null   get(string $key)
 * @method TaxLogEntity|null   first()
 * @method TaxLogEntity|null   last()
 */
class TaxLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxLogEntity::class;
    }
}
