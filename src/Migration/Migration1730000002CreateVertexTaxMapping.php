<?php

declare(strict_types=1);

namespace VertexTax\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000002CreateVertexTaxMapping extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000002;
    }

    public function update(Connection $connection): void
    {
        $query = /** @lang SQL */
            <<<SQL
          CREATE TABLE IF NOT EXISTS `vertex_tax_mapping` (
          `id` binary(16) NOT NULL,
          `tax_id` binary(16) NOT NULL,
          `provider_id` binary(16) NOT NULL,
          `created_at` datetime DEFAULT NULL,
          `updated_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_tax_id` (`tax_id`),
          KEY `idx_provider_id` (`provider_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       ;
SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `vertex_tax_mapping`');
    }
}
