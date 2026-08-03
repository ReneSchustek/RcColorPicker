<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Storefront\Subscriber;

use Ruhrcoder\RcColorPicker\Service\ConfigService;
use Ruhrcoder\RcColorPicker\Service\CustomFieldInstaller;
use Ruhrcoder\RcColorPicker\Storefront\Struct\RcColorPickerConfigStruct;
use Shopware\Core\Content\Cms\SalesChannel\Struct\BuyBoxStruct;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Event\SwitchBuyBoxVariantEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die Farbauswahl-Konfiguration an **jedes Produkt, das ein Kaufbereich anzeigt**.
 *
 * Warum das nicht `page.product` ist
 * ----------------------------------
 * Naheliegend wäre, die Konfiguration an die Seite oder an `page.product` zu hängen. Beides trägt
 * nicht: Der Kaufbereich ist seit Shopware 6.4 ein CMS-Element, und
 * `cms-element-buy-box.html.twig` übergibt `element.data.product` — eine **eigene Instanz**, die
 * der BuyBox-Resolver lädt. Am 2026-08-03 auf dev-67121 gemessen: gleiche Produkt-ID, andere
 * Erweiterungen. `page.product` trug `rcColorPickerConfig`, die Instanz in der Vorlage nicht.
 *
 * An der Seite zu hängen ging dagegen am CMS-Kaufbereich vorbei:
 * `CmsController::switchBuyBoxVariant()` rendert ihn beim Variantenwechsel mit `product`, aber
 * **ohne `page`** (Kern 6.7.12.1, Zeile 223-228) und ohne ein `ProductPageLoadedEvent` zu feuern.
 * Im ausgetauschten Markup fehlte danach das gesamte Feld samt der verborgenen Nutzlast-Felder:
 * keine Farbauswahl, keine Pflichtprüfung, keine Meldung.
 *
 * Deshalb hier beide Wege, und beide am Produkt: der Aufbau der Seite und der Variantenwechsel.
 */
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
            SwitchBuyBoxVariantEvent::class => 'onSwitchBuyBoxVariant',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $context = $event->getSalesChannelContext();

        // `page.product` bleibt versorgt: Andere Vorlagen und Plugins lesen dort.
        $this->addConfig($page->getProduct(), $context);

        // Und jede BuyBox der CMS-Seite — das ist die Instanz, die der Kaufbereich wirklich rendert.
        foreach ($this->collectBuyBoxProducts($page) as $product) {
            $this->addConfig($product, $context);
        }
    }

    public function onSwitchBuyBoxVariant(SwitchBuyBoxVariantEvent $event): void
    {
        $this->addConfig($event->getProduct(), $event->getSalesChannelContext());
    }

    /**
     * Sammelt die Produkte aller BuyBox-Elemente der Seite.
     *
     * @return list<SalesChannelProductEntity>
     */
    private function collectBuyBoxProducts(object $page): array
    {
        if (!method_exists($page, 'getCmsPage')) {
            return [];
        }

        try {
            $cmsPage = $page->getCmsPage();
        } catch (\Error) {
            // `ProductPage::$cmsPage` ist eine typisierte Eigenschaft **ohne Vorbelegung**. Wurde
            // sie nie gesetzt — etwa weil die Seite ohne CMS-Layout aufgebaut wurde —, wirft schon
            // der Zugriff im Getter, nicht erst die Auswertung. Eine `=== null`-Prüfung dahinter
            // käme nie zum Zuge.
            return [];
        }

        if ($cmsPage === null) {
            return [];
        }

        $products = [];

        foreach ($cmsPage->getSections() ?? [] as $section) {
            foreach ($section->getBlocks() ?? [] as $block) {
                foreach ($block->getSlots() ?? [] as $slot) {
                    $data = $slot->getData();
                    if (!$data instanceof BuyBoxStruct) {
                        continue;
                    }

                    $product = $data->getProduct();
                    if ($product !== null) {
                        $products[] = $product;
                    }
                }
            }
        }

        return $products;
    }

    private function addConfig(SalesChannelProductEntity $product, SalesChannelContext $context): void
    {
        $customFields = $product->getCustomFields() ?? [];

        if (!($customFields[CustomFieldInstaller::CUSTOM_FIELD_ENABLED] ?? false)) {
            return;
        }

        $salesChannelId = $context->getSalesChannel()->getId();

        $product->addExtension(
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
