<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcColorPicker\Subscriber\OrderColorSubscriber;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(OrderColorSubscriber::class)]
final class OrderColorSubscriberTest extends TestCase
{
    private function createPlacedEvent(OrderEntity $order): CheckoutOrderPlacedEvent
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return new CheckoutOrderPlacedEvent($salesChannelContext, $order);
    }

    public function testSubscribedEvents(): void
    {
        $events = OrderColorSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutOrderPlacedEvent::class, $events);
        self::assertSame(['onOrderPlaced', -500], $events[CheckoutOrderPlacedEvent::class]);
    }

    public function testUebertraegtPayloadInCustomFields(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 7016',
            'rcColorPickerName' => 'Anthrazitgrau',
            'rcColorPickerHex' => '#293133',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(
                self::callback(function (array $updates): bool {
                    self::assertCount(1, $updates);
                    self::assertSame('item-1', $updates[0]['id']);
                    self::assertSame('RAL 7016', $updates[0]['customFields']['ruhrcoder_color_picker_ral']);
                    self::assertSame('Anthrazitgrau', $updates[0]['customFields']['ruhrcoder_color_picker_name']);
                    self::assertSame('#293133', $updates[0]['customFields']['ruhrcoder_color_picker_hex']);

                    return true;
                }),
                self::isInstanceOf(Context::class),
            );

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $event = $this->createPlacedEvent($order);

        $subscriber->onOrderPlaced($event);

        // Entity im Speicher ebenfalls aktualisiert
        $cf = $lineItem->getCustomFields();
        self::assertSame('RAL 7016', ($cf['ruhrcoder_color_picker_ral'] ?? null));
    }

    public function testVerwirftUngueltigenHexGegenCssInjection(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 7016',
            'rcColorPickerName' => 'Anthrazitgrau',
            // CSS-Injection-Versuch: kein valider Hex -> muss geleert werden.
            'rcColorPickerHex' => 'red;background:url(//evil/x)',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $updates): bool {
                self::assertSame('', $updates[0]['customFields']['ruhrcoder_color_picker_hex']);

                return true;
            }), self::isInstanceOf(Context::class));

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $subscriber->onOrderPlaced($this->createPlacedEvent($order));
    }

    public function testIgnoriertInaktiveItems(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '0',
            'rcColorPickerRal' => 'RAL 7016',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $event = $this->createPlacedEvent($order);

        $subscriber->onOrderPlaced($event);
    }

    public function testIgnoriertItemsOhneAktivFlag(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload(['someOtherKey' => 'value']);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $event = $this->createPlacedEvent($order);

        $subscriber->onOrderPlaced($event);
    }

    public function testErhaeltBestehendeCustonFields(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 9010',
            'rcColorPickerName' => 'Reinweiß',
            'rcColorPickerHex' => '#FFFFFF',
        ]);
        $lineItem->setCustomFields(['existing_field' => 'keep_this']);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(
                self::callback(function (array $updates): bool {
                    $cf = $updates[0]['customFields'];
                    self::assertSame('keep_this', $cf['existing_field']);
                    self::assertSame('RAL 9010', ($cf['ruhrcoder_color_picker_ral'] ?? null));

                    return true;
                }),
                self::isInstanceOf(Context::class),
            );

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $event = $this->createPlacedEvent($order);

        $subscriber->onOrderPlaced($event);
    }

    /**
     * `CheckoutFinishPageLoadedEvent` feuert bei jedem Reload der Bestellbestätigung.
     * Stehen die Farbwerte bereits korrekt in den CustomFields, darf kein Write mehr rausgehen.
     */
    public function testSchreibtNichtErneutWennCustomFieldsBereitsStimmen(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 7016',
            'rcColorPickerName' => 'Anthrazitgrau',
            'rcColorPickerHex' => '#293133',
        ]);
        $lineItem->setCustomFields([
            'ruhrcoder_color_picker_ral' => 'RAL 7016',
            'ruhrcoder_color_picker_name' => 'Anthrazitgrau',
            'ruhrcoder_color_picker_hex' => '#293133',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $subscriber->onOrderPlaced($this->createPlacedEvent($order));
    }

    /**
     * Gegenprobe zum Guard: Weicht auch nur ein Wert ab, wird weiterhin nachgetragen.
     */
    public function testSchreibtWennEinCustomFieldAbweicht(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 7016',
            'rcColorPickerName' => 'Anthrazitgrau',
            'rcColorPickerHex' => '#293133',
        ]);
        $lineItem->setCustomFields([
            'ruhrcoder_color_picker_ral' => 'RAL 7016',
            'ruhrcoder_color_picker_name' => 'Anthrazitgrau',
            // Veraltet — muss korrigiert werden.
            'ruhrcoder_color_picker_hex' => '#000000',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $updates): bool {
                self::assertSame('#293133', $updates[0]['customFields']['ruhrcoder_color_picker_hex']);

                return true;
            }), self::isInstanceOf(Context::class));

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $subscriber->onOrderPlaced($this->createPlacedEvent($order));
    }

    /**
     * Derselbe Artikel in zwei Farben ist zwei Positionen. Jede Position traegt ihre eigene
     * Farbe — die erste darf von der zweiten nicht überschrieben werden.
     */
    public function testZweiFarbenBleibenZweiEigenstaendigePositionen(): void
    {
        $rot = new OrderLineItemEntity();
        $rot->setId('item-rot');
        $rot->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 3020',
            'rcColorPickerName' => 'Verkehrsrot',
            'rcColorPickerHex' => '#CC0605',
        ]);

        $blau = new OrderLineItemEntity();
        $blau->setId('item-blau');
        $blau->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 5010',
            'rcColorPickerName' => 'Enzianblau',
            'rcColorPickerHex' => '#0E294B',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $updates): bool {
                self::assertCount(2, $updates, 'Beide Positionen muessen geschrieben werden.');

                $byId = array_column($updates, 'customFields', 'id');
                self::assertSame('RAL 3020', $byId['item-rot']['ruhrcoder_color_picker_ral']);
                self::assertSame('#CC0605', $byId['item-rot']['ruhrcoder_color_picker_hex']);
                self::assertSame('RAL 5010', $byId['item-blau']['ruhrcoder_color_picker_ral']);
                self::assertSame('#0E294B', $byId['item-blau']['ruhrcoder_color_picker_hex']);

                return true;
            }), self::isInstanceOf(Context::class));

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$rot, $blau]));

        $subscriber->onOrderPlaced($this->createPlacedEvent($order));

        // Auch im Speicher behaelt jede Position ihre eigene Farbe.
        self::assertSame('RAL 3020', $rot->getCustomFields()['ruhrcoder_color_picker_ral'] ?? null);
        self::assertSame('RAL 5010', $blau->getCustomFields()['ruhrcoder_color_picker_ral'] ?? null);
    }

    /**
     * Guard und Mehrfarbigkeit zusammen: Steht eine der beiden Positionen bereits korrekt,
     * wird nur die andere nachgetragen — die fertige Position wird nicht angefasst.
     */
    public function testGuardUeberspringtNurDieBereitsKorrektePosition(): void
    {
        $rot = new OrderLineItemEntity();
        $rot->setId('item-rot');
        $rot->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 3020',
            'rcColorPickerName' => 'Verkehrsrot',
            'rcColorPickerHex' => '#CC0605',
        ]);
        $rot->setCustomFields([
            'ruhrcoder_color_picker_ral' => 'RAL 3020',
            'ruhrcoder_color_picker_name' => 'Verkehrsrot',
            'ruhrcoder_color_picker_hex' => '#CC0605',
        ]);

        $blau = new OrderLineItemEntity();
        $blau->setId('item-blau');
        $blau->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 5010',
            'rcColorPickerName' => 'Enzianblau',
            'rcColorPickerHex' => '#0E294B',
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $updates): bool {
                self::assertCount(1, $updates, 'Nur die noch fehlende Position wird geschrieben.');
                self::assertSame('item-blau', $updates[0]['id']);

                return true;
            }), self::isInstanceOf(Context::class));

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$rot, $blau]));

        $subscriber->onOrderPlaced($this->createPlacedEvent($order));

        // Rot bleibt unverändert erhalten.
        self::assertSame('RAL 3020', $rot->getCustomFields()['ruhrcoder_color_picker_ral'] ?? null);
    }

    public function testIgnoriertNullLineItems(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $subscriber = new OrderColorSubscriber($repository, new NullLogger());

        $order = new OrderEntity();
        $order->setId('order-1');

        $event = $this->createPlacedEvent($order);

        $subscriber->onOrderPlaced($event);
    }

    public function testLoggtStrukturiertBeiRepositoryFehlerOhneZuWerfen(): void
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('item-1');
        $lineItem->setPayload([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 7016',
            'rcColorPickerName' => 'Anthrazitgrau',
            'rcColorPickerHex' => '#293133',
        ]);

        $repositoryException = new \RuntimeException('DAL write failed');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('update')->willThrowException($repositoryException);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Order-LineItem-Update fehlgeschlagen'),
                self::callback(function (array $context) use ($repositoryException): bool {
                    self::assertSame('ruhrcoder_color_picker.order_color_subscriber', $context['context']);
                    self::assertSame('order-1', $context['orderId']);
                    self::assertSame(1, $context['lineItemCount']);
                    self::assertSame($repositoryException::class, $context['exception']);
                    self::assertSame('DAL write failed', $context['message']);

                    return true;
                }),
            );

        $subscriber = new OrderColorSubscriber($repository, $logger);

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        $event = $this->createPlacedEvent($order);

        // Geschluckt, nicht geworfen: der bereits abgeschlossene Bestellprozess darf
        // wegen reiner Anzeige-Daten kein 500 bekommen. Das Log oben ist die Erwartung.
        $subscriber->onOrderPlaced($event);
    }
}
