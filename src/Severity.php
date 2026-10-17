<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

/**
 * The risks of the alerts of ZAP, from the highest: `riskcode` 3 is `high`, 2 `medium`, 1 `low` and 0 `info`.
 */
final class Severity
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';
    public const INFO = 'info';

    /**
     * The risks from the highest
     */
    public const ALL = [self::HIGH, self::MEDIUM, self::LOW, self::INFO];

    /**
     * The risk of a `riskcode` of ZAP
     *
     * @param mixed $code `riskcode`
     * @return string
     */
    public static function fromRiskCode(mixed $code): string
    {
        return match ((int)$code) {
            3 => self::HIGH,
            2 => self::MEDIUM,
            1 => self::LOW,
            default => self::INFO,
        };
    }

    /**
     * Whether a risk is the threshold or higher
     *
     * @param string $severity Risk
     * @param string $threshold Lowest risk that counts, or `none`
     * @return bool
     */
    public static function reaches(string $severity, string $threshold): bool
    {
        if ($threshold === 'none') {
            return false;
        }
        $rank = array_search($severity, self::ALL, true);
        $limit = array_search($threshold, self::ALL, true);

        return $rank !== false && $limit !== false && $rank <= $limit;
    }
}
