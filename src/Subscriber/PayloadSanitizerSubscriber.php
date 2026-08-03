<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Subscriber;

use Ruhrcoder\RcColorPicker\Service\ColorValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Entfernt unsicheres Markup aus der Farbauswahl-Nutzlast, bevor sie im Warenkorb landet.
 *
 * **Dies ist die einzige wirksame Prüfung der Werte, die später angezeigt werden.** Die
 * Bereinigung in `OrderColorSubscriber` fasst nur die CustomFields an; Warenkorb-Template und
 * Bestellbestätigung lesen aber die Nutzlast. Was hier durchkommt, kommt überall durch.
 *
 * Zwei Parameternamen, nicht einer
 * --------------------------------
 * Die Storefront schickt `lineItems`, die Store-API **`items`** (`CartItemAddRoute.php:57`,
 * `CartItemUpdateRoute.php:46`). Bis zum 2026-08-03 las dieser Abonnent nur `lineItems` — und der
 * Klassenkommentar behauptete trotzdem, Headless- und PWA-Clients seien abgedeckt. Waren sie
 * nicht: Ein `POST /store-api/checkout/cart/line-item` mit
 * `items[0][payload][rcColorPickerHex] = "red;background-image:url(…)"` legte den Wert ungeprüft
 * in `order_line_item.payload`. Im Warenkorb-Template steht er in einem `style`-Attribut;
 * `e('html_attr')` verhindert dort den Ausbruch aus dem Attribut, aber kein Semikolon im
 * CSS-Wert.
 *
 * Zum Zeitpunkt: Shopwares `JsonRequestTransformerListener` läuft mit Priorität 128 und hat
 * JSON-Rümpfe bereits in `$request->request` abgelegt; dieser Abonnent mit Vorrang 0 sieht sie
 * also. Der `_route`-Eintrag steht ebenfalls (RouterListener, Vorrang 32).
 */
final class PayloadSanitizerSubscriber implements EventSubscriberInterface
{
    private const TEXT_KEYS = ['rcColorPickerRal', 'rcColorPickerName'];
    private const HEX_KEY = 'rcColorPickerHex';
    private const CHECKOUT_ROUTE_PREFIXES = ['frontend.checkout', 'store-api.checkout'];

    /** Storefront und Store-API benennen dieselbe Sache verschieden. */
    private const PARAMETER_NAMES = ['lineItems', 'items'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'sanitizePayload',
        ];
    }

    public function sanitizePayload(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $route = (string) $request->attributes->get('_route', '');
        $matches = false;
        foreach (self::CHECKOUT_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return;
        }

        foreach (self::PARAMETER_NAMES as $parameterName) {
            /** @var array<int|string, array<string, mixed>> $items */
            $items = $request->request->all($parameterName);

            if ($items === []) {
                continue;
            }

            $sanitized = $this->sanitizeItems($items);

            if ($sanitized !== null) {
                $request->request->set($parameterName, $sanitized);
            }
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $items
     *
     * @return array<int|string, array<string, mixed>>|null null, wenn nichts zu ändern war
     */
    private function sanitizeItems(array $items): ?array
    {
        $changed = false;

        foreach ($items as $id => $item) {
            if (!isset($item['payload']) || !\is_array($item['payload'])) {
                continue;
            }

            foreach (self::TEXT_KEYS as $key) {
                if (isset($item['payload'][$key]) && \is_string($item['payload'][$key])) {
                    $items[$id]['payload'][$key] = ColorValidator::sanitizeText($item['payload'][$key]);
                    $changed = true;
                }
            }

            if (isset($item['payload'][self::HEX_KEY]) && \is_string($item['payload'][self::HEX_KEY])) {
                // Hex strikt prüfen: Ein ungültiger Wert erlaubt im Inline-Style eine
                // CSS-Einschleusung — und das JavaScript umgeht ein Headless-Client ohnehin.
                $items[$id]['payload'][self::HEX_KEY] = ColorValidator::sanitizeHex($item['payload'][self::HEX_KEY]);
                $changed = true;
            }
        }

        return $changed ? $items : null;
    }
}
