# Web Server Settings

Most alerts of the baseline scan are fixed in the web server of production: the security headers of every
response (the pages of CakePHP, the files of `webroot/` and the errors), the versions in the headers, and the
redirect to HTTPS. This page gives complete settings of nginx and Apache for a CakePHP 2 application, which were
checked with nginx 1.28 and Apache 2.4.

## Where to Set the Headers

Set the security headers in **one** place:

- **The web server** (this page, recommended): every response gets them, also the files of `webroot/` (CSS,
  JavaScript, images) and the errors of the web server itself, which never reach CakePHP.
- **The application**, when you cannot change the web server, e.g. a shared hosting: with
  [pieceofcake2/security-headers](https://pieceofcake2.github.io/security-headers/), which adds them to every response of CakePHP, or by hand in
  `AppController` ([Security Headers](alerts.md#security-headers)). The files of `webroot/` then miss them.

Not both for the same header: a header sent twice, e.g. two `X-Frame-Options`, is invalid for some browsers, and
two `Content-Security-Policy` headers both apply, so the stricter one wins. Splitting them is fine, e.g. the
Content Security Policy with the plugin, which allows the sites that the application needs, and the others below
in the web server: remove those lines from the snippet, and turn off the same headers in the plugin.

Adapt the Content Security Policy to the application before production: start with
`Content-Security-Policy-Report-Only` ([Security Headers](alerts.md#security-headers)).

## nginx

With PHP-FPM, on HTTPS only. Keep the headers in a snippet, so that every `location` that adds a header of its own
can include them again: nginx drops the `add_header` of the `server` in a `location` that has an `add_header`.

```nginx
# /etc/nginx/snippets/security-headers.conf: the security headers of every response, the pages of CakePHP, the
# files of webroot/ and the errors (always). Include it in the server, and again in each location that has an
# add_header of its own: nginx drops the add_header of the server in such a location.
add_header Content-Security-Policy "default-src 'self'; frame-ancestors 'self'; form-action 'self'" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Cross-Origin-Opener-Policy "same-origin" always;
add_header Cross-Origin-Resource-Policy "same-origin" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

```nginx
# /etc/nginx/conf.d/app.conf: a CakePHP 2 application (pieceofcake2/app) with PHP-FPM, on HTTPS only

# HTTP: redirect everything to HTTPS
server {
    listen 80;
    server_name example.com;
    server_tokens off;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name example.com;

    ssl_certificate /etc/ssl/certs/example.com.pem;
    ssl_certificate_key /etc/ssl/private/example.com.key;

    # The document root: webroot/ of the project (app/webroot/ in the legacy layout), never the project itself
    root /var/www/app/webroot;
    index index.php;

    # No version of nginx in the Server header and the error pages
    server_tokens off;

    include /etc/nginx/snippets/security-headers.conf;

    # The files of webroot/ as they are, the other requests to CakePHP (as webroot/.htaccess does)
    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # The assets: cached by the browsers. This add_header drops those of the server, so include them again
    location ~* \.(?:css|js|png|jpe?g|gif|svg|ico|woff2?)$ {
        include /etc/nginx/snippets/security-headers.conf;
        add_header Cache-Control "public, max-age=604800" always;
        try_files $uri /index.php?$args;
    }

    # PHP: index.php of webroot/ only (and test.php, which only runs with debug)
    location ~ ^/(index|test)\.php(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        # No version of PHP in the X-Powered-By header (or expose_php = Off in php.ini)
        fastcgi_hide_header X-Powered-By;
    }

    # No other PHP file, and no hidden file (.htaccess, .env, .git)
    location ~ \.php$ {
        return 404;
    }
    location ~ /\. {
        deny all;
    }
}
```

What it does:

| | |
| --- | --- |
| `return 301 https://...` | Every request of HTTP goes to HTTPS, so that the cookies and the forms are never sent in clear |
| `root .../webroot` | Only `webroot/` is public: the configuration, the code, `vendor/` and `.env` are not reachable |
| `server_tokens off` | The `Server` header and the error pages of nginx say `nginx`, without its version (alert 10036) |
| `include .../security-headers.conf` | The security headers of every response (alerts 10038, 10020, 10021, 10063, 90004, 10035) |
| `always` | The headers are also sent with the errors (404, 500), which ZAP checks too |
| `fastcgi_param HTTPS on` | CakePHP sees the request as HTTPS: the session cookie gets `Secure` |
| `fastcgi_hide_header X-Powered-By` | No version of PHP (alert 10037) |
| `location ~ \.php$ { return 404; }` | Only the front controller of CakePHP runs; another PHP file of `webroot/` is not run |
| `location ~ /\.` | Hidden files, e.g. a `.env` or a `.git` copied by mistake, are refused |

Check it with `nginx -t`, reload, then look at the headers:

```bash
curl -sI https://example.com/ | grep -iE 'content-security|x-frame|x-content-type|strict-transport|x-powered|^server'
curl -sI https://example.com/css/cake.css | grep -iE 'x-content-type|cache-control'
curl -sI https://example.com/.env | head -1   # HTTP/2 403
```

### nginx in Front of Another Server

When nginx is a reverse proxy in front of the server of PHP (`proxy_pass`), hide the version of PHP with
`proxy_hide_header`, and add the flags of the cookies that the application does not set with
`proxy_cookie_flags` (nginx 1.19.3+):

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_hide_header X-Powered-By;
    proxy_cookie_flags ~ secure httponly samesite=lax;
}
```

With PHP-FPM (`fastcgi_pass`), nginx cannot change the cookies: set `SameSite` in CakePHP
([Cookies](alerts.md#cookies)).

## Apache

The settings of the virtual host. Keep `webroot/.htaccess` of CakePHP for the routing (mod_rewrite), and put the
headers in the virtual host, which also covers the errors of Apache:

```apache
# /etc/apache2/sites-available/app.conf: a CakePHP 2 application (pieceofcake2/app), on HTTPS only
# Needs mod_ssl, mod_headers and mod_rewrite (a2enmod ssl headers rewrite)

<VirtualHost *:80>
    ServerName example.com
    Redirect permanent / https://example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName example.com
    DocumentRoot /var/www/app/webroot

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/example.com.key

    <Directory /var/www/app/webroot>
        AllowOverride All
        Require all granted
    </Directory>

    # The security headers of every response, the pages of CakePHP, the files of webroot/ and the errors
    Header always set Content-Security-Policy "default-src 'self'; frame-ancestors 'self'; form-action 'self'"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    Header always set Cross-Origin-Opener-Policy "same-origin"
    Header always set Cross-Origin-Resource-Policy "same-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

    # No version of PHP
    Header always unset X-Powered-By
    Header unset X-Powered-By

    # SameSite for the cookies that do not have it
    Header always edit Set-Cookie "^((?!.*;\s*SameSite=).*)$" "$1; SameSite=Lax"
</VirtualHost>
```

And in the main configuration of Apache (e.g. `/etc/apache2/conf-available/security.conf`), for the versions in
the `Server` header and the error pages (alert 10036):

```apache
ServerTokens Prod
ServerSignature Off
```

Check it with `apachectl configtest`, reload, and look at the headers as for nginx. The `Set-Cookie` of the
session then has `SameSite=Lax`, and a cookie that already has a `SameSite` keeps it.

## PHP

In the `php.ini` of production, whatever the web server:

```ini
expose_php = Off          ; no X-Powered-By (alert 10037)
display_errors = Off      ; no error of PHP in the pages (alert 10023), with debug 0 of CakePHP
log_errors = On
```

`session.cookie_httponly`, `session.cookie_secure` and `session.cookie_samesite` are set by CakePHP from its
`Session` configuration ([Cookies](alerts.md#cookies)).
