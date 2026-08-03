<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Eine Position, für die eine Farbe Pflicht ist, hat keine.
 *
 * Der Fehler **blockiert die Bestellung**. Das ist bewusst härter als eine Warnung: Ohne Farbe
 * ist die Position nicht produzierbar, und eine Warnung würde im Bestellablauf überlesen. Der
 * Kunde bekäme sonst Ware in einer Farbe, die niemand gewählt hat.
 */
class MissingColorError extends Error
{
    private const KEY = 'rc-color-picker-missing-color';

    public function __construct(
        private readonly string $lineItemId,
        private readonly string $productName,
    ) {
        $this->message = sprintf('Für "%s" ist keine Farbe ausgewählt.', $productName);

        parent::__construct($this->message);
    }

    public function getParameters(): array
    {
        return ['name' => $this->productName];
    }

    /**
     * Je Position eine eigene Kennung.
     *
     * Ohne die Positions-Kennung würden mehrere farblose Artikel zu **einer** Meldung
     * zusammenfallen (`ErrorCollection` schlüsselt auf `getId()`) — der Kunde räumte den ersten
     * ab und stünde beim Absenden wieder vor derselben Meldung, ohne zu wissen, welcher Artikel
     * jetzt gemeint ist.
     */
    public function getId(): string
    {
        return self::KEY . '-' . $this->lineItemId;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }
}
