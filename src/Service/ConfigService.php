<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class ConfigService
{
    private const PLUGIN_CONFIG_KEY = 'RcColorPicker.config';

    // Schutz gegen DOM-Bloat bei fehlerhafter Vendor-Konfiguration —
    // 8 Farben sind Default, 80 deckt jeden realen RAL-Auszug ab.
    public const MAX_STANDARD_COLORS = 80;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isCustomRalAllowed(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.allowCustomRal', true, $salesChannelId);
    }

    public function isColorRequired(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.colorRequired', true, $salesChannelId);
    }

    public function isCustomRalLabelVisible(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.customRalLabelVisible', false, $salesChannelId);
    }

    /**
     * Parst die Standard-Farben aus der Konfiguration.
     * Format pro Zeile: RAL-Code;Farbname;Hex-Code
     *
     * @return list<array{ral: string, name: string, hex: string}>
     */
    public function getStandardColors(?string $salesChannelId = null): array
    {
        $raw = (string) $this->get('.standardColors', '', $salesChannelId);

        if ($raw === '') {
            return [];
        }

        $colors = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode(';', $line));

            if (\count($parts) < 3) {
                continue;
            }

            // Ungueltige Hex-Werte verwerfen — sonst landet "background-color: notahex" im DOM.
            if (ColorValidator::sanitizeHex($parts[2]) === '') {
                continue;
            }

            $colors[] = [
                'ral' => $parts[0],
                'name' => $parts[1],
                'hex' => $parts[2],
            ];

            if (\count($colors) >= self::MAX_STANDARD_COLORS) {
                break;
            }
        }

        return $colors;
    }

    /**
     * Bewusst ohne eigenen Cache: Der Core memoisiert die Konfiguration bereits pro Request
     * (MemoizedSystemConfigStore) und verwirft sie bei `SystemConfigChangedEvent` sowie bei
     * `kernel.reset`. Ein zweiter Cache hier hätte beide Invalidierungen nicht mitbekommen und
     * im Worker-Mode veraltete Werte ausgeliefert.
     */
    private function get(string $keySuffix, mixed $default, ?string $salesChannelId = null): mixed
    {
        return $this->systemConfigService->get(self::PLUGIN_CONFIG_KEY . $keySuffix, $salesChannelId) ?? $default;
    }
}
