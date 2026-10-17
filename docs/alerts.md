# Alerts

The alerts that ZAP raises most often on a CakePHP 2 application, by the baseline and by the full scan, what
they mean, and how to fix them. Each page of the report also has the description and the solution of ZAP, and a link to the page
of the alert on [zaproxy.org](https://www.zaproxy.org/docs/alerts/).

Most alerts are fixed once for the whole application: the security headers in `AppController` and the web
server, the cookies in `config/core.php`, and the error pages with the debug level. The alerts of the full scan
(XSS, SQL injection, ...) are fixed in the code of the page: the same places are found by the rules of
[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/rules), which give more patterns of
the fix.

## Checking a Fix

1. Look at the response yourself, e.g. the headers and the cookies of a page:

   ```bash
   curl -sI https://staging.example.com/ | grep -iE 'content-security|x-frame|x-content-type|set-cookie|x-powered'
   ```

   The developer tools of the browser (Network, then the headers of a request, and Application, then the
   cookies) show the same.
2. Run the scan again: the alert is no longer in the report.
3. Check production too, with a baseline scan of `--url` on staging or production: the web server, the proxy and
   PHP of production are not those of the scan.

| Alert ID | Alert | Risk | Fix |
| --- | --- | --- | --- |
| 40012, 40014, 40026 | Cross Site Scripting (Reflected, Persistent, DOM Based) | High | [XSS](#xss) (full scan) |
| 40018, 40019, 40020, 40021, 40022, 40024 | SQL Injection | High | [SQL injection](#sql-injection) (full scan) |
| 6, 7 | Path Traversal, Remote File Inclusion | High | [Paths and files of the request](#paths-and-files-of-the-request) (full scan) |
| 90020 | Remote OS Command Injection | High | [Commands](#commands) (full scan) |
| 20019 | External Redirect | High | [Redirects](#redirects) (full scan) |
| 10038 | Content Security Policy (CSP) Header Not Set | Medium | [Security headers](#security-headers) |
| 10020 | Missing Anti-clickjacking Header | Medium | [Security headers](#security-headers) |
| 10202 | Absence of Anti-CSRF Tokens | Medium | [Forms without a CSRF token](#forms-without-a-csrf-token) |
| 90003 | Sub Resource Integrity Attribute Missing | Medium | [Scripts of other sites](#scripts-of-other-sites) |
| 10021 | X-Content-Type-Options Header Missing | Low | [Security headers](#security-headers) |
| 10063 | Permissions Policy Header Not Set | Low | [Security headers](#security-headers) |
| 90004 | Cross-Origin-Embedder/Opener/Resource-Policy Header Missing or Invalid | Low | [Security headers](#security-headers) |
| 10035 | Strict-Transport-Security Header Not Set | Low | [Security headers](#security-headers) |
| 10010 | Cookie No HttpOnly Flag | Low | [Cookies](#cookies) |
| 10011 | Cookie Without Secure Flag | Low | [Cookies](#cookies) |
| 10054 | Cookie without SameSite Attribute | Low | [Cookies](#cookies) |
| 10023 | Information Disclosure - Debug Error Messages | Low | [Error pages](#error-pages) |
| 90022 | Application Error Disclosure | Low | [Error pages](#error-pages) |
| 10037 | Server Leaks Information via "X-Powered-By" | Low | [Versions in the headers](#versions-in-the-headers) |
| 10036 | Server Leaks Version Information via "Server" | Low | [Versions in the headers](#versions-in-the-headers) |
| 10017 | Cross-Domain JavaScript Source File Inclusion | Low | [Scripts of other sites](#scripts-of-other-sites) |
| 10049 | Storable and Cacheable Content, Non-Storable Content | Informational | [Cache](#cache) |
| 10109, 10111, 10027, 10096 | Modern Web Application, Authentication Request Identified, Information Disclosure - Suspicious Comments, Timestamp Disclosure | Informational | [Information](#information) |

## XSS

A value of the request (a parameter, a field of a form) is printed in a page without escaping: a script in it
runs in the browser of the user who opens the page, with their session. Reflected: in the response of the
request; persistent: saved, then printed in another page; DOM based: by JavaScript of the page.

Escape every value in the views with `h()`, and build the forms and links with the helpers, which escape:

```php
<!-- Found -->
<p>Sent: <?php echo $message; ?></p>

<!-- Fixed -->
<p>Sent: <?= h($message) ?></p>
```

The rule `cakephp2-view-unescaped-output` of
[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/rules#cakephp2-view-unescaped-output)
finds these places in the code. For the DOM based XSS, do not put values in the page with `innerHTML` or
`document.write()` in JavaScript: use `textContent`. A Content Security Policy without `'unsafe-inline'`
([Security headers](#security-headers)) also blocks most of these scripts.

## SQL Injection

A value of the request goes into SQL as it is: the attacker can read or change the database. Use the
conditions arrays of `find()`, which escape the values, or the values of `query()`:

```php
// Found
$this->Post->query("SELECT * FROM posts WHERE title LIKE '%" . $q . "%'");

// Fixed
$this->Post->find('all', ['conditions' => ['Post.title LIKE' => '%' . $q . '%']]);
$this->Post->query('SELECT * FROM posts WHERE title LIKE ?', ['%' . $q . '%']);
```

pieceofcake2/security-sast finds these places with the rules
[cakephp2-query-concatenation](https://pieceofcake2.github.io/security-sast/rules#cakephp2-query-concatenation)
and [cakephp2-conditions-string](https://pieceofcake2.github.io/security-sast/rules#cakephp2-conditions-string).

## Paths and Files of the Request

A value of the request names a file to read or include, or a view to render: `../` in it reaches other files.
Map the value to a list of allowed names instead of using it as a path, and check uploaded file names with
`basename()`. See the rule
[cakephp2-render-request](https://pieceofcake2.github.io/security-sast/rules#cakephp2-render-request).

## Commands

A value of the request goes into a command of the system (`exec()`, `shell_exec()`, `system()`, backticks):
the attacker runs commands on the server. Avoid the command, or pass each value with `escapeshellarg()`.

## Redirects

A value of the request is the URL of a redirect: a link of the site can send its users to another site, e.g. a
copy of the login page. Redirect only to paths of the application, e.g. with a route array, or check the URL
against a list. See the rule
[cakephp2-redirect-request](https://pieceofcake2.github.io/security-sast/rules#cakephp2-redirect-request).

## Security Headers

The headers that tell the browser to limit what a page can do: which scripts it may run (CSP), whether
another site may frame it (clickjacking), whether it may guess the type of a file, and which features and
cross-origin resources it may use.

Set them in **one** place:

- **In the web server** (recommended): every response gets them, also the files of `webroot/` and the errors of
  the web server. See [Web Server Settings](web-server.md) for complete settings of nginx and Apache.
- **In `AppController`**, when you cannot change the web server: every page of CakePHP gets them, the error pages
  (404, 500) too, as `CakeErrorController` extends `AppController`; the files of `webroot/` do not.

```php
// src/Controller/AppController.php (app/Controller/AppController.php)
public function beforeFilter()
{
    parent::beforeFilter();
    $this->response->header([
        'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self'; form-action 'self'",
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ]);
    if ($this->request->is('ssl')) {
        $this->response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
```

Not in both: a header sent twice, e.g. two `X-Frame-Options`, is invalid for some browsers.

- **CSP**: `default-src 'self'` allows the scripts, styles and images of the application only. A page with
  inline scripts (`<script>...</script>`, `$this->Html->scriptBlock()`, `onclick="..."`) or files of other
  sites needs more: move the scripts to files of `webroot/js/`, or add the sites to the policy, e.g.
  `script-src 'self' https://cdn.example.com`. Introduce a policy step by step:
  1. Send it as `Content-Security-Policy-Report-Only`: it blocks nothing, and the console of the browser lists
     what it would block.
  2. Go through the pages (the End-to-End tests do), and move the inline scripts and styles to files, or add the
     sites that the pages need.
  3. Send it as `Content-Security-Policy` once the console is clean.
- **Cross-Origin-Embedder-Policy** (`require-corp`) blocks the images and scripts of other sites that do not
  allow it. Set it only when the pages use no such resources; the alert of it is low.
- **Strict-Transport-Security** only on HTTPS: a browser keeps it for `max-age` seconds, so make sure every
  page works on HTTPS first.

## Forms without a CSRF Token

A form that changes data (`method="post"`) without a token can be sent by another site in the name of a
logged-in user (CSRF). Load `SecurityComponent` in `AppController`, and build the forms with `FormHelper`:
`$this->Form->create()` and `$this->Form->end()` add the token (`data[_Token][key]`), and ZAP knows this name.

```php
public $components = ['Security', 'Session'];
```

ZAP reports the forms that are written in HTML (`<form method="post">`), or built without `FormHelper`.
Build them with `FormHelper`; for an action that takes requests of other sites, e.g. a webhook, use
`$this->Security->unlockedActions` for that action only. See also the rule
[cakephp2-security-csrf-disabled](https://pieceofcake2.github.io/security-sast/rules#cakephp2-security-csrf-disabled) of
pieceofcake2/security-sast.

## Cookies

- **HttpOnly**: a cookie without it can be read by JavaScript, and stolen by an XSS. The session cookie has
  it by default (`session.cookie_httponly`). For `CookieComponent`, set `$this->Cookie->httpOnly = true;`.
- **Secure**: a cookie without it is also sent over HTTP. The session cookie has it on HTTPS by default
  (`session.cookie_secure`). For `CookieComponent`, set `$this->Cookie->secure = true;` on HTTPS.
- **SameSite**: limits the cookies that are sent with the requests of other sites. For the session, set it in
  `config/core.php`:

  ```php
  Configure::write('Session', [
      'defaults' => 'php',
      'ini' => ['session.cookie_samesite' => 'Lax'],
  ]);
  ```

  `CookieComponent` has no option for it: add it in the web server, with `Header always edit Set-Cookie` of
  Apache, or `proxy_cookie_flags` of nginx in front of another server ([Web Server Settings](web-server.md)).

Do not set `session.cookie_httponly` or `session.cookie_secure` to false; pieceofcake2/security-sast checks
this in the configuration (`cakephp2-config-session-cookie`). Check the cookies with `curl -sI` or in the
developer tools: each `Set-Cookie` of the session shows `HttpOnly`, `Secure` (on HTTPS) and `SameSite=Lax`.

## Error Pages

An error page with a stack trace, the SQL or the paths of the files tells an attacker how the application
works. In production, `debug` must be 0 (`APP_DEBUG` unset or 0): CakePHP then shows the pages of
`templates/Errors/` (`app/View/Errors/`) without the details.

The built-in web server runs with `APP_ENV=test`, where `debug` is usually 0 too. "Application Error
Disclosure" on many pages of the scan means that they failed with an error 500: check the log
(`logs/error.log`), e.g. the test database has no tables.

## Versions in the Headers

- `X-Powered-By: PHP/8.x` comes from PHP: set `expose_php = Off` in `php.ini` of production.
- `Server: Apache/2.4.x (Ubuntu)` comes from the web server: `ServerTokens Prod` (Apache) or
  `server_tokens off;` (nginx). With PHP-FPM behind nginx, `fastcgi_hide_header X-Powered-By;` hides the version
  of PHP too ([Web Server Settings](web-server.md)).

The built-in web server of the scan sends `X-Powered-By` with the `php.ini` of the machine of CI, so this alert
may show there and not in production: check the headers of production, or ignore it in the scan with
`--ignore=10037` when production is set.

## Scripts of Other Sites

A script of another site (a CDN) runs with the rights of the page: if that site is attacked, so is the
application. Serve the files from `webroot/js/`, or give their hash with `integrity` (Subresource
Integrity), which the browser checks:

```php
echo $this->Html->script('https://cdn.example.com/lib.min.js', [
    'integrity' => 'sha384-...',
    'crossorigin' => 'anonymous',
]);
```

The hash is on the page of the file of the CDN, or `openssl dgst -sha384 -binary lib.min.js | openssl base64 -A`.

## Cache

"Storable and Cacheable Content" is informational: a page may be kept by a proxy. For the pages of a
logged-in user, send `Cache-Control: no-store` with `$this->response->disableCache()` in the controller,
e.g. in `beforeFilter()` when the user is logged in.

## Information

These alerts are informational and need no fix in most applications:

- **Modern Web Application**: the pages use JavaScript; `--ajax-spider=1` crawls their links.
- **Authentication Request Identified**: ZAP found a login form.
- **Suspicious Comments**: a comment in HTML or JavaScript has a word such as "TODO", "password" or "admin";
  check that it does not tell anything that should be secret.
- **Timestamp Disclosure**: a number in a response looks like a timestamp; usually harmless.
