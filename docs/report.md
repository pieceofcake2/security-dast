# The Report

## Samples

**The baseline scan** of a page without the security headers, with a form without a token and an error page:

- [Sample report of the baseline scan (English, PDF)](https://pieceofcake2.github.io/security-dast/report-sample.pdf)
- [Sample report of the baseline scan (Japanese, PDF)](https://pieceofcake2.github.io/security-dast/report-sample-ja.pdf)

![The cover, the alerts by risk, the page of an alert and its requests](img/report-sample.png)

**The full scan** of a CakePHP 2 application, with an End-to-End test as the scenario: a form of
`SecurityComponent` that prints its value without `h()`, found as a reflected XSS:

- [Sample report of the full scan (English, PDF)](https://pieceofcake2.github.io/security-dast/report-sample-full.pdf)
- [Sample report of the full scan (Japanese, PDF)](https://pieceofcake2.github.io/security-dast/report-sample-full-ja.pdf)

![The cover of a full scan with the End-to-End tests, the alerts by risk, and the page of the XSS with its attack](img/report-sample-full.png)

## The Pages

1. **Cover**: the result, the numbers of alerts by risk, the repository, branch and commit, and what was
   scanned: the URL, the server (the built-in web server and its `APP_ENV`, or a running application), the scan
   (baseline or full, its minutes), the End-to-End tests and their result, the user of the login, the number of
   URLs with alerts, the ignored alerts and the threshold; then the date, environment and software of the run
   (ZAP and its version)
2. **Alerts by risk**: High, Medium, Low and Informational; each alert with its number of requests, and a link
   to its page
3. **A page for each alert**: its name, alert ID, risk, confidence, CWE and WASC, what it means and how to fix
   it (as ZAP writes them, in the language of the report), each request where it was found, up to 50, and the
   links: the page of the alert on zaproxy.org first

Each request shows its method and URL, then what ZAP found in it:

| | |
| --- | --- |
| Parameter | The parameter, header or cookie of the alert, e.g. `data[Contact][message]` |
| Attack | The value that ZAP sent, for the alerts of the full scan |
| Evidence | What the response showed, e.g. the attack printed as it was sent, or the header that is missing |
| Other info | More details of ZAP |

See [Alerts](alerts.md) for the fixes of the alerts in a CakePHP 2 application.

## Risk and Confidence

The **risk** is how bad the issue is when it is real: High (e.g. XSS, SQL injection), Medium (e.g. a missing
CSP or anti-clickjacking header), Low (e.g. a cookie without `SameSite`), and Informational (to know, rarely to
fix). The **confidence** is how sure ZAP is that the issue is real: Low, Medium, High or Confirmed. Check an
alert of low confidence in the code before fixing it: it can be a false positive.

`--fail-on` fails the run on a risk, whatever the confidence:

| `--fail-on` | Fails on |
| --- | --- |
| `high` (default) | High |
| `medium` | High and Medium |
| `low` | High, Medium and Low |
| `info` | Every alert |
| `none` | Nothing: the report only |

For an existing application, start with `--fail-on=none`, fix the alerts of the report, then raise the
threshold.

## Ignoring an Alert

Fix the alerts first: most of them are fixed once for the whole application, in `AppController` or the web
server ([Alerts](alerts.md)). When an alert does not apply, leave it out of the report with its ID:

```bash
vendor/bin/security-dast --ignore=10038,10020
```

- An ID leaves out its variants too: `10038` leaves out `10038-1`.
- The IDs are those of the report (Alert ID) and of [the alerts of ZAP](https://www.zaproxy.org/docs/alerts/).
- The cover of the report lists the ignored alerts, so that they are not forgotten.

The alerts to ignore are those of the scan environment, not of the application: e.g. the security headers that
a proxy in front of production adds, which the built-in web server of the scan does not have, or the
`X-Powered-By` of the PHP of CI. Keep the reason in the [settings file](configuration.md#the-settings-file):

```php
'dast' => [
    // The load balancer of production adds CSP and X-Frame-Options; checked on staging with --url
    'ignore' => ['10038', '10020'],
],
```
