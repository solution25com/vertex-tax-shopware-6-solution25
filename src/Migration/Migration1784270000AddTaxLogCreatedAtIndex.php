<?php

declare(strict_types=1);

namespace VertexTax\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1784270000AddTaxLogCreatedAtIndex extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784270000;
    }

    public function update(Connection $connection): void
    {
        $indexExists = (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'vertex_tax_log'
                  AND index_name = 'idx_vertex_tax_log_created_at'
                SQL
        ) > 0;

        if (!$indexExists) {
            $connection->executeStatement(
                'CREATE INDEX `idx_vertex_tax_log_created_at` ON `vertex_tax_log` (`created_at`)'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
