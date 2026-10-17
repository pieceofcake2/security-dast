# アラート

CakePHP 2 アプリケーションで、ZAP のベースラインスキャンがよく出すアラートと、その意味、直し方です。報告書の各ページ
にも ZAP の説明と対策があり、[zaproxy.org](https://www.zaproxy.org/docs/alerts/) のアラートのページへのリンクが
あります。

ほとんどのアラートは、アプリケーション全体に一度で直せます。セキュリティヘッダーは `AppController` と Web サーバーで、
Cookie は `config/core.php` で、エラーページはデバッグレベルで直します。

| アラート ID | アラート | リスク | 直し方 |
| --- | --- | --- | --- |
| 10038 | Content Security Policy (CSP) ヘッダーが設定されていない | 中 | [セキュリティヘッダー](#セキュリティヘッダー) |
| 10020 | クリックジャッキング対策ヘッダーの欠如 | 中 | [セキュリティヘッダー](#セキュリティヘッダー) |
| 10202 | Anti-CSRF トークンが使用されていない | 中 | [CSRF トークンのないフォーム](#csrf-トークンのないフォーム) |
| 90003 | サブリソース整合性属性の欠落 | 中 | [ほかのサイトのスクリプト](#ほかのサイトのスクリプト) |
| 10021 | X-Content-Type-Options ヘッダーがありません | 低 | [セキュリティヘッダー](#セキュリティヘッダー) |
| 10063 | Permissions-Policy ヘッダー未設定 | 低 | [セキュリティヘッダー](#セキュリティヘッダー) |
| 90004 | Cross-Origin-Embedder/Opener/Resource-Policy ヘッダーの欠落または無効 | 低 | [セキュリティヘッダー](#セキュリティヘッダー) |
| 10035 | Strict-Transport-Security ヘッダーが設定されていない | 低 | [セキュリティヘッダー](#セキュリティヘッダー) |
| 10010 | Cookie に HttpOnly フラグがない | 低 | [Cookie](#cookie) |
| 10011 | Secure フラグのない Cookie | 低 | [Cookie](#cookie) |
| 10054 | SameSite 属性のない Cookie | 低 | [Cookie](#cookie) |
| 10023 | 情報漏洩 - デバッグエラーメッセージ | 低 | [エラーページ](#エラーページ) |
| 90022 | アプリケーションエラーの露見 | 低 | [エラーページ](#エラーページ) |
| 10037 | "X-Powered-By" による情報の漏洩 | 低 | [ヘッダーのバージョン](#ヘッダーのバージョン) |
| 10036 | "Server" によるバージョン情報の漏洩 | 低 | [ヘッダーのバージョン](#ヘッダーのバージョン) |
| 10017 | クロスドメイン JavaScript ソースファイルのインクルージョン | 低 | [ほかのサイトのスクリプト](#ほかのサイトのスクリプト) |
| 10049 | 保存可能でキャッシュ可能なコンテンツ、保存不可能なコンテンツ | 情報 | [キャッシュ](#キャッシュ) |
| 10109、10111、10027、10096 | モダン Web アプリケーション、認証リクエストの特定、疑わしいコメント、タイムスタンプの開示 | 情報 | [情報](#情報) |

アラートの名前は、ZAP の日本語訳とは少し違うことがあります。ID で探してください。

## セキュリティヘッダー

ページにできることを、ブラウザに制限させるヘッダーです。実行してよいスクリプト (CSP)、ほかのサイトがフレームに
入れてよいか (クリックジャッキング)、ファイルの種類を推測してよいか、使ってよい機能やほかのオリジンのリソースを
指定します。

アプリケーションのすべてのページに、`AppController::beforeFilter()` で付けます。エラーページ (404、500) にも付きます。
`CakeErrorController` は `AppController` を継承しているからです。

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

`webroot/` のファイル (CSS、JavaScript、画像) は CakePHP ではなく Web サーバーが返します。そちらでも、すべての
レスポンスにヘッダーを付けてください。Apache では `webroot/.htaccess` に書きます (`mod_headers`)。

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Cross-Origin-Resource-Policy "same-origin"
</IfModule>
```

nginx では `server` ブロックに `add_header X-Content-Type-Options "nosniff" always;` と書きます。Web サーバーが
すべてのレスポンスに付けるヘッダーは、`AppController` から省けます。

- **CSP**: `default-src 'self'` は、アプリケーション自身のスクリプト、スタイル、画像だけを許します。インラインの
  スクリプト (`<script>...</script>`、`$this->Html->scriptBlock()`、`onclick="..."`) やほかのサイトのファイルを使う
  ページでは、足りません。スクリプトを `webroot/js/` のファイルに移すか、`script-src 'self' https://cdn.example.com`
  のようにサイトをポリシーに足してください。ポリシーは、まず `Content-Security-Policy-Report-Only` で試せます。
  ブラウザのコンソールに報告するだけで、何もブロックしません。
- **Cross-Origin-Embedder-Policy** (`require-corp`) は、許可していないほかのサイトの画像やスクリプトをブロックします。
  そうしたリソースを使わないページでだけ付けてください。このアラートのリスクは低です。
- **Strict-Transport-Security** は HTTPS のときだけ付けます。ブラウザは `max-age` 秒のあいだ覚えているので、先に
  すべてのページが HTTPS で動くことを確かめてください。

## CSRF トークンのないフォーム

データを変えるフォーム (`method="post"`) にトークンがないと、ほかのサイトがログイン中のユーザーになりすまして送信
できます (CSRF)。`AppController` で `SecurityComponent` を読み込み、フォームを `FormHelper` で作ってください。
`$this->Form->create()` と `$this->Form->end()` がトークン (`data[_Token][key]`) を入れます。ZAP はこの名前を知って
います。

```php
public $components = ['Security', 'Session'];
```

ZAP が指摘するのは、HTML で書いたフォーム (`<form method="post">`) や、`FormHelper` を使わずに作ったフォームです。
`FormHelper` で作ってください。Webhook のように、ほかのサイトからのリクエストを受けるアクションは、そのアクション
だけを `$this->Security->unlockedActions` に入れます。pieceofcake2/security-sast のルール
[cakephp2-security-csrf-disabled](https://pieceofcake2.github.io/security-sast/ja/rules#cakephp2-security-csrf-disabled)
もご覧ください。

## Cookie

- **HttpOnly**: ないと、JavaScript から Cookie を読めて、XSS で盗まれます。セッションの Cookie にはデフォルトで
  付きます (`session.cookie_httponly`)。`CookieComponent` では `$this->Cookie->httpOnly = true;` にします。
- **Secure**: ないと、HTTP でも Cookie が送られます。セッションの Cookie には HTTPS のときデフォルトで付きます
  (`session.cookie_secure`)。`CookieComponent` では、HTTPS のとき `$this->Cookie->secure = true;` にします。
- **SameSite**: ほかのサイトからのリクエストで送る Cookie を制限します。セッションでは `config/core.php` で設定します。

  ```php
  Configure::write('Session', [
      'defaults' => 'php',
      'ini' => ['session.cookie_samesite' => 'Lax'],
  ]);
  ```

  `CookieComponent` にはこのオプションがありません。Apache なら、すべての Cookie に付けられます。
  `Header always edit Set-Cookie "^((?!.*;\s*SameSite=).*)$" "$1; SameSite=Lax"`

`session.cookie_httponly` や `session.cookie_secure` を false にしないでください。pieceofcake2/security-sast が設定で
検査します (`cakephp2-config-session-cookie`)。

## エラーページ

スタックトレース、SQL、ファイルのパスを出すエラーページは、アプリケーションのしくみを攻撃者に教えます。本番では
`debug` を 0 にします (`APP_DEBUG` を設定しないか 0)。CakePHP は、詳細を出さない `templates/Errors/`
(`app/View/Errors/`) のページを表示します。

組み込みウェブサーバーは `APP_ENV=test` で動き、`debug` はふつう 0 です。スキャンで多くのページに「アプリケーション
エラーの露見」が出るときは、それらのページがエラー 500 になっています。ログ (`logs/error.log`) を確かめてください。
テストのデータベースにテーブルがない、などです。

## ヘッダーのバージョン

- `X-Powered-By: PHP/8.x` は PHP が付けます。本番の `php.ini` で `expose_php = Off` にします。
- `Server: Apache/2.4.x (Ubuntu)` は Web サーバーが付けます。`ServerTokens Prod` (Apache) か `server_tokens off;`
  (nginx) にします。

スキャンの組み込みウェブサーバーは CI のマシンの `php.ini` で動くので、本番では出ないのにスキャンでは `X-Powered-By`
が出ることがあります。本番のヘッダーを確かめ、本番を設定したならスキャンでは `--ignore=10037` で除外してください。

## ほかのサイトのスクリプト

ほかのサイト (CDN) のスクリプトは、ページと同じ権限で動きます。そのサイトが攻撃されると、アプリケーションも
攻撃されます。ファイルを `webroot/js/` から返すか、`integrity` でハッシュを指定してください (サブリソース整合性)。
ブラウザがハッシュを確かめます。

```php
echo $this->Html->script('https://cdn.example.com/lib.min.js', [
    'integrity' => 'sha384-...',
    'crossorigin' => 'anonymous',
]);
```

ハッシュは CDN のファイルのページにあるか、`openssl dgst -sha384 -binary lib.min.js | openssl base64 -A` で作れます。

## キャッシュ

「保存可能でキャッシュ可能なコンテンツ」は情報です。プロキシがページを保存するかもしれません。ログイン中のユーザー
のページでは、コントローラーで `$this->response->disableCache()` を呼んで `Cache-Control: no-store` を送ってください。
ログイン中なら `beforeFilter()` で呼ぶ、などです。

## 情報

次のアラートは情報で、ほとんどのアプリケーションで直す必要はありません。

- **モダン Web アプリケーション**: ページが JavaScript を使っています。`--ajax-spider=1` でそのリンクもクロールします。
- **認証リクエストの特定**: ZAP がログインフォームを見つけました。
- **疑わしいコメント**: HTML や JavaScript のコメントに「TODO」「password」「admin」などの語があります。秘密にすべき
  ことが書かれていないか確かめてください。
- **タイムスタンプの開示**: レスポンスの数字がタイムスタンプに見えます。ふつうは問題ありません。
