<?php

namespace Plugins\ExpenseClaims\Services;

use Plugins\ExpenseClaims\Models\VehicleRate;

/**
 * Kilometric rates per vehicle type and validity period.
 */
final class Rates
{
    /**
     * Seeded for a new organisation: the reference rate of the salary
     * certificate guidance (FTA), CHF 0.70/km until 31.12.2025 and
     * CHF 0.75/km from 1.1.2026.
     */
    private const DEFAULTS = [
        ['vehicle_type' => 'car', 'valid_from' => '2000-01-01', 'valid_to' => '2025-12-31', 'rate_per_km' => '0.7000', 'note' => 'FTA reference rate'],
        ['vehicle_type' => 'car', 'valid_from' => '2026-01-01', 'valid_to' => null, 'rate_per_km' => '0.7500', 'note' => 'FTA reference rate from 1.1.2026'],
    ];

    public function ensureDefaults(string $organizationId): void
    {
        if (VehicleRate::withoutGlobalScopes()->where('organization_id', $organizationId)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $rate) {
            VehicleRate::withoutGlobalScopes()->create($rate + ['organization_id' => $organizationId]);
        }
    }

    /**
     * @throws \DomainException When no rate covers the date
     */
    public function rateFor(string $organizationId, string $vehicleType, string $date): string
    {
        $this->ensureDefaults($organizationId);

        $rate = VehicleRate::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('vehicle_type', $vehicleType)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('valid_from')
            ->first();

        if ($rate === null) {
            throw new \DomainException(__('expense-claims::ec.no_rate', ['type' => $vehicleType, 'date' => $date]));
        }

        return (string) $rate->rate_per_km;
    }

    /**
     * km × rate, rounded to 5 centimes (half up).
     */
    public static function kmAmount(string $km, string $rate): string
    {
        /** @var numeric-string $km */
        /** @var numeric-string $rate */
        $twentieths = bcadd(bcmul(bcmul($km, $rate, 6), '20', 6), '0.5', 0);

        return bcdiv($twentieths, '20', 2);
    }
}
