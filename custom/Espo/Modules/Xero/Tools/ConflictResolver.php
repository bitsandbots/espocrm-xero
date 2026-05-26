<?php

namespace Espo\Modules\Xero\Tools;

use Espo\ORM\Entity;

/**
 * Determines which side wins a bidirectional sync conflict.
 *
 * Strategy: last-modified-wins. If Xero was updated after our last sync,
 * Xero wins. Otherwise EspoCRM wins (push to Xero).
 *
 * Xero timestamps use the /Date(ms+offset)/ format; this resolver handles
 * both that format and standard ISO 8601 strings.
 */
class ConflictResolver
{
    public const WINNER_XERO = 'xero';
    public const WINNER_ESPO = 'espo';
    public const WINNER_NONE = 'none';

    /**
     * @param string|null $xeroLastUpdated Xero UpdatedDateUTC field value
     * @param string|null $espoSyncedAt    EspoCRM xeroSyncedAt field value
     */
    public function resolve(?string $xeroLastUpdated, ?string $espoSyncedAt): string
    {
        if (!$xeroLastUpdated && !$espoSyncedAt) {
            return self::WINNER_NONE;
        }

        if (!$xeroLastUpdated) {
            return self::WINNER_ESPO;
        }

        if (!$espoSyncedAt) {
            return self::WINNER_XERO;
        }

        $xeroTs = $this->parseXeroDate($xeroLastUpdated);
        $espoTs = strtotime($espoSyncedAt);

        if ($xeroTs === null || $espoTs === false) {
            return self::WINNER_NONE;
        }

        return $xeroTs > $espoTs ? self::WINNER_XERO : self::WINNER_ESPO;
    }

    /**
     * Returns true if Xero is newer than the last EspoCRM sync.
     */
    public function isXeroNewer(?string $xeroLastUpdated, ?string $espoSyncedAt): bool
    {
        return $this->resolve($xeroLastUpdated, $espoSyncedAt) === self::WINNER_XERO;
    }

    /**
     * Parses a Xero date string — handles both /Date(ms+offset)/ and ISO 8601.
     */
    private function parseXeroDate(?string $xeroDate): ?int
    {
        if ($xeroDate === null) {
            return null;
        }

        if (preg_match('/\/Date\((-?\d+)/', $xeroDate, $m)) {
            return (int) round((int) $m[1] / 1000);
        }

        $ts = strtotime($xeroDate);

        return $ts === false ? null : $ts;
    }
}
