# CHANGELOG

## 1.1.1

- The scans run the beta scan rules of ZAP again: the Docker image of ZAP has the release rules only, and since
  every release ran ZAP with a plan of its Automation Framework instead of `zap-baseline.py`, which installs
  them, the beta rules did not run. ZAP now installs `pscanrulesBeta` at the start of each scan, and
  `ascanrulesBeta` for the full scan, from the ZAP Marketplace (`-addoninstall`), as its packaged scans do. A scan
  finds again e.g. Content Cacheability (10049), Permissions Policy Header Not Set (10063) and Insufficient Site
  Isolation Against Spectre (90004), and the full scan e.g. SSRF (40046) and Session Fixation (40013)

## 1.1.0

- The report lists the scan rules of ZAP besides the alerts, on pages after the alerts by risk
  (pieceofcake2/report 1.1):
  - **Passed checks**: the passive and active rules that ran and raised no alert on the site
  - **Not run**: the active rules that ZAP skipped, the rules of the active scan for the baseline scan, and a note
    when the active scan reached its time limit
  The plan of ZAP writes two more reports for them: `modern` with the section `passingrules`, and, for the full
  scan, `traditional-json-plus` with the section `statistics` (the rules that the active scan skipped)
- The alerts by risk show High, Medium, Low and Informational also without alerts (`None`), so that a reader tells a
  risk without alerts from one that was left out
- The samples are built from recorded scans with `docs/examples/report-sample.php`, and the Samples workflow
  records them again with ZAP

## 1.0.6

- The docs explain the alert 10038-1 "CSP Header Not Set" that ZAP raises for a report-only Content Security
  Policy, and how to ignore it, with its reason, until the policy is enforced

## 1.0.5

- The docs point the fix of the missing security headers to
  [pieceofcake2/security-headers](https://pieceofcake2.github.io/security-headers/), which adds them to every
  response of CakePHP; the way by hand in `AppController` stays

## 1.0.4

- The full scan no longer reports Path Traversal (alert 6-5) when a field of a form accepts the file name of the
  URL, e.g. a status `1` on `/posts/edit/1`: Path Traversal runs at the threshold High, without the check on the
  file name of the URL, which is an action or a passed argument in CakePHP. Its other checks still run, and the
  other rules run as in the Default Policy of ZAP

- The example workflows of the docs are in the Composer package, which `docs/ci.md` links to
- Only the alerts of the site of the scan are reported and counted: the browser of a scenario also requests other
  sites through the proxy of ZAP, e.g. a CDN, whose alerts are not of the application

## 1.0.3

- An empty list of the settings file, e.g. `'exclude' => []`, is no longer the text `[]` (an invalid pattern of
  ZAP since 1.0.2), and a list with gaps in its keys, e.g. of `array_filter()`, is a list again
- On Linux, ZAP runs as the user of the command, with its home in the directory of the scan, which is now
  private (`0700`): the other users of the host can no longer read or replace the plan, the reports or the key
  of the API
- SIGTERM to the command alone also removes the container of a scan without `--end-to-end`, and a second Ctrl+C
  no longer stops the cleanup halfway
- The commands of the project, e.g. `config/define.php`, no longer get `SECURITY_DAST_PASSWORD`
- `ext-pcntl` is suggested, for the cleanup on Ctrl+C and SIGTERM

## 1.0.2

- The alerts that `--ignore` leaves out are in the report: a group "Ignored alerts" with the ID, name, risk and
  number of URLs of each, and their number in the summary of the command. They do not fail the scan
- The cover shows the excluded URLs (`--exclude`, `.*logout.*` by default)
- The settings file gives the reason of each ignored alert ID, `'ignore' => ['10038' => 'reason']`, which the
  report shows with the alert

## 1.0.1

- The password of the login is no longer written to the plan of ZAP: ZAP gets it in an environment variable of
  its container, and the tests of `--end-to-end` and the application no longer get `SECURITY_DAST_PASSWORD`
- The key of the API of the ZAP proxy is no longer on the command line, where the other users of the host could
  see it
- The directory of the scan is no longer readable by the other users of the host (`1733` instead of `0777`)
- Ctrl+C and SIGTERM (with the pcntl extension) stop ZAP, the web server and the tests, and remove the directory
  of the scan
- A failed run of the End-to-End tests exits with `2` after the report, as the pages after the failure may not be
  scanned
- An absolute login URL on 127.0.0.1 or localhost is reached from the container, as the site is; `[::1]` too
- The built-in web server serves the files of the document root by their encoded names, e.g. `my%20site.css`
- The report says "on the pages of the End-to-End tests" when a scenario raised no alert
- A `null` in the settings file is not set, e.g. `getenv('...') ?: null`
- Windows: the End-to-End tests and the web server read from `NUL`
- Documentation: `data[_Token][debug]`, `--minutes` and `--ajax-spider` with `--end-to-end`, the paths relative
  to the current directory, the triggers of the example workflow, the sample reports by their URL

## 1.0.0

- First release: `vendor/bin/security-dast` runs a CakePHP 2 application with PHP's built-in web server
  (`APP_ENV=test`, the document root of the application) or takes the URL of a running one, scans it with the
  baseline scan of OWASP ZAP in Docker, and writes a PDF report of the alerts by risk with
  pieceofcake2/report. `--fail-on` sets the lowest risk that fails (default `high`), `--ignore` leaves alerts
  out, and the settings file `config/security.php` keeps the settings. The documentation tells how to fix each
  alert in a CakePHP 2 application
- `--scan=full` attacks the pages and forms too (the active scan of ZAP), for XSS, SQL injections and the like
- `--login-url`, `--username` and `--password` make ZAP log in with a browser and scan as the user; a failed
  login is an error
- `--end-to-end` runs the End-to-End tests of pieceofcake2/panther through ZAP, and scans what they did
  instead of crawling
