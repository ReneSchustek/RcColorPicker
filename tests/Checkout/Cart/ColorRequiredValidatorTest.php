<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Checkout\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Checkout\Cart\ColorRequiredValidator;
use Ruhrcoder\RcColorPicker\Checkout\Cart\MissingColorError;
use Ruhrcoder\RcColorPicker\Service\ConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Die Farbpflicht auf dem Server.
 *
 * Bis zum 2026-08-03 lag sie allein im JavaScript — und griff selbst dort nicht: Shopwares
 * `AddToCart` hängt am selben Formular und wird zuerst angemeldet, hatte den Artikel also bereits
 * per Hintergrundanfrage im Warenkorb, bevor die Prüfung überhaupt lief. Ein Headless-Client
 * umging sie ohnehin.
 *
 * Diese Tests prüfen die einzige Stelle, an der sich die Zusage einlösen lässt.
 */
#[CoversClass(ColorRequiredValidator::class)]
final class ColorRequiredValidatorTest extends TestCase
{
    /**
     * Was: Pflichtfarbe an, Artikel ohne Farbe.
     * Warum: Der Kernfall. Ohne diesen Fehler geht die Bestellung durch, und in der Fertigung
     *        steht ein Artikel ohne Farbangabe.
     * Erwartet: ein Fehler, der die Bestellung blockiert.
     */
    public function testAnItemWithoutColorBlocksTheOrder(): void
    {
        $cart = $this->buildCart([$this->buildLineItem(['rcColorPickerActive' => '1'])]);
        $errors = new ErrorCollection();

        $this->buildValidator(true)->validate($cart, $errors, $this->buildContext());

        self::assertCount(1, $errors);
        self::assertInstanceOf(MissingColorError::class, $errors->first());
        self::assertTrue($errors->blockOrder());
    }

    /**
     * Was: Pflichtfarbe an, Farbe vorhanden.
     * Warum: Die Gegenprobe. Ein Prüfer, der immer meldet, wird abgeschaltet.
     * Erwartet: kein Fehler.
     */
    public function testAnItemWithColorPassesThrough(): void
    {
        $cart = $this->buildCart([$this->buildLineItem([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => 'RAL 9010',
            'rcColorPickerHex' => '#FFFFFF',
        ])]);
        $errors = new ErrorCollection();

        $this->buildValidator(true)->validate($cart, $errors, $this->buildContext());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Die Kennzeichnung steht, der Hex-Wert ist leer.
     * Warum: Genau so sieht eine Position aus, deren ungültiger Wert von der Eingabeprüfung
     *        verworfen wurde. Auf das Vorhandensein des Schlüssels zu prüfen ließe sie durch —
     *        eine Position, die vorgibt, eine Farbe zu haben, aber keine nennt.
     * Erwartet: blockiert.
     */
    public function testAnEmptyColorCountsAsMissing(): void
    {
        $cart = $this->buildCart([$this->buildLineItem([
            'rcColorPickerActive' => '1',
            'rcColorPickerRal' => '',
            'rcColorPickerHex' => '',
        ])]);
        $errors = new ErrorCollection();

        $this->buildValidator(true)->validate($cart, $errors, $this->buildContext());

        self::assertCount(1, $errors);
    }

    /**
     * Was: Pflichtfarbe aus.
     * Warum: Der Schalter muss wirken. Sonst blockierte der Prüfer jeden Shop, der die Farbe
     *        freiwillig anbietet.
     * Erwartet: kein Fehler.
     */
    public function testNothingIsBlockedWhenTheColorIsOptional(): void
    {
        $cart = $this->buildCart([$this->buildLineItem(['rcColorPickerActive' => '1'])]);
        $errors = new ErrorCollection();

        $this->buildValidator(false)->validate($cart, $errors, $this->buildContext());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Ein Artikel ohne Farbauswahl.
     * Warum: Der Schalter macht die Farbe nicht für das ganze Sortiment zur Pflicht, sondern für
     *        die Artikel, an denen die Auswahl überhaupt angeboten wird. Griffe der Prüfer weiter,
     *        ließe sich in einem solchen Shop nichts mehr bestellen.
     * Erwartet: kein Fehler.
     */
    public function testItemsWithoutColorSelectionAreNotAffected(): void
    {
        $cart = $this->buildCart([$this->buildLineItem([])]);
        $errors = new ErrorCollection();

        $this->buildValidator(true)->validate($cart, $errors, $this->buildContext());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Der Artikel trägt das Zusatzfeld, aber keine Kennzeichnung in der Nutzlast.
     * Warum: So liegt ein Artikel im Warenkorb, der vor dem Einschalten der Farbauswahl
     *        hineingelegt wurde. Er ist genauso wenig produzierbar wie ein neuer.
     * Erwartet: blockiert.
     */
    public function testTheCustomFieldAloneAlsoTriggersTheRequirement(): void
    {
        $cart = $this->buildCart([$this->buildLineItem([
            'customFields' => ['ruhrcoder_color_picker_enabled' => true],
        ])]);
        $errors = new ErrorCollection();

        $this->buildValidator(true)->validate($cart, $errors, $this->buildContext());

        self::assertCount(1, $errors);
    }

    /**
     * Was: Zwei farblose Artikel.
     * Warum: `ErrorCollection` schlüsselt auf `getId()`. Ohne die Positions-Kennung fielen beide
     *        zu einer Meldung zusammen — der Kunde räumte den ersten ab und stünde vor derselben
     *        Meldung, ohne zu wissen, welcher Artikel jetzt gemeint ist.
     * Erwartet: zwei Meldungen.
     */
    public function testEachItemGetsItsOwnMessage(): void
    {
        $cart = $this->buildCart([
            $this->buildLineItem(['rcColorPickerActive' => '1'], 'li-1'),
            $this->buildLineItem(['rcColorPickerActive' => '1'], 'li-2'),
        ]);
        $errors = new ErrorCollection();

        $this->buildValidator(true)->validate($cart, $errors, $this->buildContext());

        self::assertCount(2, $errors);
    }

    private function buildValidator(bool $required): ColorRequiredValidator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'RcColorPicker.config.colorRequired' ? $required : null,
        );

        return new ColorRequiredValidator(new ConfigService($systemConfig));
    }

    /** @param array<int, LineItem> $lineItems */
    private function buildCart(array $lineItems): Cart
    {
        $cart = new Cart('test');
        foreach ($lineItems as $lineItem) {
            $cart->add($lineItem);
        }

        return $cart;
    }

    /** @param array<string, mixed> $payload */
    private function buildLineItem(array $payload, string $id = 'li-1'): LineItem
    {
        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-1', 1);
        $lineItem->setLabel('Edelstahlpfosten');
        $lineItem->setPayload($payload);

        return $lineItem;
    }

    private function buildContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return $context;
    }
}
