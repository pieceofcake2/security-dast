# Security DAST Documentation

Dynamic application security testing (DAST) of a CakePHP 2 application: the application is run and scanned by
[OWASP ZAP](https://www.zaproxy.org/), and the alerts are written as an A4 PDF report by risk, in the design of
the reports of [pieceofcake2/report](https://pieceofcake2.github.io/report/). The exit code fails CI when an
alert reaches a risk.

- [Scans](scans.md): what the baseline and the full scans do, and which to run where
- [Scanning as a Logged-in User](login.md)
- [End-to-End Tests as the Scenario](end-to-end.md)
- [Configuration](configuration.md): the options, the environment variables and the settings file
- [The Report](report.md): reading it, and ignoring an alert
- [Alerts](alerts.md): what each alert means, and how to fix it in a CakePHP 2 application
- [CI with GitHub Actions](ci.md)
- [Troubleshooting](troubleshooting.md)

It is one of the security checks of pieceofcake2, with
[pieceofcake2/security-sca](https://pieceofcake2.github.io/security-sca/) (the known vulnerabilities of the
Composer packages) and [pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) (the source
code); pieceofcake2/security-check runs them all.

## What It Finds

A DAST tool looks at the application from the outside, as a browser and an attacker do: it finds what the
responses show, whatever the code that made them, including the configuration of PHP and of the web server.

| | Examples |
| --- | --- |
| The baseline scan (default) | Missing security headers (CSP, clickjacking, `X-Content-Type-Options`, ...), cookies without `HttpOnly`, `Secure` or `SameSite`, forms without a CSRF token, error pages with debug information, versions in the headers, scripts of other sites without integrity |
| The full scan (`--scan=full`) | The same, and what an attack shows: XSS (reflected, persistent, DOM based), SQL injections, path traversal, command injections, open redirects, ... |

It does not find what the responses do not show: a vulnerability of a page that it does not reach, the access
of a user to the data of another (the rules of the application), or a weakness of the code that no request
triggers. [pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) checks the code, and the
three checks together cover more than each of them.

## Quick Start

```bash
composer require --dev pieceofcake2/security-dast

# The baseline scan of the application, with PHP's built-in web server and the test database
vendor/bin/security-dast --pdf=tmp/security-dast.pdf --language=ja
```

The command starts the application with `APP_ENV=test`, runs ZAP in Docker against it, and prints a summary:

```text
17 alerts (4 medium, 10 low, 3 info) on 7 URLs of http://127.0.0.1:56557 (ZAP 2.17.0). Report: tmp/security-dast.pdf
```

The exit code is `0` when no alert reaches `--fail-on` (default `high`), `1` when one does, and `2` when the
web server, ZAP or the report fails.

Then, step by step:

1. Fix the alerts of the baseline scan: most of them are fixed once for the whole application, in
   `AppController` and the web server ([Alerts](alerts.md)).
2. Scan as a user, to reach the pages behind the login ([Scanning as a Logged-in User](login.md)).
3. Run the full scan on the End-to-End tests, every night in CI ([End-to-End Tests as the Scenario](end-to-end.md),
   [CI](ci.md)).

## How It Works

1. The application of the project is started with PHP's built-in web server on `127.0.0.1`, with
   `APP_ENV=test`, so that it loads `config/.env.test` and uses the database of the tests. The document root
   comes from the application (`WWW_ROOT` of `config/define.php`, or `webroot/` or `app/webroot/` of the
   legacy layout), and a router script sends the requests to `index.php` as `webroot/.htaccess` does.
2. ZAP runs in Docker (`ghcr.io/zaproxy/zaproxy:stable`) with a plan of its Automation Framework: it crawls
   the site, or records the End-to-End tests, and checks each request and response. The full scan then attacks
   each page and parameter that it found ([Scans](scans.md)).
3. The alerts are read from the JSON report of ZAP and written as a PDF, in the language of the report: ZAP
   writes its alerts in Japanese for `--language=ja` ([The Report](report.md)).

## Requirements

- PHP 8.0+
- Docker. The image of ZAP (about 1.5 GB) is pulled on the first run. On Linux the container uses the network
  of the host (`--network host`); on macOS and Windows (Docker Desktop), it reaches the application through
  `host.docker.internal`
- Google Chrome or Chromium 131+ to print the PDF, and on Linux `fonts-noto-cjk` for a Japanese report
- An application that runs with `APP_ENV=test`: its test database with the tables and the data that the pages
  need (e.g. the schema loaded by the tests), and its `config/.env.test`
- For `--end-to-end`: the End-to-End tests of [pieceofcake2/panther](https://pieceofcake2.github.io/panther/),
  with Chrome and its chromedriver

Scan only the applications that you are allowed to: even a baseline scan sends many requests to the site, and a
full scan sends attacks. Use `--url` for a server of your own, never for a site of others.
