<?php

declare(strict_types=1);

namespace VertexTax\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000001CreateVertexTaxLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000001;
    }

    public function update(Connection $connection): void
    {
        $query = /** @lang SQL */
            <<<SQL
          CREATE TABLE IF NOT EXISTS `vertex_tax_log` (
          `id` binary(16) NOT NULL,
          `customer_name` varchar(255) DEFAULT NULL,
          `remote_ip` varchar(45) DEFAULT NULL,
          `customer_email` varchar(255) DEFAULT NULL,
          `request_key` longtext,
          `type` varchar(255) DEFAULT NULL,
          `order_number` varchar(255) DEFAULT NULL,
          `order_id` varchar(255) DEFAULT NULL,
          `request` longtext,
          `response` longtext,
          `created_at` datetime DEFAULT NULL,
          `updated_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_order_id` (`order_id`),
          KEY `idx_type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       ;
SQL;
        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `vertex_tax_log`');
    }
}
