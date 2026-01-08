<?php

declare(strict_types=1);

namespace VertexTax;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Kernel;

class VertexTax extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $migrationCollection = $installContext->getMigrationCollection();
        $migrationSteps = $migrationCollection->getMigrationSteps();

        foreach ($migrationSteps as $migration) {
            $migration->update(Kernel::getConnection());
        }

        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if (!$uninstallContext->keepUserData()) {
            $migrationCollection = $uninstallContext->getMigrationCollection();
            $migrationSteps = $migrationCollection->getMigrationSteps();
            foreach ($migrationSteps as $migration) {
                $migration->updateDestructive(Kernel::getConnection());
            }
        }

        parent::uninstall($uninstallContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        $migrationCollection = $updateContext->getMigrationCollection();
        $migrationSteps = $migrationCollection->getMigrationSteps();

        foreach ($migrationSteps as $migration) {
            $migration->update(Kernel::getConnection());
        }

        parent::update($updateContext);
    }
}
