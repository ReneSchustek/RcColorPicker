<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Integration\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use Ruhrcoder\RcColorPicker\Subscriber\OrderColorSubscriber;
use Ruhrcoder\RcColorPicker\Tests\Integration\IntegrationTestBase;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(OrderColorSubscriber::class)]
final class OrderColorSubscriberIntegrationTest extends IntegrationTestBase
{
    public function testSubscriberIsResolvedByTheContainer(): void
    {
        $subscriber = static::getContainer()->get(OrderColorSubscriber::class);

        self::assertInstanceOf(OrderColorSubscriber::class, $subscriber);
    }

    public function testSubscriberIsRegisteredForTheCheckoutOrderPlacedEventWithPriorityMinus500(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');

        $matchingPriorities = [];
        foreach ($dispatcher->getListeners(CheckoutOrderPlacedEvent::class) as $listener) {
            if (!\is_array($listener) || !isset($listener[0])) {
                continue;
            }

            if ($listener[0] instanceof OrderColorSubscriber && ($listener[1] ?? null) === 'onOrderPlaced') {
                // Aus dem rohen Listener-Eintrag ein sauber typisiertes Callable bauen:
                // getListeners() liefert ein gemischtes Array, die Zuordnung ist oben geprüft.
                $matchingPriorities[] = $dispatcher->getListenerPriority(
                    CheckoutOrderPlacedEvent::class,
                    [$listener[0], 'onOrderPlaced'],
                );
            }
        }

        self::assertCount(1, $matchingPriorities, 'OrderColorSubscriber muss genau einmal für CheckoutOrderPlacedEvent registriert sein.');
        self::assertSame(-500, $matchingPriorities[0]);
    }
}
