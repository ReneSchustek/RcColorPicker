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

    public function testEntferntHtmlTags(): void
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

    public function testBelaesstSonderzeichenUngeescaped(): void
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

    public function testTrimtWhitespace(): void
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

    public function testIgnoriertLeereLineItems(): void
    {
        $event = $this->createCheckoutEvent([]);

        $this->subscriber->sanitizePayload($event);

        self::assertSame([], $event->getRequest()->request->all('lineItems'));
    }

    public function testIgnoriertItemsOhnePayload(): void
    {
        $event = $this->createCheckoutEvent([
            'item1' => ['quantity' => 1],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertArrayNotHasKey('payload', $lineItems['item1']);
    }

    public function testIgnoriertSubRequests(): void
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

    public function testGreiftAufStoreApiRoutenZu(): void
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

    public function testIgnoriertNichtCheckoutRouten(): void
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

    public function testIgnoriertNichtStringWerte(): void
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
}
