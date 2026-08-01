<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Exception;

use RuntimeException;

/**
 * Basis-Exception für alle vom Plugin bewusst ausgelösten Fehler.
 * Named Constructors kapseln die Nachrichten für konsistentes Logging.
 *
 * Stabile Error-Codes für Observability/Monitoring — Codes sind Vertrag, keine Drift.
 */
final class RcColorPickerException extends RuntimeException
{
    public const CODE_CONTAINER_NOT_AVAILABLE = 1001;
    public const CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING = 1002;
    public const CODE_ORDER_LINE_ITEM_UPDATE_FAILED = 1003;

    public static function containerNotAvailable(): self
    {
        return new self(
            'Plugin-Container ist im aktuellen Lifecycle-Zustand nicht verfügbar.',
            self::CODE_CONTAINER_NOT_AVAILABLE
        );
    }

    public static function customFieldSetRepositoryMissing(): self
    {
        return new self(
            'Core-Service "custom_field_set.repository" ist nicht als EntityRepository verfügbar.',
            self::CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING
        );
    }

    public static function orderLineItemUpdateFailed(string $orderId, \Throwable $previous): self
    {
        return new self(
            sprintf('Update der Order-LineItem-CustomFields für Order "%s" fehlgeschlagen.', $orderId),
            self::CODE_ORDER_LINE_ITEM_UPDATE_FAILED,
            $previous
        );
    }
}
