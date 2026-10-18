<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use InvalidArgumentException;

/**
 * The settings file of the security checks of pieceofcake2: `config/security.php` of the project, or
 * `app/Config/security.php` of the legacy layout. It returns an array with a section for each check (`sca`,
 * `sast`, `dast`) and a `report` section that they share:
 *
 * ```php
 * return [
 *     'report' => ['language' => 'ja', 'dir' => 'tmp/security', 'project' => 'Acme'],
 *     'dast' => ['failOn' => 'medium', 'minutes' => 3],
 * ];
 * ```
 *
 * The keys of a section are the options of the command in camel case (`--fail-on` is `failOn`), with arrays for
 * the lists. The options of the command and the environment variables win over the file.
 */
final class Settings
{
    /**
     * The settings files, from the project, in order
     */
    public const FILES = ['config/security.php', 'app/Config/security.php'];

    /**
     * The options of the `report` section and the options they set
     */
    public const REPORT = ['language' => 'language', 'localeDir' => 'locale-dir', 'project' => 'project-name', 'note' => 'note'];

    /**
     * The options of a section of the settings file, as options of the command
     *
     * @param string $project Directory of the project
     * @param string $section Section, e.g. `dast`
     * @param string|null $file Settings file, default: the first of `FILES` that exists
     * @param string $pdf Name of the PDF in the `dir` of the `report` section
     * @return array<string, string|bool> Values by option name, e.g. `['fail-on' => 'medium']`
     * @throws \InvalidArgumentException When the file is given and missing, or does not return an array
     */
    public static function options(string $project, string $section, ?string $file = null, string $pdf = 'report.pdf'): array
    {
        if ($file === null) {
            foreach (self::FILES as $candidate) {
                if (is_file($project . DIRECTORY_SEPARATOR . $candidate)) {
                    $file = $project . DIRECTORY_SEPARATOR . $candidate;
                    break;
                }
            }
            if ($file === null) {
                return [];
            }
        } elseif (!is_file($file)) {
            throw new InvalidArgumentException(sprintf('The settings file %s is not found.', $file));
        }

        $settings = (static fn(string $path): mixed => include $path)($file);
        if (!is_array($settings)) {
            throw new InvalidArgumentException(sprintf('%s must return an array.', $file));
        }

        $options = [];
        $report = is_array($settings['report'] ?? null) ? $settings['report'] : [];
        foreach (self::REPORT as $key => $option) {
            // A null value is not set, e.g. getenv('...') ?: null
            if (isset($report[$key])) {
                $options[$option] = self::value($report[$key]);
            }
        }
        if (isset($report['dir']) && is_string($report['dir'])) {
            $options['pdf'] = rtrim($report['dir'], '/\\') . '/' . $pdf;
        }
        foreach (is_array($settings[$section] ?? null) ? $settings[$section] : [] as $key => $value) {
            // A null value is not set, e.g. getenv('...') ?: null
            if ($value === null) {
                continue;
            }
            $options[strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', (string)$key))] = self::value($value);
        }

        return $options;
    }

    /**
     * A value of the file as the value of an option: lists are comma separated, and an array with keys is JSON, e.g.
     * the alert IDs of `ignore` with their reasons
     *
     * @param mixed $value Value
     * @return string|bool
     */
    private static function value(mixed $value): string|bool
    {
        if (is_bool($value)) {
            return $value;
        }
        // Not an empty list; a list with gaps in its keys, e.g. of array_filter(), is JSON too, and the command takes
        // its values
        if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }

        return is_scalar($value) ? (string)$value : '';
    }
}
