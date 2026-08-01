<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker;

use Ruhrcoder\RcColorPicker\Exception\RcColorPickerException;
use Ruhrcoder\RcColorPicker\Service\CustomFieldInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

final class RcColorPicker extends Plugin
{
    /**
     * Ab dieser Version übernimmt Migration1777248000RenameCustomFields den
     * Set-/Feld-Rename. Der Installer würde davor versuchen, das alte Set per
     * DAL umzubenennen, was Shopware mit "name is immutable" blockiert.
     */
    private const MIGRATION_INTRODUCED_IN = '2.0.0';

    public function install(InstallContext $context): void
    {
        parent::install($context);
        $this->getInstaller()->install($context->getContext());
    }

    public function update(UpdateContext $context): void
    {
        parent::update($context);

        if (version_compare($context->getCurrentPluginVersion(), self::MIGRATION_INTRODUCED_IN, '<')) {
            return;
        }

        $this->getInstaller()->install($context->getContext());
    }

    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        if (!$context->keepUserData()) {
            $this->getInstaller()->uninstall($context->getContext());
        }
    }

    /**
     * Während install/update/uninstall sind plugin-eigene Services nicht im DI-Container
     * (Shopware-Lifecycle). Deshalb Installer direkt instanziieren und ausschließlich
     * Core-Services aus dem Container holen.
     */
    private function getInstaller(): CustomFieldInstaller
    {
        $container = $this->container;

        if ($container === null) {
            throw RcColorPickerException::containerNotAvailable();
        }

        $repository = $container->get('custom_field_set.repository');

        if (!$repository instanceof EntityRepository) {
            throw RcColorPickerException::customFieldSetRepositoryMissing();
        }

        return new CustomFieldInstaller($repository);
    }
}
