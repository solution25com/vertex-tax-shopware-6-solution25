<?php

declare(strict_types=1);

namespace VertexTax\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1784300000AddTaxLogDedupFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784300000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->hasColumn($connection, 'request_hash')) {
            $connection->executeStatement(
                'ALTER TABLE `vertex_tax_log` ADD COLUMN `request_hash` VARCHAR(64) NULL'
            );
        }

        if (!$this->hasColumn($connection, 'occurrence_count')) {
            $connection->executeStatement(
                'ALTER TABLE `vertex_tax_log` ADD COLUMN `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 1'
            );
        }

        if (!$this->hasColumn($connection, 'last_occurred_at')) {
            $connection->executeStatement(
                'ALTER TABLE `vertex_tax_log` ADD COLUMN `last_occurred_at` DATETIME(3) NULL'
            );
        }

        if (!$this->hasIndex($connection, 'idx_vertex_tax_log_dedup')) {
            $connection->executeStatement(
                'CREATE INDEX `idx_vertex_tax_log_dedup` ON `vertex_tax_log` (`type`, `request_hash`, `created_at`)'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function hasColumn(Connection $connection, string $column): bool
    {
        return (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'vertex_tax_log'
                  AND column_name = :column
                SQL,
            ['column' => $column]
        ) > 0;
    }

    private function hasIndex(Connection $connection, string $index): bool
    {
        return (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'vertex_tax_log'
                  AND index_name = :index
                SQL,
            ['index' => $index]
        ) > 0;
    }
}
