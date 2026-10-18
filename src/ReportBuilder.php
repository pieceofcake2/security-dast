<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use DateTimeImmutable;
use PieceOfCake2\Report\Count;
use PieceOfCake2\Report\Cover;
use PieceOfCake2\Report\Document;
use PieceOfCake2\Report\Environment;
use PieceOfCake2\Report\Group;
use PieceOfCake2\Report\Item;
use PieceOfCake2\Report\Row;
use PieceOfCake2\Report\Translator;
use PieceOfCake2\Report\Verdict;

/**
 * Builds the report of a scan: the alerts by risk, one page for each with the requests where it was found.
 */
final class ReportBuilder
{
    /**
     * The directory of the messages and the CSS of this package
     */
    public const RESOURCE_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'resources';

    /**
     * The most requests on the page of an alert
     */
    public const MAX_INSTANCES = 50;

    /**
     * Constructor
     *
     * @param \PieceOfCake2\Report\Translator $translator Messages
     */
    public function __construct(private Translator $translator)
    {
    }

    /**
     * The messages of the report in a language: those of pieceofcake2/report, of this package and of a directory
     *
     * @param string $language Language
     * @param string|null $localeDir Directory of more messages
     * @return \PieceOfCake2\Report\Translator
     */
    public static function translator(string $language, ?string $localeDir = null): Translator
    {
        return new Translator($language, [self::RESOURCE_DIR . DIRECTORY_SEPARATOR . 'locales', $localeDir]);
    }

    /**
     * The report of a scan
     *
     * @param \PieceOfCake2\SecurityDast\ScanResult $result Scan
     * @param \PieceOfCake2\SecurityDast\Config $config Settings
     * @param \PieceOfCake2\Report\Environment $environment Where it ran
     * @param \DateTimeImmutable $startedAt Start
     * @param \DateTimeImmutable $finishedAt End
     * @return \PieceOfCake2\Report\Document
     */
    public function build(ScanResult $result, Config $config, Environment $environment, DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt): Document
    {
        $cover = new Cover($this->translator);
        $title = $config->title ?? $this->t('title');
        $project = $config->projectName ?? self::projectName($config->project) ?? Cover::rootPackage();
        $passed = !$result->fails($config->failOn);

        $counts = [new Count(count($result->alerts), $this->t('count.alerts'))];
        foreach ($result->counts() as $severity => $count) {
            $counts[] = new Count($count, $this->t('severity.' . $severity), $count > 0 ? 't-' . $severity : '');
        }

        $target = $cover->target($environment);
        $target[] = new Row($this->t('field.url'), $result->target, monoValue: true);
        $target[] = new Row($this->t('field.server'), $config->url === null
            ? $this->t('server.builtin', ['env' => $config->appEnv])
            : $this->t('server.url'));
        $scan = $this->t(($config->endToEnd !== null ? 'scan.endToEnd.' : 'scan.') . ($config->scan === 'full' ? 'full' : 'baseline'), ['minutes' => $config->minutes, 'scanMinutes' => $config->scanMinutes]);
        if ($config->ajaxSpider && $config->endToEnd === null) {
            $scan .= $this->t('scan.ajax');
        }
        $target[] = new Row($this->t('field.scan'), $scan);
        if ($config->endToEnd !== null) {
            $failed = $result->endToEndExitCode !== null && $result->endToEndExitCode !== 0;
            $target[] = new Row($this->t('field.endToEnd'), $config->endToEnd, warning: $failed ? $this->t('endToEnd.failed', ['code' => (int)$result->endToEndExitCode]) : null, monoValue: true);
        }
        $login = $config->login();
        $target[] = new Row($this->t('field.login'), $login === null
            ? $this->t('login.none')
            : $this->t('login.user', ['user' => $result->user ?? $login->username, 'url' => $login->urlOn($result->target)]));
        $target[] = new Row($this->t('field.urls'), (string)$result->urls());
        if ($result->ignored !== []) {
            $target[] = new Row($this->t('field.ignored'), implode('　', $result->ignored), monoValue: true);
        }
        $exclude = $config->exclude ?? Zap::EXCLUDE;
        if ($exclude !== []) {
            $target[] = new Row($this->t('field.excluded'), implode('　', $exclude), monoValue: true);
        }
        $target[] = new Row($this->t('field.threshold'), $config->failOn === 'none'
            ? $this->t('threshold.none')
            : $this->t('threshold.value', ['severity' => $this->t('severity.' . $config->failOn)]));

        $software = ['PHP ' . PHP_VERSION, $result->version !== null ? 'OWASP ZAP ' . $result->version : null, Cover::package('pieceofcake2/security-dast')];

        $notes = [];
        if ($result->alerts === []) {
            $notes[] = $this->t($config->endToEnd !== null ? 'note.noneEndToEnd' : 'note.none');
        }
        $notes[] = $this->t($config->scan === 'full' ? 'note.active' : 'note.passive');
        if ($config->note !== null) {
            $notes[] = $config->note;
        }

        return new Document(
            title: $title,
            verdict: new Verdict($passed, $this->t($passed ? 'verdict.passed' : 'verdict.failed'), $counts),
            columns: [$this->t('field.no'), $this->t('field.alert'), $this->t('field.risk'), $this->t('field.count')],
            groups: $this->groups($result, $config->ignoreReasons),
            project: $project,
            target: $target,
            run: array_merge(
                $cover->run($startedAt, $finishedAt, (float)$finishedAt->format('U.u') - (float)$startedAt->format('U.u'), $environment),
                [new Row($this->t('field.software'), implode('　', array_filter($software)))],
            ),
            note: implode(' ', $notes),
            listTitle: $this->t('section.alerts'),
            targetTitle: $this->t('section.target'),
            runTitle: $this->t('section.run'),
            headerLeft: Cover::headerLeft($title, $project),
            headerRight: $cover->headerRight($startedAt, $environment),
            css: (string)file_get_contents(self::RESOURCE_DIR . DIRECTORY_SEPARATOR . 'dast.css'),
            language: $this->translator->language(),
        );
    }

    /**
     * The name of the package of a project, from its `composer.json`
     *
     * @param string $dir Directory of the project
     * @return string|null
     */
    public static function projectName(string $dir): ?string
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'composer.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $name = is_array($data) ? ($data['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The alerts by risk
     *
     * @param \PieceOfCake2\SecurityDast\ScanResult $result Scan
     * @param array<int|string, string> $ignoreReasons Why each alert ID is ignored
     * @return array<\PieceOfCake2\Report\Group>
     */
    private function groups(ScanResult $result, array $ignoreReasons = []): array
    {
        $groups = [];
        foreach (Severity::ALL as $severity) {
            $alerts = array_values(array_filter($result->alerts, static fn(Alert $alert): bool => $alert->severity === $severity));
            if ($alerts === []) {
                continue;
            }
            $group = new Group(count($groups) + 1, $this->t('severity.' . $severity));
            foreach ($alerts as $i => $alert) {
                $group->items[] = $this->alert($group->no . '-' . ($i + 1), $alert);
            }
            $groups[] = $group;
        }

        if ($result->ignoredAlerts !== []) {
            $group = new Group(count($groups) + 1, $this->t('group.ignored'));
            $group->items[] = new Item(
                $group->no . '-1',
                $this->t('ignored.title'),
                'ignored',
                $this->t('badge.ignored'),
                (string)count($result->ignoredAlerts),
                [new Row($this->t('field.ignored'), (string)count($result->ignoredAlerts))],
                $this->t('ignored.description'),
                body: $this->ignored($result->ignoredAlerts, $ignoreReasons),
            );
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * The table of the ignored alerts: their IDs, names, risks and URLs
     *
     * @param array<\PieceOfCake2\SecurityDast\Alert> $alerts Ignored alerts
     * @param array<int|string, string> $reasons Why each alert ID is ignored, e.g. for `10038` or `10038-1`
     * @return string HTML
     */
    private function ignored(array $alerts, array $reasons = []): string
    {
        $h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<table class="ignored-alerts"><thead><tr><th>' . $h($this->t('field.alertRef')) . '</th><th>' . $h($this->t('field.alert')) . '</th><th>'
            . $h($this->t('field.risk')) . '</th><th>' . $h($this->t('field.urls')) . '</th><th>' . $h($this->t('field.reason')) . '</th></tr></thead><tbody>';
        foreach ($alerts as $alert) {
            $urls = count(array_unique(array_column($alert->instances, 'uri')));
            $reason = $reasons[$alert->id] ?? $reasons[explode('-', $alert->id)[0]] ?? '-';
            $html .= '<tr><td class="mono">' . $h($alert->id) . '</td><td>' . $h($alert->name) . '</td><td>' . $h($this->t('severity.' . $alert->severity))
                . '</td><td class="num">' . $urls . '</td><td>' . $h($reason) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * The item of an alert
     *
     * @param string $no Number
     * @param \PieceOfCake2\SecurityDast\Alert $alert Alert
     * @return \PieceOfCake2\Report\Item
     */
    private function alert(string $no, Alert $alert): Item
    {
        $meta = [
            new Row($this->t('field.alertRef'), $alert->id, monoValue: true),
            new Row($this->t('field.risk'), $this->t('severity.' . $alert->severity)),
        ];
        if ($alert->confidence !== '') {
            $meta[] = new Row($this->t('field.confidence'), $this->t('confidence.' . strtolower(str_replace(' ', '-', $alert->confidence))));
        }
        if ($alert->cwe !== null) {
            $meta[] = new Row($this->t('field.cwe'), 'CWE-' . $alert->cwe);
        }
        if ($alert->wasc !== null) {
            $meta[] = new Row($this->t('field.wasc'), 'WASC-' . $alert->wasc);
        }
        $count = $this->t('count.instances', ['count' => count($alert->instances)]);
        $meta[] = new Row($this->t('field.count'), $count);

        return new Item(
            $no,
            $alert->name,
            $alert->severity,
            $this->t('severity.' . $alert->severity),
            $count,
            $meta,
            $alert->description !== '' ? $alert->description : null,
            body: $this->body($alert),
        );
    }

    /**
     * The solution, the requests and the links of an alert
     *
     * @param \PieceOfCake2\SecurityDast\Alert $alert Alert
     * @return string HTML
     */
    private function body(Alert $alert): string
    {
        $h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '';
        if ($alert->solution !== '') {
            $html .= '<h4>' . $h($this->t('section.solution')) . '</h4><p class="text">' . nl2br($h($alert->solution)) . '</p>';
        }

        if ($alert->instances !== []) {
            $html .= '<h4>' . $h($this->t('section.instances')) . '</h4><div class="instances">';
            foreach (array_slice($alert->instances, 0, self::MAX_INSTANCES) as $instance) {
                $html .= '<div class="instance"><div class="req">' . $this->request($instance['method'], $instance['uri']) . '</div>';
                $details = [
                    'param' => $instance['param'],
                    'attack' => $instance['attack'],
                    'evidence' => $instance['evidence'],
                    'otherinfo' => $instance['otherinfo'],
                ];
                $rows = '';
                foreach ($details as $key => $value) {
                    if ($value !== '') {
                        $rows .= '<tr><th>' . $h($this->t('instance.' . $key)) . '</th><td>' . nl2br($h(mb_strimwidth($value, 0, 600, '…'))) . '</td></tr>';
                    }
                }
                if ($rows !== '') {
                    $html .= '<table class="detail"><tbody>' . $rows . '</tbody></table>';
                }
                $html .= '</div>';
            }
            if (count($alert->instances) > self::MAX_INSTANCES) {
                $html .= '<p class="instance-more">' . $h($this->t('instances.more', ['count' => count($alert->instances) - self::MAX_INSTANCES])) . '</p>';
            }
            $html .= '</div>';
        }

        $links = $alert->references;
        if (preg_match('/\A(\d+)/', $alert->id, $matches)) {
            array_unshift($links, 'https://www.zaproxy.org/docs/alerts/' . $matches[1] . '/');
        }
        $links = array_values(array_unique($links));
        if ($links !== []) {
            $html .= '<h4>' . $h($this->t('section.references')) . '</h4><ul class="links">';
            foreach ($links as $link) {
                $html .= '<li><a href="' . $h($link) . '">' . $h($link) . '</a></li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * The method and the URL of a request: the method as a label, then the origin, light, and the path
     *
     * @param string $method Method
     * @param string $uri URL
     * @return string HTML
     */
    private function request(string $method, string $uri): string
    {
        $h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $origin = preg_match('#\A[a-z][a-z0-9+.-]*://[^/?\#]*#i', $uri, $matches) ? $matches[0] : '';
        $path = substr($uri, strlen($origin));

        return ($method !== '' ? '<span class="method ' . $h(strtolower($method)) . '">' . $h(strtoupper($method)) . '</span>' : '')
            . '<span class="uri"><span class="origin">' . $h($origin) . '</span><span class="path">' . $h($path !== '' ? $path : '/') . '</span></span>';
    }

    /**
     * A message
     *
     * @param string $key Key
     * @param array<string, string|int|float> $params Placeholders
     * @return string
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->translate($key, $params);
    }
}
