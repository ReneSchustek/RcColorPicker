<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Exception\RcColorPickerException;

/**
 * Pinning-Tests gegen die stabilen Error-Codes — Logging/Monitoring referenziert diese als Vertrag.
 * Drift bei Konstanten-Werten bricht den Test, statt stillschweigend Alerts kaputt zu machen.
 */
#[CoversClass(RcColorPickerException::class)]
final class RcColorPickerExceptionTest extends TestCase
{
    public function testCodeConstantsBleibenStabil(): void
    {
        self::assertSame(1001, RcColorPickerException::CODE_CONTAINER_NOT_AVAILABLE);
        self::assertSame(1002, RcColorPickerException::CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING);
        self::assertSame(1003, RcColorPickerException::CODE_ORDER_LINE_ITEM_UPDATE_FAILED);
    }

    public function testContainerNotAvailableExceptionTraegtCode(): void
    {
        $exception = RcColorPickerException::containerNotAvailable();

        self::assertSame(RcColorPickerException::CODE_CONTAINER_NOT_AVAILABLE, $exception->getCode());
        self::assertNotSame('', $exception->getMessage());
    }

    public function testCustomFieldSetRepositoryMissingExceptionTraegtCode(): void
    {
        $exception = RcColorPickerException::customFieldSetRepositoryMissing();

        self::assertSame(RcColorPickerException::CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING, $exception->getCode());
    }

    public function testOrderLineItemUpdateFailedExceptionWrappsPrevious(): void
    {
        $original = new \RuntimeException('DAL exploded');
        $exception = RcColorPickerException::orderLineItemUpdateFailed('order-abc-123', $original);

        self::assertSame(RcColorPickerException::CODE_ORDER_LINE_ITEM_UPDATE_FAILED, $exception->getCode());
        self::assertSame($original, $exception->getPrevious());
        self::assertStringContainsString('order-abc-123', $exception->getMessage());
    }

    public function testCodesSindEindeutig(): void
    {
        $codes = [
            RcColorPickerException::CODE_CONTAINER_NOT_AVAILABLE,
            RcColorPickerException::CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING,
            RcColorPickerException::CODE_ORDER_LINE_ITEM_UPDATE_FAILED,
        ];

        self::assertSame($codes, array_unique($codes), 'Alle Error-Codes muessen eindeutig sein');
    }
}
