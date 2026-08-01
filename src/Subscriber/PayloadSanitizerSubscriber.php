<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Subscriber;

use Ruhrcoder\RcColorPicker\Service\ColorValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strippt potentiell unsicheres Markup aus dem RcColorPicker-Payload, bevor es
 * im Cart/LineItem persistiert wird.
 *
 * Wird auf form-encoded UND JSON-Bodies wirksam: Shopware-Core feuert
 * `JsonRequestTransformerListener` mit Priorität 128 vor allen anderen
 * `KernelEvents::REQUEST`-Listenern und mappt JSON-Bodies in `$request->request`.
 * Dieser Sanitizer läuft mit Default-Priorität 0, sieht also bereits den
 * ParameterBag-Zustand nach JSON-Decode — sowohl bei klassischen
 * Storefront-Requests (form-encoded) als auch bei Headless-/PWA-/Store-API-
 * Clients (`Content-Type: application/json`).
 */
final class PayloadSanitizerSubscriber implements EventSubscriberInterface
{
    private const TEXT_KEYS = ['rcColorPickerRal', 'rcColorPickerName'];
    private const HEX_KEY = 'rcColorPickerHex';
    private const CHECKOUT_ROUTE_PREFIXES = ['frontend.checkout', 'store-api.checkout'];

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

        /** @var array<string, array<string, mixed>> $lineItems */
        $lineItems = $request->request->all('lineItems');

        if ($lineItems === []) {
            return;
        }

        $changed = false;

        foreach ($lineItems as $id => $item) {
            if (!isset($item['payload']) || !\is_array($item['payload'])) {
                continue;
            }

            foreach (self::TEXT_KEYS as $key) {
                if (isset($item['payload'][$key]) && \is_string($item['payload'][$key])) {
                    $lineItems[$id]['payload'][$key] = ColorValidator::sanitizeText($item['payload'][$key]);
                    $changed = true;
                }
            }

            if (isset($item['payload'][self::HEX_KEY]) && \is_string($item['payload'][self::HEX_KEY])) {
                // Hex strikt validieren: ein ungültiger Wert würde im Inline-Style
                // CSS-Injection erlauben (auch Headless-Clients umgehen das JS).
                $lineItems[$id]['payload'][self::HEX_KEY] = ColorValidator::sanitizeHex($item['payload'][self::HEX_KEY]);
                $changed = true;
            }
        }

        if ($changed) {
            $request->request->set('lineItems', $lineItems);
        }
    }
}
