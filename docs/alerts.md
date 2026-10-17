# Alerts

The alerts that the baseline scan of ZAP raises most often on a CakePHP 2 application, what they mean, and how
to fix them. Each page of the report also has the description and the solution of ZAP, and a link to the page
of the alert on [zaproxy.org](https://www.zaproxy.org/docs/alerts/).

Most alerts are fixed once for the whole application: the security headers in `AppController` and the web
server, the cookies in `config/core.php`, and the error pages with the debug level.

| Alert ID | Alert | Risk | Fix |
| --- | --- | --- | --- |
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

## Security Headers

The headers that tell the browser to limit what a page can do: which scripts it may run (CSP), whether
another site may frame it (clickjacking), whether it may guess the type of a file, and which features and
cross-origin resources it may use.

Set them for every page of the application in `AppController::beforeFilter()`. The error pages (404, 500) get
them too: `CakeErrorController` extends `AppController`.

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

The files of `webroot/` (CSS, JavaScript, images) are sent by the web server, not by CakePHP: set the headers
there too, for all the responses. With Apache, in `webroot/.htaccess` (`mod_headers`):

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Cross-Origin-Resource-Policy "same-origin"
</IfModule>
```

With nginx, in the `server` block: `add_header X-Content-Type-Options "nosniff" always;`. When the web
server sets a header for all the responses, it can be left out of `AppController`.

- **CSP**: `default-src 'self'` allows the scripts, styles and images of the application only. A page with
  inline scripts (`<script>...</script>`, `$this->Html->scriptBlock()`, `onclick="..."`) or files of other
  sites needs more: move the scripts to files of `webroot/js/`, or add the sites to the policy, e.g.
  `script-src 'self' https://cdn.example.com`. Try a policy first with `Content-Security-Policy-Report-Only`,
  which reports in the console of the browser and blocks nothing.
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

  `CookieComponent` has no option for it; with Apache, add it to all the cookies:
  `Header always edit Set-Cookie "^((?!.*;\s*SameSite=).*)$" "$1; SameSite=Lax"`.

Do not set `session.cookie_httponly` or `session.cookie_secure` to false; pieceofcake2/security-sast checks
this in the configuration (`cakephp2-config-session-cookie`).

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
  `server_tokens off;` (nginx).

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
