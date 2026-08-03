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
use Shopware\Storefront\Event\SwitchBuyBoxVariantEvent;
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
        // Ohne dieses Ereignis verliert der Kaufbereich auf CMS-Seiten die Farbauswahl beim
        // Variantenwechsel — der Kern rendert ihn dort ohne `page`.
        self::assertArrayHasKey(SwitchBuyBoxVariantEvent::class, $events);
    }

    public function testSetsExtensionWhenCustomFieldIsActive(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([
            'RcColorPicker.config.standardColors' => "RAL 9010;Reinweiß;#FFFFFF",
            'RcColorPicker.config.allowCustomRal' => true,
            'RcColorPicker.config.colorRequired' => false,
            'RcColorPicker.config.customRalLabelVisible' => true,
        ]));

        $event = $this->createEvent(['ruhrcoder_color_picker_enabled' => true]);
        $subscriber->onProductPageLoaded($event);

        $extension = $event->getPage()->getProduct()->getExtension('rcColorPickerConfig');
        self::assertInstanceOf(RcColorPickerConfigStruct::class, $extension);
        self::assertCount(1, $extension->getStandardColors());
        self::assertTrue($extension->isAllowCustomRal());
        self::assertFalse($extension->isColorRequired());
        self::assertTrue($extension->isCustomRalLabelVisible());
    }

    public function testSetsNoExtensionWhenCustomFieldIsInactive(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([]));

        $event = $this->createEvent(['ruhrcoder_color_picker_enabled' => false]);
        $subscriber->onProductPageLoaded($event);

        self::assertNull($event->getPage()->getProduct()->getExtension('rcColorPickerConfig'));
    }

    public function testSetsNoExtensionWithoutCustomFields(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([]));

        $event = $this->createEvent(null);
        $subscriber->onProductPageLoaded($event);

        self::assertNull($event->getPage()->getProduct()->getExtension('rcColorPickerConfig'));
    }

    public function testSetsNoExtensionWhenTheFieldIsAbsent(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([]));

        $event = $this->createEvent(['some_other_field' => true]);
        $subscriber->onProductPageLoaded($event);

        self::assertNull($event->getPage()->getProduct()->getExtension('rcColorPickerConfig'));
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

    /**
     * Was: Der Kaufbereich auf einer CMS-Seite nach einem Variantenwechsel.
     * Warum: `CmsController::switchBuyBoxVariant()` rendert ihn mit `product`, aber **ohne
     *        `page`** und ohne ein `ProductPageLoadedEvent` zu feuern. Solange die Konfiguration
     *        an der Seite hing, fehlte im ausgetauschten Markup das gesamte Feld — samt der
     *        verborgenen Nutzlast-Felder. Der Kunde bestellte ohne Farbe und merkte nichts.
     * Erwartet: Die Konfiguration hängt am Produkt.
     */
    public function testSwitchBuyBoxVariantAlsoCarriesTheConfiguration(): void
    {
        $subscriber = new ProductPageSubscriber($this->createConfigService([
            'RcColorPicker.config.standardColors' => "RAL 9010;Reinweiß;#FFFFFF",
        ]));

        $product = new SalesChannelProductEntity();
        $product->setId('prod-1');
        $product->setCustomFields(['ruhrcoder_color_picker_enabled' => true]);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        $subscriber->onSwitchBuyBoxVariant(new SwitchBuyBoxVariantEvent(
            'element-1',
            $product,
            null,
            new Request(),
            $context,
        ));

        self::assertInstanceOf(
            RcColorPickerConfigStruct::class,
            $product->getExtension('rcColorPickerConfig'),
        );
    }
}
