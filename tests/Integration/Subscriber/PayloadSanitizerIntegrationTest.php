<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Integration\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use Ruhrcoder\RcColorPicker\Subscriber\PayloadSanitizerSubscriber;
use Ruhrcoder\RcColorPicker\Tests\Integration\IntegrationTestBase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(PayloadSanitizerSubscriber::class)]
final class PayloadSanitizerIntegrationTest extends IntegrationTestBase
{
    public function testSubscriberIsRegisteredOnTheKernelRequestEvent(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');

        $found = false;
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            if (\is_array($listener) && ($listener[0] ?? null) instanceof PayloadSanitizerSubscriber) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'PayloadSanitizerSubscriber muss am KernelEvents::REQUEST registriert sein.');
    }

    public function testSanitizerStripsScriptTagsOnStoreApiRoute(): void
    {
        /** @var PayloadSanitizerSubscriber $subscriber */
        $subscriber = static::getContainer()->get(PayloadSanitizerSubscriber::class);

        $request = new Request([], ['lineItems' => [
            'item-1' => [
                'payload' => [
                    'rcColorPickerRal' => '<script>alert("xss")</script>RAL 7016',
                    'rcColorPickerName' => '<b>Anthrazitgrau</b>',
                    'rcColorPickerHex' => '#293133',
                ],
            ],
        ]]);
        $request->attributes->set('_route', 'store-api.checkout.cart.line-item');

        $event = new RequestEvent(
            static::getKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        $subscriber->sanitizePayload($event);

        $lineItems = $request->request->all('lineItems');
        self::assertSame('alert("xss")RAL 7016', $lineItems['item-1']['payload']['rcColorPickerRal']);
        self::assertSame('Anthrazitgrau', $lineItems['item-1']['payload']['rcColorPickerName']);
        self::assertSame('#293133', $lineItems['item-1']['payload']['rcColorPickerHex']);
    }
}
