<?php

declare(strict_types=1);

namespace VertexTax\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000000CreateVertexTaxProvider extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        $query = /** @lang SQL */
            <<<SQL
          CREATE TABLE IF NOT EXISTS `vertex_tax_provider` (
          `id` binary(16) NOT NULL,
          `name` varchar(512) DEFAULT NULL,
          `base_class` text,
          `created_at` datetime DEFAULT NULL,
          `updated_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       ;
SQL;
        $connection->executeStatement($query);
        $connection->executeStatement('TRUNCATE TABLE `vertex_tax_provider`');
        $this->addVertexTaxProvider($connection);
    }

    private function addVertexTaxProvider(Connection $connection): void
    {
        $vertexDataEntry = $this->getVertexData();
        $vertexDataEntry['id'] = Uuid::randomBytes();
        $vertexDataEntry['created_at'] = date('Y-m-d H:i:s', time());
        $connection->insert('vertex_tax_provider', $vertexDataEntry);
    }

    private function getVertexData(): array
    {
        return [
            'name' => 'Vertex Tax',
            'base_class' => 'VertexTax\\Core\\Vertex\\Calculator',
        ];
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `vertex_tax_provider`');
    }
}
