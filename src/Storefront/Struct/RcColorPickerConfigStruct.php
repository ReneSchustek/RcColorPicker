<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Storefront\Struct;

use Shopware\Core\Framework\Struct\Struct;

final class RcColorPickerConfigStruct extends Struct
{
    /**
     * @param list<array{ral: string, name: string, hex: string}> $standardColors
     */
    public function __construct(
        private readonly array $standardColors,
        private readonly bool $allowCustomRal,
        private readonly bool $colorRequired,
        private readonly bool $customRalLabelVisible = false,
    ) {
    }

    /** @return list<array{ral: string, name: string, hex: string}> */
    public function getStandardColors(): array
    {
        return $this->standardColors;
    }

    public function isAllowCustomRal(): bool
    {
        return $this->allowCustomRal;
    }

    public function isColorRequired(): bool
    {
        return $this->colorRequired;
    }

    public function isCustomRalLabelVisible(): bool
    {
        return $this->customRalLabelVisible;
    }
}
