# pieceofcake2/security-dast

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/security-dast?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/security-dast?label=Packagist)](https://packagist.org/packages/pieceofcake2/security-dast)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/security-dast/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/security-dast/actions/workflows/CI.yml)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/security-dast/CI.yml?label=CI)](https://github.com/pieceofcake2/security-dast/actions/workflows/CI.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/security-dast?label=Coverage)](https://codecov.io/gh/pieceofcake2/security-dast)

Dynamic application security testing of a CakePHP 2 application: the application is run with PHP's built-in
web server and scanned by the baseline scan of [OWASP ZAP](https://www.zaproxy.org/) in Docker (security
headers, cookies, CSRF tokens of forms, error pages, ...), and the alerts are written as an A4 PDF report by
risk in the design of [pieceofcake2/report](https://github.com/pieceofcake2/report), with an exit code that
fails CI. The documentation tells how to fix each alert in a CakePHP 2 application.

```bash
composer require --dev pieceofcake2/security-dast
vendor/bin/security-dast --pdf=tmp/security-dast.pdf --fail-on=high
```

## Documentation

- Website: https://pieceofcake2.github.io/security-dast/ (日本語: https://pieceofcake2.github.io/security-dast/ja/)
- Markdown: [docs/README.md](docs/README.md) (日本語: [docs/ja/README.md](docs/ja/README.md)) (for AI tools and offline reading)

## Requirements

* PHP 8.0+
* Docker (the image of ZAP)
* Google Chrome or Chromium 131+

## Installation

```bash
composer require --dev pieceofcake2/security-dast
```

## Reporting Issues

Please open an issue on [GitHub](https://github.com/pieceofcake2/security-dast/issues).
To report a security vulnerability, use [private vulnerability reporting](https://github.com/pieceofcake2/security-dast/security/advisories/new).

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## Origin

This package was created by [pieceofcake2](https://github.com/pieceofcake2), which also maintains it. It is
not a fork of another project and is not based on one.

## License

The MIT License. See [LICENSE](LICENSE).
