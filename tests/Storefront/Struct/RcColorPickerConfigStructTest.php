<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Storefront\Struct;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Storefront\Struct\RcColorPickerConfigStruct;

#[CoversClass(RcColorPickerConfigStruct::class)]
final class RcColorPickerConfigStructTest extends TestCase
{
    public function testGetterGebenKorrekteWerteZurueck(): void
    {
        $colors = [
            ['ral' => 'RAL 9010', 'name' => 'Reinweiß', 'hex' => '#FFFFFF'],
            ['ral' => 'RAL 7016', 'name' => 'Anthrazitgrau', 'hex' => '#293133'],
        ];

        $struct = new RcColorPickerConfigStruct($colors, true, false, true);

        self::assertSame($colors, $struct->getStandardColors());
        self::assertTrue($struct->isAllowCustomRal());
        self::assertFalse($struct->isColorRequired());
        self::assertTrue($struct->isCustomRalLabelVisible());
    }

    public function testLeeresFarbenArray(): void
    {
        $struct = new RcColorPickerConfigStruct([], false, true);

        self::assertSame([], $struct->getStandardColors());
        self::assertFalse($struct->isAllowCustomRal());
        self::assertTrue($struct->isColorRequired());
    }

    public function testCustomRalLabelVisibleStandardmaessigFalse(): void
    {
        $struct = new RcColorPickerConfigStruct([], true, true);

        self::assertFalse($struct->isCustomRalLabelVisible());
    }
}
