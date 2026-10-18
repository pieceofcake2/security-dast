# Configuration

Each setting is an option of the command, an environment variable, or a key of the settings file. The options
win over the environment variables, and the environment variables over the file.

## Options

### What to Scan

| Option | Environment variable | Default | |
| --- | --- | --- | --- |
| `--project=DIR` | | the current directory | The directory of the application |
| `--url=URL` | `SECURITY_DAST_URL` | | The URL of a running application to scan, instead of the built-in web server |
| `--webroot=DIR` | `SECURITY_DAST_WEBROOT` | `WWW_ROOT` of the application | The document root of the built-in web server, relative to the project |
| `--app-env=ENV` | `SECURITY_DAST_APP_ENV` | `test` | `APP_ENV` of the built-in web server (and of the End-to-End tests) |
| `--exclude=LIST` | `SECURITY_DAST_EXCLUDE` | `.*logout.*` | Regular expressions (of Java) of the URLs to leave out, comma separated |

### How to Scan

| Option | Environment variable | Default | |
| --- | --- | --- | --- |
| `--scan=SCAN` | `SECURITY_DAST_SCAN` | `baseline` | `baseline`, or `full` to attack the pages too ([Scans](scans.md)) |
| `--scan-minutes=N` | `SECURITY_DAST_SCAN_MINUTES` | `10` | The most minutes of the attacks of the full scan |
| `--minutes=N` | `SECURITY_DAST_MINUTES` | `1` | Minutes to crawl the site; not with `--end-to-end`, which does not crawl |
| `--ajax-spider=1` | `SECURITY_DAST_AJAX_SPIDER` | | Crawl with a browser too, for the links that JavaScript builds; not with `--end-to-end` |
| `--end-to-end=COMMAND` | `SECURITY_DAST_END_TO_END` | | The command of the End-to-End tests to scan, instead of the crawl ([End-to-End Tests](end-to-end.md)) |
| `--login-url=PATH` | `SECURITY_DAST_LOGIN_URL` | | The login page, a path or a URL, to scan as a user ([Login](login.md)) |
| `--username=NAME` | `SECURITY_DAST_USERNAME` | | The user to log in as |
| `--password=PASSWORD` | `SECURITY_DAST_PASSWORD` | | Its password; prefer the environment variable |

### The Result

| Option | Environment variable | Default | |
| --- | --- | --- | --- |
| `--pdf=PATH` | `SECURITY_DAST_PDF` | `tmp/security-dast.pdf` | The PDF report |
| `--fail-on=RISK` | `SECURITY_DAST_FAIL_ON` | `high` | The lowest risk that fails: `high`, `medium`, `low`, `info` or `none` |
| `--ignore=LIST` | `SECURITY_DAST_IGNORE` | | Alert IDs to leave out of the report, comma separated ([Ignoring an Alert](report.md#ignoring-an-alert)) |
| `--language=LANG` | `SECURITY_REPORT_LANGUAGE` | `en` | `en` or `ja`, or a language of `--locale-dir` |
| `--locale-dir=DIR` | `SECURITY_REPORT_LOCALE_DIR` | | `<language>.json` files that add or replace messages |
| `--title=TITLE` | `SECURITY_DAST_TITLE` | the title of the language | The title of the report |
| `--project-name=NAME` | `SECURITY_REPORT_PROJECT` | the name in `composer.json` | The project under the title |
| `--note=TEXT` | `SECURITY_DAST_NOTE` | | A note on the cover |

### ZAP and Docker

| Option | Environment variable | Default | |
| --- | --- | --- | --- |
| `--image=IMAGE` | `SECURITY_DAST_IMAGE` | `ghcr.io/zaproxy/zaproxy:stable` | The Docker image of ZAP, e.g. a fixed version `ghcr.io/zaproxy/zaproxy:2.17.0` |
| `--docker=COMMAND` | `SECURITY_DAST_DOCKER` | `docker` | The Docker command, e.g. `podman` |
| `--zap-options=OPTIONS` | `SECURITY_DAST_ZAP_OPTIONS` | | More options of ZAP, separated by spaces, e.g. `-config spider.maxDepth=5` |
| `--settings=FILE` | `SECURITY_SETTINGS` | `config/security.php` or `app/Config/security.php` | The settings file |

The paths of `--pdf`, `--settings`, `--locale-dir` and of the `dir` of the report are relative to the current
directory, not to the project: run the command in the project, or give absolute paths. `--webroot` is relative
to the project.

The `REPORT_CHROME_BINARY` and `REPORT_NO_SANDBOX` variables of
[pieceofcake2/report](https://pieceofcake2.github.io/report/) set the Chrome that prints the PDF.

## The Settings File

The settings of the security checks of pieceofcake2 can be kept in the project, in `config/security.php`
(`app/Config/security.php` of the legacy layout). It returns an array with a section for each check, `sca`,
`sast` and `dast`, and a `report` section that they share:

```php
<?php
// config/security.php
return [
    'report' => [
        'language' => 'ja',
        'dir' => 'tmp/security',
        'project' => 'Acme',
    ],
    'dast' => [
        'scan' => 'full',
        'scanMinutes' => 20,
        'failOn' => 'medium',
        'endToEnd' => 'vendor/bin/phpunit --testsuite EndToEnd',
        'loginUrl' => '/users/login',
        'username' => 'test@example.com',
        'password' => getenv('SECURITY_DAST_PASSWORD') ?: null,
        'exclude' => ['.*/users/logout.*', '.*/users/delete.*'],
        'ignore' => ['10049' => 'The public pages may be cached'],
    ],
];
```

- The keys of the `dast` section are the options in camel case (`--fail-on` is `failOn`, `--end-to-end` is
  `endToEnd`), with arrays for the lists. An unknown key is an error.
- The `report` section has `language`, `localeDir`, `project` (the project name), `note` and `dir`, the
  directory of the reports: this one writes `tmp/security/security-dast.pdf`.
- The file is PHP: read the secrets from the environment, and commit the file.

A setting of the file is used unless the option or the environment variable is given, e.g. the baseline scan of
the pull requests with the settings of the nightly full scan:

```bash
SECURITY_DAST_SCAN=baseline vendor/bin/security-dast
```
