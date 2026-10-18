# CI with GitHub Actions

Two workflows cover most applications:

| Workflow | When | Scan |
| --- | --- | --- |
| [docs/examples/security-dast.yml](examples/security-dast.yml) | Each push to `main`, each pull request, and every week | The baseline scan of the application, with the test database of the job |
| [docs/examples/security-dast-full.yml](examples/security-dast-full.yml) | Every night, and by hand | The full scan on the End-to-End tests, as a test user |

Both upload the report as a PDF (not a zip). The Ubuntu runners have Docker and Google Chrome.

## The Baseline Scan

```yaml
- run: composer install --no-interaction --no-progress
- run: sudo apt-get install -y fonts-noto-cjk
- run: vendor/bin/security-dast --pdf=tmp/security-dast.pdf --fail-on=medium --language=ja
- uses: actions/upload-artifact@v7
  if: ${{ !cancelled() }}
  with:
    path: tmp/security-dast.pdf
    archive: false
```

- The application runs with `APP_ENV=test`, so it needs what the tests need: the test database (a service of the
  job, and `DATABASE_URL`), with its tables. Without them every page is an error 500, and the scan reports the
  error pages instead of the application.
- The environment variables of the job are passed to the application, e.g. `DATABASE_URL`.
- On Linux, ZAP uses the network of the host (`--network host`) and reaches the web server on `127.0.0.1`.
- `if: ${{ !cancelled() }}` uploads the report also when the scan fails the job, which is when it is needed.
- Start with `--fail-on=none` to see the alerts of an existing application without failing the job, then raise
  it once the headers are fixed ([Alerts](alerts.md)).

## The Full Scan with the End-to-End Tests

The full scan attacks the application, so it runs on the built-in web server of the job, with the test database
of the job, which is thrown away with the job:

```yaml
- name: Scan the End-to-End tests with attacks
  env:
    SECURITY_DAST_PASSWORD: ${{ secrets.TEST_USER_PASSWORD }}
  run: >-
    vendor/bin/security-dast --scan=full --scan-minutes=30
    --end-to-end="vendor/bin/phpunit --testsuite EndToEnd"
    --login-url=/users/login --username=test@example.com
    --pdf=tmp/security-dast-full.pdf --fail-on=high --language=ja
```

- The job needs Chrome and a chromedriver for the tests, as for the End-to-End tests themselves
  (`vendor/bin/bdi detect drivers`).
- Load the test user into the test database before the scan ([The Test User](login.md#the-test-user)), and keep
  its password a secret of the repository (`TEST_USER_PASSWORD`).
- It takes longer than the other jobs: run it every night (`schedule`) rather than on each push, and give it
  enough `--scan-minutes` for the scenario.

## A Fixed Version of ZAP

The default image, `ghcr.io/zaproxy/zaproxy:stable`, follows the releases of ZAP: its rules change, and so can
the alerts of a scan that changed nothing. Pin a version to compare the reports from a run to another, and raise
it on purpose:

```bash
vendor/bin/security-dast --image=ghcr.io/zaproxy/zaproxy:2.17.0
```

Each job pulls the image (about 1.5 GB) before the scan.

## Staging

To scan a staging server after a deployment, give its URL. Run the baseline scan only, which changes nothing:

```yaml
- run: vendor/bin/security-dast --url=https://staging.example.com --pdf=tmp/security-dast-staging.pdf
```

The summary of the command is in the log of the job:

```text
11 alerts (2 medium, 7 low, 2 info) on 14 URLs of https://staging.example.com (ZAP 2.17.0). Report: tmp/security-dast-staging.pdf
```
