# CHANGELOG

## Unreleased

- First release: `vendor/bin/security-dast` runs a CakePHP 2 application with PHP's built-in web server
  (`APP_ENV=test`, the document root of the application) or takes the URL of a running one, scans it with the
  baseline scan of OWASP ZAP in Docker, and writes a PDF report of the alerts by risk with
  pieceofcake2/report. `--fail-on` sets the lowest risk that fails (default `high`), `--ignore` leaves alerts
  out, and the settings file `config/security.php` keeps the settings. The documentation tells how to fix each
  alert in a CakePHP 2 application
