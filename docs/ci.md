# CI with GitHub Actions

[docs/examples/security-dast.yml](examples/security-dast.yml) runs the application with the built-in web
server, scans it with ZAP on each push and pull request, and every week, and uploads the report as a PDF (not a
zip). The Ubuntu runners have Docker and Google Chrome.

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

- The application runs with `APP_ENV=test`, so it needs what the tests need: the test database (a service
  of the job, and `DATABASE_URL`), with its tables. Without them every page is an error 500, and the scan
  reports the error pages instead of the application.
- The environment variables of the job are passed to the application, e.g. `DATABASE_URL`.
- On Linux, ZAP uses the network of the host (`--network host`) and reaches the web server on `127.0.0.1`.
- The image of ZAP is large (about 1.5 GB); each job pulls it before the scan.
- `if: ${{ !cancelled() }}` uploads the report also when the scan fails the job, which is when it is needed.
- Start with `--fail-on=none` to see the alerts of an existing application without failing the job, then
  raise it once the headers are fixed (see [Alerts](alerts.md)).

To scan a staging server after a deployment instead, give its URL:

```yaml
- run: vendor/bin/security-dast --url=https://staging.example.com --pdf=tmp/security-dast.pdf
```

The summary of the command is in the log of the job:

```text
11 alerts (2 medium, 7 low, 2 info) on 14 URLs of http://127.0.0.1:49855 (ZAP 2.17.0). Report: tmp/security-dast.pdf
```
