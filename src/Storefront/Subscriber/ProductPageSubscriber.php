<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Storefront\Subscriber;

use Ruhrcoder\RcColorPicker\Service\ConfigService;
use Ruhrcoder\RcColorPicker\Service\CustomFieldInstaller;
use Ruhrcoder\RcColorPicker\Storefront\Struct\RcColorPickerConfigStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ConfigService $configService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        $customFields = $product->getCustomFields() ?? [];

        if (!($customFields[CustomFieldInstaller::CUSTOM_FIELD_ENABLED] ?? false)) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        $event->getPage()->addExtension(
            'rcColorPickerConfig',
            new RcColorPickerConfigStruct(
                $this->configService->getStandardColors($salesChannelId),
                $this->configService->isCustomRalAllowed($salesChannelId),
                $this->configService->isColorRequired($salesChannelId),
                $this->configService->isCustomRalLabelVisible($salesChannelId),
            )
        );
    }
}
