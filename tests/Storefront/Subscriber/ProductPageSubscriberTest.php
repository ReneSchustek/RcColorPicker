<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Storefront\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Service\ConfigService;
use Ruhrcoder\RcColorPicker\Storefront\Struct\RcColorPickerConfigStruct;
use Ruhrcoder\RcColorPicker\Storefront\Subscriber\ProductPageSubscriber;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ProductPageSubscriber::class)]
final class ProductPageSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $events = ProductPageSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ProductPageLoadedEvent::class, $events);
    }

    public function testSetztExtensionBeiAktivemCustomField(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([
            'RcColorPicker.config.standardColors' => "RAL 9010;Reinweiß;#FFFFFF",
            'RcColorPicker.config.allowCustomRal' => true,
            'RcColorPicker.config.colorRequired' => false,
            'RcColorPicker.config.customRalLabelVisible' => true,
        ]));

        $event = $this->createEvent(['ruhrcoder_color_picker_enabled' => true]);
        $subscriber->onProductPageLoaded($event);

        $extension = $event->getPage()->getExtension('rcColorPickerConfig');
        self::assertInstanceOf(RcColorPickerConfigStruct::class, $extension);
        self::assertCount(1, $extension->getStandardColors());
        self::assertTrue($extension->isAllowCustomRal());
        self::assertFalse($extension->isColorRequired());
        self::assertTrue($extension->isCustomRalLabelVisible());
    }

    public function testSetztKeineExtensionBeiInaktivemCustomField(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([]));

        $event = $this->createEvent(['ruhrcoder_color_picker_enabled' => false]);
        $subscriber->onProductPageLoaded($event);

        self::assertNull($event->getPage()->getExtension('rcColorPickerConfig'));
    }

    public function testSetztKeineExtensionOhneCustomFields(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([]));

        $event = $this->createEvent(null);
        $subscriber->onProductPageLoaded($event);

        self::assertNull($event->getPage()->getExtension('rcColorPickerConfig'));
    }

    public function testSetztKeineExtensionBeiFehlenderEigenschaft(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([]));

        $event = $this->createEvent(['some_other_field' => true]);
        $subscriber->onProductPageLoaded($event);

        self::assertNull($event->getPage()->getExtension('rcColorPickerConfig'));
    }

    /**
     * @param array<string, mixed> $configMap
     */
    private function createConfigService(array $configMap): ConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $configMap[$key] ?? null,
        );

        return new ConfigService($systemConfig);
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    private function createEvent(?array $customFields): ProductPageLoadedEvent
    {
        $product = new SalesChannelProductEntity();
        $product->setId('prod-1');
        $product->setCustomFields($customFields);

        $page = new ProductPage();
        $page->setProduct($product);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return new ProductPageLoadedEvent(
            $page,
            $context,
            new Request(),
        );
    }
}
