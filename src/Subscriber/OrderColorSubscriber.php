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
            $corrected = $this->buildCustomFields($lineItem);

            if ($corrected === null) {
                continue;
            }

            $updates[] = [
                'id' => $lineItem->getId(),
                'customFields' => $corrected,
            ];

            // Entity im Speicher korrigieren für nachfolgende Subscriber/Templates
            $lineItem->setCustomFields($corrected);
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

        // Headless-/Store-API-Clients senden den Aktiv-Flag evtl. als int/bool statt String '1'.
        if (!\in_array($payload[self::PAYLOAD_ACTIVE] ?? null, ['1', 1, true], true)) {
            return null;
        }

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
}
