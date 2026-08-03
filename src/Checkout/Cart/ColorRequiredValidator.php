<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Checkout\Cart;

use Ruhrcoder\RcColorPicker\Service\ConfigService;
use Ruhrcoder\RcColorPicker\Service\CustomFieldInstaller;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Setzt die Pflichtfarbe dort durch, wo sie sich nicht umgehen lässt.
 *
 * Warum das nicht im Browser reicht
 * ---------------------------------
 * Die Prüfung lag bis zum 2026-08-03 allein im JavaScript — und griff selbst dort nicht:
 * Shopwares `AddToCart` hängt am selben Formular und wird zuerst angemeldet, hatte den Artikel
 * also bereits per Hintergrundanfrage im Warenkorb, bevor die Prüfung überhaupt lief. Ein
 * Headless-Client umgeht sie ohnehin, und ein Warenkorb kann aus einer Sitzung stammen, in der
 * die Farbe noch nicht Pflicht war.
 *
 * Der Warenkorb-Prüfer läuft bei **jeder** Neuberechnung, unabhängig davon, wie die Position
 * hineingekommen ist. Das ist die einzige Stelle, an der sich die Zusage einlösen lässt.
 *
 * Blockieren statt entfernen
 * --------------------------
 * Der Fehler blockiert die Bestellung, die Position bleibt aber liegen. Sie stillschweigend zu
 * entfernen wäre das schlechtere Verhalten: Der Kunde hat sie bewusst gewählt, ihm fehlt nur eine
 * Angabe. Ein verschwundener Artikel sieht aus wie ein Fehler des Shops.
 */
class ColorRequiredValidator implements CartValidatorInterface
{
    private const PAYLOAD_ACTIVE = 'rcColorPickerActive';
    private const PAYLOAD_HEX = 'rcColorPickerHex';
    private const PAYLOAD_RAL = 'rcColorPickerRal';

    public function __construct(private readonly ConfigService $configService)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if (!$this->configService->isColorRequired($context->getSalesChannel()->getId())) {
            return;
        }

        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            if (!$this->needsColor($lineItem)) {
                continue;
            }

            if ($this->hasColor($lineItem)) {
                continue;
            }

            $errors->add(new MissingColorError(
                $lineItem->getId(),
                (string) $lineItem->getLabel(),
            ));
        }
    }

    /**
     * Gilt die Farbpflicht für diesen Artikel?
     *
     * Nur für Artikel mit gesetztem Zusatzfeld — der Schalter in den Einstellungen macht die
     * Farbe nicht für das ganze Sortiment zur Pflicht, sondern für die Artikel, an denen die
     * Farbauswahl überhaupt angeboten wird.
     */
    private function needsColor(LineItem $lineItem): bool
    {
        $payload = $lineItem->getPayload();

        // Der Weg über die Storefront setzt die Kennzeichnung mit. Fehlt sie, liegt der Artikel
        // aus einer Zeit im Warenkorb, in der die Farbauswahl für ihn nicht angeboten wurde --
        // dann ist das Zusatzfeld die verbliebene Quelle.
        if (isset($payload[self::PAYLOAD_ACTIVE]) && $payload[self::PAYLOAD_ACTIVE]) {
            return true;
        }

        $customFields = $payload['customFields'] ?? null;
        if (!\is_array($customFields)) {
            return false;
        }

        return (bool) ($customFields[CustomFieldInstaller::CUSTOM_FIELD_ENABLED] ?? false);
    }

    /**
     * Zählt eine Farbe nur, wenn sie auch etwas aussagt.
     *
     * Ein leerer Hex-Wert entsteht, wenn die Prüfung einen ungültigen Wert verworfen hat -- die
     * Kennzeichnung steht dann noch, die Farbe nicht. Auf `isset` allein zu prüfen ließe genau
     * diesen Fall durch.
     */
    private function hasColor(LineItem $lineItem): bool
    {
        $payload = $lineItem->getPayload();

        $hex = $payload[self::PAYLOAD_HEX] ?? '';
        $ral = $payload[self::PAYLOAD_RAL] ?? '';

        return (\is_string($hex) && $hex !== '') || (\is_string($ral) && $ral !== '');
    }
}
