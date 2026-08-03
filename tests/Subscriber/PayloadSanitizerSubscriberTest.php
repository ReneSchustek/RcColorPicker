<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Subscriber\PayloadSanitizerSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(PayloadSanitizerSubscriber::class)]
final class PayloadSanitizerSubscriberTest extends TestCase
{
    private PayloadSanitizerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new PayloadSanitizerSubscriber();
    }

    public function testSubscribedEvents(): void
    {
        $events = PayloadSanitizerSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertSame('sanitizePayload', $events[KernelEvents::REQUEST]);
    }

    public function testStripsHtmlTags(): void
    {
        $event = $this->createCheckoutEvent([
            'item1' => [
                'payload' => [
                    'rcColorPickerRal' => '<script>alert("xss")</script>RAL 7016',
                    'rcColorPickerName' => '<b>Anthrazitgrau</b>',
                    'rcColorPickerHex' => '#293133',
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('alert("xss")RAL 7016', $lineItems['item1']['payload']['rcColorPickerRal']);
        self::assertSame('Anthrazitgrau', $lineItems['item1']['payload']['rcColorPickerName']);
    }

    public function testLeavesSpecialCharactersUnescaped(): void
    {
        $event = $this->createCheckoutEvent([
            'item1' => [
                'payload' => [
                    'rcColorPickerRal' => 'RAL "7016" & Co',
                    'rcColorPickerName' => "Name's <Test>",
                    'rcColorPickerHex' => '#293133',
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('RAL "7016" & Co', $lineItems['item1']['payload']['rcColorPickerRal']);
        self::assertSame("Name's", $lineItems['item1']['payload']['rcColorPickerName']);
    }

    public function testTrimsWhitespace(): void
    {
        $event = $this->createCheckoutEvent([
            'item1' => [
                'payload' => [
                    'rcColorPickerRal' => '  RAL 7016  ',
                    'rcColorPickerName' => '',
                    'rcColorPickerHex' => '',
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('RAL 7016', $lineItems['item1']['payload']['rcColorPickerRal']);
    }

    public function testIgnoresEmptyLineItems(): void
    {
        $event = $this->createCheckoutEvent([]);

        $this->subscriber->sanitizePayload($event);

        self::assertSame([], $event->getRequest()->request->all('lineItems'));
    }

    public function testIgnoresItemsWithoutPayload(): void
    {
        $event = $this->createCheckoutEvent([
            'item1' => ['quantity' => 1],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertArrayNotHasKey('payload', $lineItems['item1']);
    }

    public function testIgnoresSubRequests(): void
    {
        $request = new Request([], ['lineItems' => [
            'item1' => [
                'payload' => ['rcColorPickerRal' => '<script>xss</script>'],
            ],
        ]]);
        $request->attributes->set('_route', 'frontend.checkout.line-item.add');

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('<script>xss</script>', $lineItems['item1']['payload']['rcColorPickerRal']);
    }

    public function testAppliesToStoreApiRoutesToo(): void
    {
        $request = new Request([], ['lineItems' => [
            'item1' => [
                'payload' => [
                    'rcColorPickerRal' => '<script>alert("xss")</script>RAL 7016',
                    'rcColorPickerName' => '<b>Anthrazitgrau</b>',
                    'rcColorPickerHex' => '#293133',
                ],
            ],
        ]]);
        $request->attributes->set('_route', 'store-api.checkout.cart.line-item');

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('alert("xss")RAL 7016', $lineItems['item1']['payload']['rcColorPickerRal']);
        self::assertSame('Anthrazitgrau', $lineItems['item1']['payload']['rcColorPickerName']);
    }

    public function testIgnoresNonCheckoutRoutes(): void
    {
        $request = new Request([], ['lineItems' => [
            'item1' => [
                'payload' => ['rcColorPickerRal' => '<script>xss</script>'],
            ],
        ]]);
        $request->attributes->set('_route', 'frontend.detail.page');

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('<script>xss</script>', $lineItems['item1']['payload']['rcColorPickerRal']);
    }

    public function testIgnoresNonStringValues(): void
    {
        $event = $this->createCheckoutEvent([
            'item1' => [
                'payload' => [
                    'rcColorPickerRal' => 12345,
                    'rcColorPickerName' => null,
                    'rcColorPickerHex' => true,
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame(12345, $lineItems['item1']['payload']['rcColorPickerRal']);
    }

    /**
     * @param array<string, mixed> $lineItems
     */
    private function createCheckoutEvent(array $lineItems): RequestEvent
    {
        $request = new Request([], ['lineItems' => $lineItems]);
        $request->attributes->set('_route', 'frontend.checkout.line-item.add');

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /**
     * Was: Ein Store-API-Aufruf mit dem Parameter `items`.
     * Warum: **Das war die Lücke.** Die Storefront schickt `lineItems`, die Store-API `items`
     *        (`CartItemAddRoute.php:57`). Der Abonnent las nur `lineItems` — und der
     *        Klassenkommentar behauptete trotzdem, Headless- und PWA-Clients seien abgedeckt.
     *        Ein Hex-Wert wie `red;background-image:url(…)` landete damit ungeprüft in
     *        `order_line_item.payload` und von dort in ein `style`-Attribut.
     * Erwartet: Der Wert ist verworfen.
     */
    public function testStoreApiItemsAreSanitizedAsWell(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'store-api.checkout.cart.line-item.add');
        $request->request->set('items', [
            ['payload' => ['rcColorPickerHex' => 'red;background-image:url(https://example.invalid/x)']],
        ]);

        $this->subscriber->sanitizePayload(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        $items = $request->request->all('items');
        self::assertSame('', $items[0]['payload']['rcColorPickerHex']);
    }

    /**
     * Was: Markup im Freitext eines Store-API-Aufrufs.
     * Warum: Derselbe Weg, anderes Feld — der RAL-Text steht im Warenkorb und in der Mail.
     * Erwartet: Das Markup ist entfernt.
     */
    public function testStoreApiTextFieldsAreStrippedAsWell(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'store-api.checkout.cart.line-item.update');
        $request->request->set('items', [
            ['payload' => ['rcColorPickerName' => '<script>alert(1)</script>Reinweiß']],
        ]);

        $this->subscriber->sanitizePayload(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        $items = $request->request->all('items');
        self::assertSame('alert(1)Reinweiß', $items[0]['payload']['rcColorPickerName']);
    }

    /**
     * Was: Beide Parameter in einem Aufruf.
     * Warum: Die Umstellung darf den bisherigen Weg nicht verlieren. Ein Test nur auf `items`
     *        hätte einen Fehler in `lineItems` nicht bemerkt.
     * Erwartet: beide bereinigt.
     */
    public function testBothParameterNamesAreHandled(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'frontend.checkout.line-item.add');
        $request->request->set('lineItems', [
            'p1' => ['payload' => ['rcColorPickerHex' => 'javascript:x']],
        ]);
        $request->request->set('items', [
            ['payload' => ['rcColorPickerHex' => 'javascript:x']],
        ]);

        $this->subscriber->sanitizePayload(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertSame('', $request->request->all('lineItems')['p1']['payload']['rcColorPickerHex']);
        self::assertSame('', $request->request->all('items')[0]['payload']['rcColorPickerHex']);
    }
}
