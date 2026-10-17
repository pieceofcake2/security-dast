# Web サーバーの設定

ベースラインスキャンのアラートのほとんどは、本番の Web サーバーで修正します。すべてのレスポンス (CakePHP のページ、
`webroot/` のファイル、エラー) のセキュリティヘッダー、ヘッダーのバージョン、HTTPS へのリダイレクトです。このページでは、
CakePHP 2 アプリケーションのための nginx と Apache の完全な設定を示します。nginx 1.28 と Apache 2.4 で確かめてあります。

## ヘッダーを付ける場所

セキュリティヘッダーは **1 か所だけ**で付けてください。

- **Web サーバー** (このページ、推奨): すべてのレスポンスに付きます。CakePHP に届かない `webroot/` のファイル (CSS、
  JavaScript、画像) や、Web サーバー自体のエラーにも付きます。
- **`AppController`** ([セキュリティヘッダー](alerts.md#セキュリティヘッダー)): 共用のホスティングなど、Web サーバーを
  変えられないときです。`webroot/` のファイルには付きません。

両方では付けないでください。`X-Frame-Options` が 2 つになるなど、2 回送ったヘッダーはブラウザによっては無効になり、
`Content-Security-Policy` が 2 つあると両方が効いて、厳しいほうが勝ちます。

Content Security Policy は、本番の前にアプリケーションに合わせてください。まず `Content-Security-Policy-Report-Only`
で始めます ([セキュリティヘッダー](alerts.md#セキュリティヘッダー))。

## nginx

PHP-FPM で、HTTPS だけの構成です。ヘッダーは snippet にまとめ、自分の `add_header` を持つ `location` でもう一度
include できるようにします。nginx は、`add_header` のある `location` では、`server` の `add_header` を捨てます。

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

それぞれの設定の意味です。

| | |
| --- | --- |
| `return 301 https://...` | HTTP のリクエストはすべて HTTPS に送り、Cookie やフォームを暗号化せずに送らないようにします |
| `root .../webroot` | `webroot/` だけを公開します。設定、コード、`vendor/`、`.env` には届きません |
| `server_tokens off` | `Server` ヘッダーと nginx のエラーページを、バージョンなしの `nginx` にします (アラート 10036) |
| `include .../security-headers.conf` | すべてのレスポンスのセキュリティヘッダー (アラート 10038、10020、10021、10063、90004、10035) |
| `always` | ZAP も確かめるエラー (404、500) にもヘッダーを付けます |
| `fastcgi_param HTTPS on` | CakePHP がリクエストを HTTPS と見て、セッションの Cookie に `Secure` が付きます |
| `fastcgi_hide_header X-Powered-By` | PHP のバージョンを出しません (アラート 10037) |
| `location ~ \.php$ { return 404; }` | CakePHP のフロントコントローラーだけを実行し、`webroot/` のほかの PHP ファイルは実行しません |
| `location ~ /\.` | 誤ってコピーした `.env` や `.git` などの隠しファイルを拒否します |

`nginx -t` で確かめてから再読み込みし、ヘッダーを見ます。

```bash
curl -sI https://example.com/ | grep -iE 'content-security|x-frame|x-content-type|strict-transport|x-powered|^server'
curl -sI https://example.com/css/cake.css | grep -iE 'x-content-type|cache-control'
curl -sI https://example.com/.env | head -1   # HTTP/2 403
```

### ほかのサーバーの前の nginx

nginx が PHP のサーバーの前のリバースプロキシ (`proxy_pass`) なら、`proxy_hide_header` で PHP のバージョンを隠し、
アプリケーションが付けない Cookie のフラグを `proxy_cookie_flags` (nginx 1.19.3 以降) で足します。

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_hide_header X-Powered-By;
    proxy_cookie_flags ~ secure httponly samesite=lax;
}
```

PHP-FPM (`fastcgi_pass`) では、nginx は Cookie を変えられません。`SameSite` は CakePHP で設定してください
([Cookie](alerts.md#cookie))。

## Apache

バーチャルホストの設定です。ルーティング (mod_rewrite) には CakePHP の `webroot/.htaccess` をそのまま使い、ヘッダーは
Apache のエラーにも効くバーチャルホストに書きます。

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

さらに、`Server` ヘッダーとエラーページのバージョンのために (アラート 10036)、Apache の全体の設定
(`/etc/apache2/conf-available/security.conf` など) に書きます。

```apache
ServerTokens Prod
ServerSignature Off
```

`apachectl configtest` で確かめてから再読み込みし、nginx と同じようにヘッダーを見ます。セッションの `Set-Cookie` に
`SameSite=Lax` が付き、すでに `SameSite` のある Cookie はそのままです。

## PHP

Web サーバーに関係なく、本番の `php.ini` で設定します。

```ini
expose_php = Off          ; no X-Powered-By (alert 10037)
display_errors = Off      ; no error of PHP in the pages (alert 10023), with debug 0 of CakePHP
log_errors = On
```

`session.cookie_httponly`、`session.cookie_secure`、`session.cookie_samesite` は、CakePHP が `Session` の設定から
設定します ([Cookie](alerts.md#cookie))。
