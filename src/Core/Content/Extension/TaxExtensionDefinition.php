<?php

declare(strict_types=1);

namespace VertexTax\Core\Content\Extension;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\Tax\TaxDefinition;
use VertexTax\Core\Content\TaxProvider\TaxProviderDefinition;

class TaxExtensionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'vertex_tax_mapping';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TaxExtensionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TaxExtensionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new FkField('tax_id', 'taxId', TaxDefinition::class),
            (new IdField('provider_id', 'providerId')),
            new OneToOneAssociationField(
                'tax',
                'tax_id',
                'id',
                TaxDefinition::class,
                false
            ),
            new OneToOneAssociationField(
                'taxProvider',
                'provider_id',
                'id',
                TaxProviderDefinition::class,
                false
            ),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
