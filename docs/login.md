# Scanning as a Logged-in User

A crawl as a visitor stops at the login page, where most of the forms of an application are. Give a user of the
test database, and ZAP logs in with a browser on the login page, then crawls and, for the full scan, attacks as
the user:

```bash
export SECURITY_DAST_PASSWORD=secret
vendor/bin/security-dast --login-url=/users/login --username=test@example.com
vendor/bin/security-dast --scan=full --login-url=/users/login --username=test@example.com
```

| Option | Environment variable | |
| --- | --- | --- |
| `--login-url=PATH` | `SECURITY_DAST_LOGIN_URL` | The login page: a path of the application (`/users/login`) or a URL |
| `--username=NAME` | `SECURITY_DAST_USERNAME` | The user to log in as, as typed in the login form (e.g. an email) |
| `--password=PASSWORD` | `SECURITY_DAST_PASSWORD` | Its password |
| `--exclude=LIST` | `SECURITY_DAST_EXCLUDE` | Regular expressions (of Java) of the URLs to leave out, default `.*logout.*` |

## How ZAP Logs In

- ZAP opens the login page in a browser (Firefox, in its image), fills the fields of the user name and of the
  password, and submits the form, as a person would. The forms of `FormHelper` with the token of
  `SecurityComponent` work as they are.
- It finds by itself how the login is kept (the session cookie) and how to check it (a page that changes after
  the login), and logs in again when it finds that it was logged out.
- When it cannot log in, the command fails with an error (exit code `2`), instead of scanning as a visitor
  unnoticed. The cover of the report shows the user and the login page.

## The Test User

The user must be in the test database when the scan runs: the built-in web server of the scan uses the
database of `APP_ENV=test`, as the tests do, but the fixtures of the tests are only there while a test runs.
Create the user before the scan, e.g. with an SQL file of the project that CI loads after the schema:

```sql
-- tests/seed/security.sql: the user of the scans, with the hash of the password of the application
INSERT INTO users (email, password, role, created, modified)
VALUES ('test@example.com', '$2y$10$...', 'user', NOW(), NOW());
```

The hash is the one that the application checks: e.g. `php -r 'echo password_hash("secret", PASSWORD_BCRYPT);'`
for the `Blowfish` password hasher of `AuthComponent`. Keep the password a secret of CI, even for a test user.

A test user should log in with its user name and password only: ZAP cannot pass a CAPTCHA, a second factor
or a login of another site (SSO). Turn them off in the test environment, e.g. with a setting of `.env.test`.

## Several Roles

ZAP logs in as one user per scan. To scan the pages of an administrator too, run the scan again with another
user and another report:

```bash
SECURITY_DAST_PASSWORD=$USER_PASSWORD vendor/bin/security-dast --login-url=/users/login \
    --username=user@example.com --pdf=tmp/security-dast-user.pdf
SECURITY_DAST_PASSWORD=$ADMIN_PASSWORD vendor/bin/security-dast --login-url=/users/login \
    --username=admin@example.com --pdf=tmp/security-dast-admin.pdf
```

Whether a user can open the pages of an administrator is a rule of the application, which a scan does not
check: test it in the tests of the application.

## Pages to Leave Out

The URLs that match `.*logout.*` are left out, so that the scan does not log out. Give your own patterns with
`--exclude` (they replace the default, so keep the logout in them):

```bash
vendor/bin/security-dast --login-url=/users/login --username=test@example.com \
    --exclude='.*/users/logout.*,.*/users/delete.*,.*/admin/.*'
```

Leave out the pages that would stop the scan or harm the test environment, e.g. the deletion of the account of
the user, or the pages that send mails to real addresses.

## The Password

Give the password with `SECURITY_DAST_PASSWORD` (a secret of CI) rather than with `--password`, which is
seen in the list of the processes and in the logs of the shell. In the [settings file](configuration.md#the-settings-file),
read it from the environment:

```php
'dast' => [
    'loginUrl' => '/users/login',
    'username' => 'test@example.com',
    'password' => getenv('SECURITY_DAST_PASSWORD') ?: null,
],
```

The password is not written to the report or to the plan of ZAP: ZAP gets it in an environment variable of its
container, by name only, so that it is not on a command line either. The tests of `--end-to-end` and the
application do not get `SECURITY_DAST_PASSWORD`.
