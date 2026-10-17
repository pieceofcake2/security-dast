<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

/**
 * An alert of ZAP: a kind of issue, and the requests where it was found.
 */
final class Alert
{
    /**
     * The confidences of ZAP by `confidence` code
     */
    public const CONFIDENCES = ['False Positive', 'Low', 'Medium', 'High', 'Confirmed'];

    /**
     * Constructor
     *
     * @param string $id Alert reference of ZAP, e.g. `10038-1`
     * @param string $name Name
     * @param string $severity Risk (`Severity`)
     * @param string $confidence Confidence: `Low`, `Medium`, `High` or `Confirmed`
     * @param string $description Description, as text
     * @param string $solution Solution, as text
     * @param array<string> $references Links
     * @param int|null $cwe CWE ID
     * @param int|null $wasc WASC ID
     * @param array<array{method: string, uri: string, param: string, attack: string, evidence: string, otherinfo: string}> $instances Requests where it was found
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $severity,
        public string $confidence = '',
        public string $description = '',
        public string $solution = '',
        public array $references = [],
        public ?int $cwe = null,
        public ?int $wasc = null,
        public array $instances = [],
    ) {
    }
}
