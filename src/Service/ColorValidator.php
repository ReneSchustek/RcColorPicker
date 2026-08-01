<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Service;

/**
 * Zentrale, serverseitige Validierung der Farb-Eingaben. Der Hex-Wert landet in
 * Inline-Styles (Warenkorb UND Bestellbestätigungs-Mail); ein nicht
 * validierter Wert erlaubt CSS-Injection (z. B. `red;background:url(//evil)`),
 * weil `|e('html_attr')` im `style`-Attribut KEIN CSS escaped. Darum wird Hex an
 * jeder Eingangskante strikt gegen das Muster geprüft und sonst geleert.
 */
final class ColorValidator
{
    /** #rgb, #rgba, #rrggbb, #rrggbbaa */
    public const HEX_PATTERN = '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{1}|[0-9a-fA-F]{3}|[0-9a-fA-F]{5})?$/';

    /** Obergrenze für RAL-/Namens-Text, damit `order_line_item.custom_fields` nicht aufgebläht wird. */
    public const MAX_TEXT_LENGTH = 64;

    private function __construct()
    {
    }

    /**
     * Gibt den Hex-Wert zurück, wenn er dem strikten Muster entspricht — sonst
     * einen leeren String (kein ungültiger Wert wird je gespeichert/gerendert).
     */
    public static function sanitizeHex(string $value): string
    {
        return preg_match(self::HEX_PATTERN, $value) === 1 ? $value : '';
    }

    /**
     * Entfernt Markup und begrenzt die Länge von Freitext-Feldern (RAL, Name).
     */
    public static function sanitizeText(string $value): string
    {
        $clean = trim(strip_tags($value));

        return mb_substr($clean, 0, self::MAX_TEXT_LENGTH);
    }
}
