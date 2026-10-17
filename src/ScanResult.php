<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

/**
 * The result of a scan: the alerts, the scanned site and the version of ZAP.
 */
final class ScanResult
{
    /**
     * Constructor
     *
     * @param array<\PieceOfCake2\SecurityDast\Alert> $alerts Alerts, from the highest risk
     * @param string $target URL of the scanned site
     * @param string|null $version Version of ZAP
     * @param array<string> $ignored Alert IDs left out of the report
     * @param string|null $user User that ZAP logged in as
     */
    public function __construct(
        public array $alerts = [],
        public string $target = '',
        public ?string $version = null,
        public array $ignored = [],
        public ?string $user = null,
    ) {
    }

    /**
     * Leaves alerts out: an ID matches itself and its variants, `10038` matches `10038-1`
     *
     * @param array<string> $ids Alert IDs
     * @return void
     */
    public function ignore(array $ids): void
    {
        $alerts = [];
        foreach ($this->alerts as $alert) {
            $base = explode('-', $alert->id)[0];
            if (in_array($alert->id, $ids, true) || in_array($base, $ids, true)) {
                $this->ignored[] = $alert->id;
            } else {
                $alerts[] = $alert;
            }
        }
        $this->alerts = $alerts;
    }

    /**
     * The number of the alerts of each risk, from the highest
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(Severity::ALL, 0);
        foreach ($this->alerts as $alert) {
            $counts[$alert->severity]++;
        }

        return $counts;
    }

    /**
     * Whether an alert has the risk of the threshold or higher
     *
     * @param string $threshold Lowest risk that counts, or `none`
     * @return bool
     */
    public function fails(string $threshold): bool
    {
        foreach ($this->alerts as $alert) {
            if (Severity::reaches($alert->severity, $threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The number of the distinct URLs of the alerts
     *
     * @return int
     */
    public function urls(): int
    {
        $urls = [];
        foreach ($this->alerts as $alert) {
            foreach ($alert->instances as $instance) {
                $urls[$instance['uri']] = true;
            }
        }

        return count($urls);
    }
}
