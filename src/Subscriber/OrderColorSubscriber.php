<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcColorPicker\Service\ColorValidator;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Überträgt die Farbauswahl aus dem LineItem-Payload in die Order-LineItem-CustomFields.
 *
 * Priorität -500: niedrig genug, damit andere Subscriber die Werte nicht überschreiben.
 */
final class OrderColorSubscriber implements EventSubscriberInterface
{
    private const PAYLOAD_ACTIVE = 'rcColorPickerActive';
    private const PAYLOAD_RAL = 'rcColorPickerRal';
    private const PAYLOAD_NAME = 'rcColorPickerName';
    private const PAYLOAD_HEX = 'rcColorPickerHex';

    private const CF_RAL = 'ruhrcoder_color_picker_ral';
    private const CF_NAME = 'ruhrcoder_color_picker_name';
    private const CF_HEX = 'ruhrcoder_color_picker_hex';

    private const LOG_CONTEXT = 'ruhrcoder_color_picker.order_color_subscriber';

    /** @param EntityRepository<OrderLineItemCollection> $orderLineItemRepository */
    public function __construct(
        private readonly EntityRepository $orderLineItemRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500],
            CheckoutFinishPageLoadedEvent::class => ['onCheckoutFinish', -500],
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->processOrder($event->getOrder(), $event->getContext());
    }

    public function onCheckoutFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        $this->processOrder($event->getPage()->getOrder(), $event->getContext());
    }

    private function processOrder(OrderEntity $order, Context $context): void
    {
        $lineItems = $order->getLineItems();

        if ($lineItems === null) {
            return;
        }

        $this->correctLineItems($lineItems, $order->getId(), $context);
    }

    private function correctLineItems(OrderLineItemCollection $lineItems, string $orderId, Context $context): void
    {
        $updates = [];

        foreach ($lineItems as $lineItem) {
            if (!$this->hasColorSelection($lineItem)) {
                continue;
            }

            $correctedCustomFields = $this->buildCustomFields($lineItem);
            $correctedPayload = $this->buildPayload($lineItem);

            if ($correctedCustomFields === null && $correctedPayload === null) {
                continue;
            }

            $update = ['id' => $lineItem->getId()];

            if ($correctedCustomFields !== null) {
                $update['customFields'] = $correctedCustomFields;
                // Entity im Speicher korrigieren für nachfolgende Subscriber/Templates
                $lineItem->setCustomFields($correctedCustomFields);
            }

            if ($correctedPayload !== null) {
                $update['payload'] = $correctedPayload;
                $lineItem->setPayload($correctedPayload);
            }

            $updates[] = $update;
        }

        if ($updates === []) {
            return;
        }

        try {
            $this->orderLineItemRepository->update($updates, $context);
        } catch (\Throwable $exception) {
            // Bewusst geschluckt: die Farb-CustomFields sind reine Anzeige-Daten. Ein DB-Fehler
            // beim Nachtragen darf den bereits abgeschlossenen Bestellprozess nicht mit einem 500
            // abreissen — nur strukturiert protokollieren, damit Ops die Ursache findet.
            $this->logger->error(
                'RcColorPicker: Order-LineItem-Update fehlgeschlagen.',
                [
                    'context' => self::LOG_CONTEXT,
                    'orderId' => $orderId,
                    'lineItemCount' => count($updates),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildCustomFields(OrderLineItemEntity $lineItem): ?array
    {
        $payload = $lineItem->getPayload();
        $customFields = $lineItem->getCustomFields() ?? [];

        // Serverseitige Validierung an der Persistenz-Kante: nur valider Hex und
        // längenbegrenzter Freitext landen in den Order-CustomFields (und damit in der Mail).
        $corrected = $customFields;
        $corrected[self::CF_RAL] = ColorValidator::sanitizeText((string) ($payload[self::PAYLOAD_RAL] ?? ''));
        $corrected[self::CF_NAME] = ColorValidator::sanitizeText((string) ($payload[self::PAYLOAD_NAME] ?? ''));
        $corrected[self::CF_HEX] = ColorValidator::sanitizeHex((string) ($payload[self::PAYLOAD_HEX] ?? ''));

        // `CheckoutFinishPageLoadedEvent` feuert bei jedem Reload von /checkout/finish. Stehen die
        // Farbwerte schon so in den CustomFields, ist das Nachtragen ein reiner Leerlauf-Write —
        // übersprungen, damit ein F5 auf der Bestellbestätigung keine DB-Last erzeugt.
        if ($corrected === $customFields) {
            return null;
        }

        return $corrected;
    }

    /**
     * Trägt die geprüften Werte auch in die **Nutzlast** ein.
     *
     * Bis zum 2026-08-03 fasste dieser Abonnent nur die CustomFields an — und das war wirkungslos
     * für alles Sichtbare: Warenkorb-Template und Bestellbestätigung lesen die Nutzlast, der
     * Rückfall auf die CustomFields greift bei aktiver Farbauswahl nie. Die „Validierung an der
     * Persistenz-Kante“ prüfte damit genau die Kopie, die niemand anzeigt.
     *
     * @return array<string, mixed>|null null, wenn nichts zu ändern war
     */
    private function buildPayload(OrderLineItemEntity $lineItem): ?array
    {
        $payload = $lineItem->getPayload() ?? [];

        $corrected = $payload;
        $corrected[self::PAYLOAD_RAL] = ColorValidator::sanitizeText((string) ($payload[self::PAYLOAD_RAL] ?? ''));
        $corrected[self::PAYLOAD_NAME] = ColorValidator::sanitizeText((string) ($payload[self::PAYLOAD_NAME] ?? ''));
        $corrected[self::PAYLOAD_HEX] = ColorValidator::sanitizeHex((string) ($payload[self::PAYLOAD_HEX] ?? ''));

        // Wie bei den CustomFields: Ein Neuladen der Bestellbestätigung darf keinen Schreibvorgang
        // auslösen, wenn sich nichts ändert.
        if ($corrected === $payload) {
            return null;
        }

        return $corrected;
    }

    /** Headless-/Store-API-Clients senden die Kennzeichnung auch als int oder bool. */
    private function hasColorSelection(OrderLineItemEntity $lineItem): bool
    {
        $payload = $lineItem->getPayload();

        return \in_array($payload[self::PAYLOAD_ACTIVE] ?? null, ['1', 1, true], true);
    }
}
