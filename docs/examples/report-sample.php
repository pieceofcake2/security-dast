<?php
/**
 * The sample reports of the documentation, from scans of fictional applications recorded in `records/`:
 *
 *     php docs/examples/report-sample.php           # the PDFs of docs/public and the images of docs/img
 *     php docs/examples/report-sample.php --record  # scans again with ZAP in Docker (the Samples workflow)
 *
 * The baseline scan is of the fixture application of the tests (`tests/fixtures/app`), the full scan of
 * `docs/examples/app` through the proxy of ZAP, as End-to-End tests would do. `--record` writes the reports of ZAP
 * (`zap.json`, `rules.html`, `stats.json`) and the run (`run.json`) for each scan and language. Without it, the
 * reports are built from the records with the report of this version, and the images of their first pages are made
 * with `pdftoppm` (Poppler) and `magick` (ImageMagick).
 */
declare(strict_types=1);

use PieceOfCake2\Report\Environment;
use PieceOfCake2\Report\ReportWriter;
use PieceOfCake2\Report\Row;
use PieceOfCake2\SecurityDast\Config;
use PieceOfCake2\SecurityDast\Process;
use PieceOfCake2\SecurityDast\ReportBuilder;
use PieceOfCake2\SecurityDast\WebServer;
use PieceOfCake2\SecurityDast\Zap;
use PieceOfCake2\SecurityDast\ZapDaemon;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$records = __DIR__ . '/records';
// The version of ZAP of the samples
$image = 'ghcr.io/zaproxy/zaproxy:2.17.0';
$samples = [
    // name => scan, webroot, settings of the report, pages of the image
    'report-sample' => ['baseline', $root . '/tests/fixtures/app/webroot', [], [1, 2, 3, 4]],
    'report-sample-full' => ['full', __DIR__ . '/app/webroot', ['endToEnd' => 'vendor/bin/phpunit --testsuite EndToEnd', 'failOn' => 'high', 'scanMinutes' => 5], [1, 2, 3, 5]],
];
$files = ['zap.json', 'rules.html', 'stats.json'];

if (in_array('--record', $argv, true)) {
    foreach ($samples as [$scan, $webroot, $settings]) {
        foreach (['en', 'ja'] as $language) {
            $dir = $records . '/' . $scan . '-' . $language;
            is_dir($dir) || mkdir($dir, 0777, true);
            // Copies the reports of ZAP before the directory of the scan is removed
            $copy = static function (string $from) use ($dir, $files): void {
                foreach ($files as $file) {
                    if (is_file($from . '/' . $file)) {
                        copy($from . '/' . $file, $dir . '/' . $file);
                    }
                }
            };
            $scanDir = null;
            $run = static function (array $command, string $cwd, array $env = []) use ($copy, &$scanDir): array {
                $result = Process::run($command, $cwd, $env);
                if (str_contains($cwd, 'security-dast-')) {
                    $copy($cwd);
                } elseif (in_array('rm', $command, true) && $scanDir !== null) {
                    $copy($scanDir);
                }

                return $result;
            };
            $server = new WebServer($webroot, dirname($webroot));
            $url = $server->start();
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            try {
                $zap = new Zap(image: $image, run: $run, scan: $scan, scanMinutes: $settings['scanMinutes'] ?? 10);
                if ($scan === 'full') {
                    $daemon = static function (string $dir, array $options, array $env) use ($run, $image, &$scanDir): ZapDaemon {
                        $scanDir = $dir;

                        return new ZapDaemon($dir, $options, $image, run: $run, env: $env);
                    };
                    $result = $zap->scanScenario($url, static function (string $proxy, string $site): void {
                        // The requests of the End-to-End tests: the form, and a message sent with it
                        $context = static fn(array $http = []): mixed => stream_context_create(['http' => $http + ['proxy' => str_replace('http://', 'tcp://', $proxy), 'request_fulluri' => true, 'ignore_errors' => true]]);
                        file_get_contents($site . '/', false, $context());
                        $form = (string)file_get_contents($site . '/contacts/add', false, $context());
                        $token = preg_match('/name="data\[_Token\]\[key\]" value="([^"]*)"/', $form, $matches) ? $matches[1] : '';
                        $body = http_build_query(['data' => ['_Token' => ['key' => $token], 'Contact' => ['message' => 'Hello']]]);
                        file_get_contents($site . '/contacts/add', false, $context(['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body]));
                    }, $language, $daemon);
                } else {
                    $result = $zap->scan($url, $language);
                }
            } finally {
                $server->stop();
            }
            $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            file_put_contents($dir . '/run.json', json_encode([
                'url' => $url,
                'startedAt' => $startedAt->format('Y-m-d H:i:s'),
                'finishedAt' => $finishedAt->format('Y-m-d H:i:s'),
                'software' => 'PHP ' . PHP_VERSION . '　OWASP ZAP ' . $result->version . '　pieceofcake2/security-dast 1.x-dev',
                'alerts' => count($result->alerts),
                'rules' => count($result->rules ?? []),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            echo $dir . "\n";
        }
    }
    exit(0);
}

$magick = trim((string)shell_exec('command -v magick')) !== '' ? 'magick' : 'convert';
foreach ($samples as $name => [$scan, $webroot, $settings, $pages]) {
    foreach (['en' => '', 'ja' => '-ja'] as $language => $suffix) {
        $dir = $records . '/' . $scan . '-' . $language;
        $run = json_decode((string)file_get_contents($dir . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
        $json = static fn(string $file): array => is_file($dir . '/' . $file) ? (array)json_decode((string)file_get_contents($dir . '/' . $file), true, 512, JSON_THROW_ON_ERROR) : [];
        $data = $json('zap.json');
        // The site as ZAP saw it: the first site of the report
        $target = (string)($data['site'][0]['@name'] ?? $run['url']);
        $result = Zap::result($data, $run['url'], $target);
        $statistics = Zap::statistics($json('stats.json'));
        $result->rules = Zap::rules((string)file_get_contents($dir . '/rules.html'), $statistics);
        $config = new Config(
            scan: $scan,
            project: dirname($webroot),
            language: $language,
            endToEnd: $settings['endToEnd'] ?? null,
            failOn: $settings['failOn'] ?? 'medium',
            scanMinutes: $settings['scanMinutes'] ?? 10,
        );
        $result->activeLimitReached = $scan === 'full' && ($statistics['stats.ascan.time'] ?? 0) >= $config->scanMinutes * 60000;
        if ($config->endToEnd !== null) {
            $result->endToEndExitCode = 0;
        }
        $doc = (new ReportBuilder(ReportBuilder::translator($language)))->build(
            $result,
            $config,
            new Environment(),
            new DateTimeImmutable($run['startedAt'], new DateTimeZone('UTC')),
            new DateTimeImmutable($run['finishedAt'], new DateTimeZone('UTC')),
        );
        // The software of the recorded scan, not of this machine
        $last = count($doc->run) - 1;
        $doc->run[$last] = new Row($doc->run[$last]->label, $run['software']);

        $pdf = $root . '/docs/public/' . $name . $suffix . '.pdf';
        (new ReportWriter())->write($doc, $pdf);
        echo $pdf . "\n";

        $png = $root . '/docs/img/' . $name . $suffix . '.png';
        $tmp = sys_get_temp_dir() . '/report-sample-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        $images = [];
        foreach ($pages as $page) {
            exec(sprintf('pdftoppm -png -scale-to-x 595 -scale-to-y 842 -f %d -l %d %s %s', $page, $page, escapeshellarg($pdf), escapeshellarg($tmp . '/p' . $page)));
            $images = array_merge($images, glob($tmp . '/p' . $page . '*.png') ?: []);
        }
        // The pages side by side on a grey background: 595 x 842 (A4 at 72 dpi), 12px apart from the edges and 24px
        // from each other
        exec(sprintf('%s %s -bordercolor "#e9e9e9" -border 12 +append %s', $magick, implode(' ', array_map('escapeshellarg', $images)), escapeshellarg($png)));
        array_map('unlink', glob($tmp . '/*') ?: []);
        rmdir($tmp);
        echo $png . "\n";
    }
}
