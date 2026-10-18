# Scans

| | Baseline (`--scan=baseline`, default) | Full (`--scan=full`) |
| --- | --- | --- |
| What | Crawls and checks the requests and responses (passive scan) | The same, then attacks each page and parameter (active scan) |
| Finds | Security headers, cookies, forms without a token, error pages, versions in the headers, ... | The same, and XSS (reflected, persistent, DOM based), SQL injections, path traversal, command injections, open redirects, ... |
| Changes data | No: it only reads the pages | Yes: it sends forms with attacks, so it adds and changes records |
| Where | Any environment, a staging server too | A test environment only, with a test database |
| Time | About 1 to 2 minutes | Several minutes or more, up to `--scan-minutes` (default 10) for the attacks |

## The Baseline Scan

ZAP crawls the site from its top page for `--minutes` (default 1): it follows the links and the forms of the
pages (its spider), as a visitor who is not logged in, unless a [login](login.md) is given. It checks each
request and response with its passive rules: the headers, the cookies, the forms, the content of the pages and
of the scripts. It sends no attacks, so it can run on any environment, a staging server too
(`--url=https://staging.example.com`).

The crawl finds a few more or fewer pages from a run to another. For the links that JavaScript builds, add
`--ajax-spider=1`, which crawls with a browser too (slower). For the pages that only a series of actions
reaches, use the [End-to-End tests](end-to-end.md) instead of the crawl.

## The Full Scan

The full scan then attacks each page and each parameter (of the query, of the forms, of the cookies and of the
headers) that the crawl or the tests found, with the active rules of ZAP: it sends values such as scripts,
quotes and SQL, paths with `../`, commands and URLs of other sites, and reports what the responses show, e.g. a
script printed as it was sent, or an SQL error.

```bash
vendor/bin/security-dast --scan=full --scan-minutes=20
```

- **It changes data.** The forms are sent with attacks, many times: records are added, changed or deleted, and
  mails may be sent. Run it only on the built-in web server of the scan (the default, with the test database),
  or on a server of your own that can lose its data. Never run it on production.
- **It takes time.** The attacks are bounded by `--scan-minutes` (default 10). A long scenario needs more:
  when the time is over, the parameters not attacked yet are not checked.
- **It finds many vulnerabilities, not all.** An attack is found by its effect in the responses. Check the code
  with [pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) too.

The full scan runs every active rule of ZAP, as its Default Policy does, in every language of the report.

## CakePHP 2 and ZAP

ZAP is set up for the applications of CakePHP 2:

- It knows the CSRF token of the forms of `SecurityComponent` (`data[_Token][key]`): these forms are not
  reported as forms without a token, and the full scan gets a new token for each of its attacks, from the page
  of the form, in the session of the scan, so that `SecurityComponent` does not black-hole them.
- It leaves the fields that `SecurityComponent` locks alone (`_method`, `data[_Token][fields]`,
  `data[_Token][unlocked]`, `data[_Token][debug]`): a change of them is always black-holed, so attacking them
  would only spend time.
- It knows the session cookie of CakePHP (`CAKEPHP`).
- The URLs of the logout are left out (`.*logout.*`, see `--exclude`), so that a scan as a user stays logged
  in.
- The full scan does not take the file name of a URL for a file: Path Traversal runs at the threshold High,
  without its check that sends the file name of the URL as the value of a parameter (alert 6-5). The file name of
  a URL of CakePHP is an action or a passed argument, e.g. `1` of `/posts/edit/1`, which is often a valid value of
  a field of the form too, e.g. a status, so the page is OK with it and not with a random value. The other checks
  of Path Traversal, with the paths of known files such as `/etc/passwd`, still run.

## Which Scan Where

| When | Scan |
| --- | --- |
| Each push and pull request | The baseline scan, as a visitor and [as a user](login.md) |
| Every night, or before a release | The full scan on the [End-to-End tests](end-to-end.md), with the test database of CI |
| After a deployment to staging | The baseline scan with `--url` |

See [CI with GitHub Actions](ci.md) for the workflows.

## What DAST Does Not Find

- The pages that the scan does not reach: give it a user and the End-to-End tests.
- The rules of the application: whether a user can see or change the data of another (`/orders/view/12` of
  another user), or do what only an administrator should. Test them in the tests of the application.
- What depends on several steps or on time, e.g. a race between two requests.
- What no response shows, e.g. a weak hash of the passwords, or a secret in the code: see
  [pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/) (its configuration check finds
  the default salts and keys) and [pieceofcake2/security-sca](https://pieceofcake2.github.io/security-sca/)
  (the packages).
