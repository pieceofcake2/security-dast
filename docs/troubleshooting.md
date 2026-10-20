# Troubleshooting

## Docker and ZAP

**`ZAP failed (exit code 125): Cannot connect to the Docker daemon`**: Docker is not running, or the user
cannot use it. Start Docker, or give another command with `--docker`, e.g. `podman`.

**`ZAP failed ...` with the output of ZAP**: the message has the last lines of ZAP. A plan error of ZAP is a bug
of this package: please report it with the message.

**`ZAP did not answer automation/view/planProgress for 300 seconds`**: ZAP did not answer the scan of the
recorded requests for five minutes in a row; a slow answer for a shorter time, e.g. while the active scan keeps
ZAP busy, does not stop the scan. The message tells whether the container of ZAP still runs, or how it stopped
(`OOMKilled`: out of memory), and shows the last lines of `zap.log` (on Linux) or of the output of the container,
without the key of the API and the password of the login. A `java.lang.OutOfMemoryError` there means that ZAP
ran out of memory: scan on a host with more memory, or lower `--scan-minutes`. No report is written, as ZAP
writes it at the end of the plan.

**ZAP cannot reach the application** (no URL in the report, or `Connection refused` in the output of ZAP): on
Linux, the container uses `--network host`, so the network of the host must be usable by Docker (it is on the
runners of GitHub Actions). On macOS and Windows, the container reaches the host as `host.docker.internal`,
which Docker Desktop provides; with another Docker, give a running application with `--url` that the container
can reach.

**The first run takes long**: Docker pulls the image of ZAP (about 1.5 GB). Pin a version with
`--image=ghcr.io/zaproxy/zaproxy:2.17.0` to pull it once, and to scan with the same rules from a run to another.

## The Application

**`... has no index.php: give the document root with --webroot`**: the document root was not found. Give it
with `--webroot`, relative to the project.

**`PHP's built-in web server did not start`**: the message shows the output of the server. The port is chosen
free, so it is often PHP itself, e.g. a missing extension.

**Every page is an error 500**: the application does not run with `APP_ENV=test`, e.g. its test database has no
tables. Run the scan after the schema is loaded (as the tests do), or use another `--app-env`. The alert
"Application Error Disclosure" shows these pages, and `logs/error.log` the errors.

**The top page is a 404**: the `home` page of a new application is a 404 without debug (`debug` 0, as with
`APP_ENV=test`). Give the scan a page to start from: link the pages of the application from the top page, or
use the [End-to-End tests](end-to-end.md).

**ZAP found only a few URLs**: the crawl follows the links of the pages, and finds a few more or fewer from a
run to another. Raise `--minutes`, add `--ajax-spider=1` for the links that JavaScript builds, or scan the
[End-to-End tests](end-to-end.md).

**The full scan fills the test database**: it sends the forms many times. Reload the test database after the
scan, as the tests do, and leave out the pages that should not be sent with `--exclude`.

## Login

**`ZAP could not log in as ...`**: check the user name and the password, that the user is in the test
database ([The Test User](login.md#the-test-user)), and that the login page has the fields of the user name and
of the password, without another step (a CAPTCHA or a second factor, which a test user should not have).

**The pages of the user are missing in the report**: the scan logged out, e.g. through a URL of the logout that
`--exclude` does not match. Add it to `--exclude`, and keep the logout in the list.

## End-to-End Tests

**`the End-to-End tests failed (exit code 1)`**: the report is written, but the command exits with `2`: fix the
tests (their output is above the message), then scan again.

**The tests fail only in the scan**: they must use relative URLs, so that they follow
`PANTHER_EXTERNAL_BASE_URI`. Their output is in the error output of the command.

**`The port 9515 is already in use`**: another chromedriver runs, e.g. the End-to-End tests of another project.
Wait for them, or give the tests another port of chromedriver.

**The full scan finds nothing in the forms of the tests**: check the ignored and the excluded URLs, give
`--scan-minutes` more time for a long scenario, and give `--login-url` when the tests log out at their end
([Writing Tests That Make a Good Scenario](end-to-end.md#writing-tests-that-make-a-good-scenario)).

## The Report

**`Chrome is needed to print the report`**: install Chrome or Chromium, or set `REPORT_CHROME_BINARY` (see
[pieceofcake2/report](https://pieceofcake2.github.io/report/)).

**The Japanese report has squares instead of characters**: install the Japanese fonts, e.g.
`sudo apt-get install -y fonts-noto-cjk` on Ubuntu.
