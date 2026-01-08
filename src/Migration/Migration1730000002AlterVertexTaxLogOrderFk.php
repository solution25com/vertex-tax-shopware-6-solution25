<?php

declare(strict_types=1);

namespace VertexTax\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000002AlterVertexTaxLogOrderFk extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000002;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('ALTER TABLE `vertex_tax_log` CHANGE `order_id` `order_id` BINARY(16) NULL');

        try {
            $connection->executeStatement('ALTER TABLE `vertex_tax_log` DROP INDEX `idx_order_id`');
        } catch (\Throwable $e) {
        }

        try {
            $connection->executeStatement('ALTER TABLE `vertex_tax_log` DROP FOREIGN KEY `fk.vertex_tax_log.order_id`');
        } catch (\Throwable $e) {
        }

        try {
            $connection->executeStatement('CREATE INDEX `idx_order_id` ON `vertex_tax_log` (`order_id`)');
        } catch (\Throwable $e) {
        }

        try {
            $connection->executeStatement('
            ALTER TABLE `vertex_tax_log` 
            ADD CONSTRAINT `fk.vertex_tax_log.order_id` 
            FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) 
            ON DELETE SET NULL ON UPDATE CASCADE
        ');
        } catch (\Throwable $e) {
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
