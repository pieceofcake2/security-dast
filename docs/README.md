# Security DAST Documentation

Dynamic application security testing (DAST) of a CakePHP 2 application: the application is run and scanned by
[OWASP ZAP](https://www.zaproxy.org/). The baseline scan checks what its responses show, such as missing
security headers, cookies without flags, forms without a CSRF token or error pages with debug information; the
full scan also attacks its pages and forms, for XSS, SQL injections and the like. ZAP can log in as a user, and
can follow the End-to-End tests of the project instead of crawling. The alerts are written as an A4 PDF report by risk, in the design of the reports of
[pieceofcake2/report](https://pieceofcake2.github.io/report/). The exit code fails CI when an alert reaches a
risk.

- [Alerts](alerts.md): what each alert means, and how to fix it in a CakePHP 2 application
- [CI with GitHub Actions](ci.md)

It is one of the security checks of pieceofcake2, with
[pieceofcake2/security-sca](https://pieceofcake2.github.io/security-sca/) (the known vulnerabilities of the
Composer packages) and [pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) (the source
code); pieceofcake2/security-check runs them all.

## How It Works

1. The application of the project is started with PHP's built-in web server on `127.0.0.1`, with
   `APP_ENV=test`, so that it loads `config/.env.test` and uses the database of the tests. The document root
   comes from the application (`WWW_ROOT` of `config/define.php`, or `webroot/` or `app/webroot/` of the
   legacy layout), and a router script sends the requests to `index.php` as `webroot/.htaccess` does.
2. ZAP runs in Docker (`ghcr.io/zaproxy/zaproxy:stable`) with a plan of its Automation Framework: it crawls
   the site for a minute (`--minutes`), and checks each request and response (passive scan). The full scan
   then attacks each page and parameter that it found (active scan). With a login, ZAP logs in with a browser
   first, and crawls and attacks as the user.
3. The alerts are read from the JSON report of ZAP and written as a PDF, in the language of the report: ZAP
   writes its alerts in Japanese for `--language=ja`.

ZAP knows the CSRF token of the forms of CakePHP 2 (`data[_Token][key]` of `SecurityComponent`): these forms
are not reported as forms without a token, and the full scan sends its attacks with a new token.

## Requirements

- PHP 8.0+
- Docker. The image of ZAP (about 1.5 GB) is pulled on the first run. On Linux the container uses the network
  of the host (`--network host`); on macOS and Windows (Docker Desktop), it reaches the application through
  `host.docker.internal`
- Google Chrome or Chromium 131+ to print the PDF, and on Linux `fonts-noto-cjk` for a Japanese report
- An application that runs with `APP_ENV=test`: its database with the tables (e.g. the schema loaded by the
  tests), and its `.env.test`

## Installation

```bash
composer require --dev pieceofcake2/security-dast
```

## Usage

```bash
# The application of the project, with the built-in web server
vendor/bin/security-dast --pdf=tmp/security-dast.pdf --language=ja

# A running application, e.g. a staging server
vendor/bin/security-dast --url=https://staging.example.com --pdf=tmp/security-dast.pdf
```

The command prints a summary and the path of the report:

```text
17 alerts (4 medium, 10 low, 3 info) on 7 URLs of http://127.0.0.1:56557 (ZAP 2.17.0). Report: tmp/security-dast.pdf
```

The exit code is `0` when no alert reaches `--fail-on`, `1` when one does, and `2` when the server, the scan
or the report fails.

Scan only the applications that you are allowed to: even a baseline scan sends many requests to the site. Use
`--url` for a server of your own, not for a site of others.

### Options

| Option | Environment variable | Default | |
| --- | --- | --- | --- |
| `--pdf=PATH` | `SECURITY_DAST_PDF` | `tmp/security-dast.pdf` | The PDF report |
| `--fail-on=RISK` | `SECURITY_DAST_FAIL_ON` | `high` | The lowest risk that fails: `high`, `medium`, `low`, `info` or `none` |
| `--project=DIR` | | the current directory | The directory of the application |
| `--url=URL` | `SECURITY_DAST_URL` | | The URL of a running application to scan, instead of the built-in web server |
| `--webroot=DIR` | `SECURITY_DAST_WEBROOT` | `WWW_ROOT` of the application | The document root of the built-in web server |
| `--app-env=ENV` | `SECURITY_DAST_APP_ENV` | `test` | `APP_ENV` of the built-in web server |
| `--ignore=LIST` | `SECURITY_DAST_IGNORE` | | Alert IDs to leave out of the report, comma separated (see [Ignoring an Alert](#ignoring-an-alert)) |
| `--scan=SCAN` | `SECURITY_DAST_SCAN` | `baseline` | `baseline`, or `full` to attack the pages too (see [Scans](#scans)) |
| `--scan-minutes=N` | `SECURITY_DAST_SCAN_MINUTES` | `10` | The most minutes of the attacks of the full scan |
| `--login-url=PATH` | `SECURITY_DAST_LOGIN_URL` | | The login page, a path (`/users/login`) or a URL, to scan as a user (see [Scanning as a Logged-in User](#scanning-as-a-logged-in-user)) |
| `--username=NAME` | `SECURITY_DAST_USERNAME` | | The user to log in as |
| `--password=PASSWORD` | `SECURITY_DAST_PASSWORD` | | Its password; prefer the environment variable |
| `--exclude=LIST` | `SECURITY_DAST_EXCLUDE` | `.*logout.*` | Regular expressions (of Java) of the URLs to leave out, comma separated |
| `--end-to-end=COMMAND` | `SECURITY_DAST_END_TO_END` | | The command of the End-to-End tests to scan, instead of the crawl (see [End-to-End Tests as the Scenario](#end-to-end-tests-as-the-scenario)) |
| `--minutes=N` | `SECURITY_DAST_MINUTES` | `1` | Minutes to crawl the site |
| `--ajax-spider=1` | `SECURITY_DAST_AJAX_SPIDER` | | Crawl with a browser too, for the links that JavaScript builds |
| `--zap-options=OPTIONS` | `SECURITY_DAST_ZAP_OPTIONS` | | More options of ZAP (`-config key=value ...`) |
| `--image=IMAGE` | `SECURITY_DAST_IMAGE` | `ghcr.io/zaproxy/zaproxy:stable` | The Docker image of ZAP, e.g. a fixed version |
| `--docker=COMMAND` | `SECURITY_DAST_DOCKER` | `docker` | The Docker command, e.g. `podman` |
| `--language=LANG` | `SECURITY_REPORT_LANGUAGE` | `en` | `en` or `ja`, or a language of `--locale-dir` |
| `--locale-dir=DIR` | `SECURITY_REPORT_LOCALE_DIR` | | `<language>.json` files that add or replace messages |
| `--title=TITLE` | `SECURITY_DAST_TITLE` | the title of the language | The title of the report |
| `--project-name=NAME` | `SECURITY_REPORT_PROJECT` | the name in `composer.json` | The project under the title |
| `--note=TEXT` | `SECURITY_DAST_NOTE` | | A note on the cover |
| `--settings=FILE` | `SECURITY_SETTINGS` | `config/security.php` or `app/Config/security.php` | The settings file |

### The Settings File

The settings of the security checks of pieceofcake2 can be kept in the project, in `config/security.php`
(`app/Config/security.php` of the legacy layout). It returns an array with a section for each check, and a
`report` section that they share:

```php
<?php
return [
    'report' => [
        'language' => 'ja',
        'dir' => 'tmp/security',
    ],
    'dast' => [
        'scan' => 'full',
        'failOn' => 'medium',
        'endToEnd' => 'vendor/bin/phpunit --testsuite EndToEnd',
        'loginUrl' => '/users/login',
        'username' => 'test@example.com',
        'password' => getenv('SECURITY_DAST_PASSWORD') ?: null,
        'ignore' => ['10049'],
    ],
];
```

The keys of the `dast` section are the options in camel case (`--fail-on` is `failOn`), with arrays for the
lists. The `report` section has `language`, `localeDir`, `project` (the project name), `note` and `dir`, the
directory of the reports (this one is written to `tmp/security/security-dast.pdf`). An unknown key of the
`dast` section is an error. The options win over the environment variables, and the environment variables
over the file.

## Scans

| | Baseline (`--scan=baseline`, default) | Full (`--scan=full`) |
| --- | --- | --- |
| What | Crawls and checks the requests and responses | The same, then attacks each page and parameter |
| Finds | Security headers, cookies, forms without a token, error pages, versions in the headers, ... | The same, and XSS (reflected, persistent, DOM), SQL injections, path traversal, command injections, ... |
| Changes data | No: it only reads the pages | Yes: it sends forms with attacks, so it adds and changes records |
| Where | Any environment | A test environment only, with a test database |
| Time | About 1 to 2 minutes | Several minutes or more, up to `--scan-minutes` (default 10) for the attacks |

The full scan sends what an attacker would: run it only on the built-in web server of the scan (the default,
with the test database), or on a server of your own that can lose its data. Never run it on production.

The attacks of a full scan are found by their effects in the responses, so a scan does not find every
vulnerability, and a finding is to check in the code: see
[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) for the code.

## Scanning as a Logged-in User

A crawl as a visitor stops at the login page, where most of the forms of an application are. Give a user of
the test database, and ZAP logs in with a browser (Firefox of its image) on the login page, then crawls and
attacks as the user:

```bash
export SECURITY_DAST_PASSWORD=secret
vendor/bin/security-dast --scan=full --login-url=/users/login --username=test@example.com
```

- ZAP fills the user name and the password fields of the page, as a person would, so the forms of
  `FormHelper` with the token of `SecurityComponent` work.
- It finds by itself how the login is kept (the session cookie) and how to check that it is (a page that
  changes after the login), and logs in again when it finds that it is logged out.
- When it cannot log in, the command fails with an error (exit code `2`), instead of scanning as a visitor
  unnoticed. The cover of the report shows the user.
- The URLs that match `.*logout.*` are left out, so that the scan does not log out. Give other patterns with
  `--exclude`, e.g. `--exclude=.*/users/logout.*,.*/admin/.*`.
- Give the password with `SECURITY_DAST_PASSWORD` (a secret of CI) rather than with the option, which is seen
  in the list of processes. It is not written to the report.

The user must be in the test database when the scan runs, e.g. created by the fixtures or a seed of the test
environment.

## End-to-End Tests as the Scenario

A crawl follows the links of the pages: it can miss the pages that only a series of actions reaches, such as
the confirmation of an order, and it does not fill forms with valid values. The End-to-End tests of the project
know these scenarios: with `--end-to-end`, ZAP records the requests of the tests and scans them, instead of
crawling.

```bash
vendor/bin/security-dast --end-to-end="vendor/bin/phpunit --testsuite EndToEnd" --scan=full
```

1. ZAP starts as a proxy (`zap.sh -daemon` in Docker), on `127.0.0.1` only, with a random key for its API.
2. The command of the tests runs with:
   - `PANTHER_CHROME_ARGUMENTS`: the proxy of ZAP for the Chrome of the tests (`--proxy-server=...`), added to
     the arguments that are already set
   - `PANTHER_EXTERNAL_BASE_URI`: the application of the scan, so that the tests do not start a web server of
     their own
   - `APP_ENV`: the one of the scan (`test`)
3. When the tests have finished, ZAP checks the requests and responses that it recorded, and, for the full
   scan, attacks them, with the session cookies of the tests.

The tests are those of [pieceofcake2/panther](https://pieceofcake2.github.io/panther/) (or of Symfony Panther):
use relative URLs in them (`$client->request('GET', '/contacts/add')`), so that they follow the base URI. Their
output goes to the error output of the command. When they fail, the scan goes on with the requests made until
then, and the cover of the report says so. With `--login-url`, the attacks of the full scan run as that user.

On the forms of `SecurityComponent`, ZAP gets a new token for each of its attacks, in the session of the tests,
so that the attacks are not rejected. Still, the active scan is bounded by `--scan-minutes` and does not reach
every parameter in a long scenario, so a full scan finds many vulnerabilities, not all: check the views with
[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) too.


## The Report

- [Sample report (English, PDF)](/report-sample.pdf)
- [Sample report (Japanese, PDF)](/report-sample-ja.pdf)

![The cover, the alerts by risk, the page of an alert and its requests](img/report-sample.png)

1. **Cover**: the result, the numbers of alerts by risk, the repository, branch and commit, the scanned URL,
   the server (the built-in web server and its `APP_ENV`, or a running application), the scan, the number of
   URLs with alerts, the ignored alerts, the threshold, and the date, environment and software of the run
   (ZAP and its version)
2. **Alerts by risk**: High, Medium, Low and Informational; each alert with its number of requests, and a
   link to its page
3. **A page for each alert**: its name, alert ID, risk, confidence, CWE and WASC, what it means and how to fix
   it (as ZAP writes them), each request where it was found (the method, the URL, and the parameter, attack,
   evidence and other information of ZAP), up to 50, and the links: the page of the alert on zaproxy.org
   first

See [Alerts](alerts.md) for the fixes in a CakePHP 2 application.

## Ignoring an Alert

Fix the alerts first: most of them are fixed once for the whole application, in `AppController` or the web
server (see [Alerts](alerts.md)). When an alert does not apply, e.g. the security headers are set by a proxy
in front of the application and not by the built-in web server, leave it out of the report with its ID:

```bash
vendor/bin/security-dast --ignore=10038,10020
```

An ID leaves out its variants too: `10038` leaves out `10038-1`. The IDs are those of the report (Alert ID)
and of [the alerts of ZAP](https://www.zaproxy.org/docs/alerts/). The cover of the report lists the ignored
alerts.

## Troubleshooting

**`ZAP failed (exit code 125): Cannot connect to the Docker daemon`**: Docker is not running, or the user
cannot use it.

**`... has no index.php: give the document root with --webroot`**: the document root was not found. Give it
with `--webroot`, relative to the project.

**`PHP's built-in web server did not start`**: the message shows the output of the server. The port is chosen
free, so it is often PHP itself, e.g. a missing extension.

**Every page is an error 500**: the application does not run with `APP_ENV=test`, e.g. its test database has
no tables. Run the scan after the schema is loaded (as the tests do), or use another `--app-env`. The alert
"Application Error Disclosure" shows these pages.

**ZAP found only a few URLs**: the crawl follows the links of the pages, and finds a few more or less from a
run to another. Raise `--minutes`, add `--ajax-spider=1` for the links that JavaScript builds, or scan the
End-to-End tests with `--end-to-end`.

**`ZAP could not log in as ...`**: check the user name and the password, that the user is in the test
database, and that the login page has the fields of the user name and the password without another step
(e.g. a CAPTCHA or a second factor, which a test user should not have).

**The End-to-End tests fail only in the scan**: they must use relative URLs, so that they follow
`PANTHER_EXTERNAL_BASE_URI`. Check their output in the error output of the command.

**`Chrome is needed to print the report`**: install Chrome or Chromium, or set `REPORT_CHROME_BINARY` (see
[pieceofcake2/report](https://pieceofcake2.github.io/report/)).
