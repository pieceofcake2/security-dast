# アラート

CakePHP 2 アプリケーションで、ZAP がベースラインスキャンとフルスキャンでよく出すアラートと、その意味、修正方法です。報告書の各ページ
にも ZAP の説明と対策があり、[zaproxy.org](https://www.zaproxy.org/docs/alerts/) のアラートのページへのリンクが
あります。

ほとんどのアラートは、アプリケーション全体に一度で修正できます。セキュリティヘッダーは Web サーバーか
[pieceofcake2/security-headers](https://pieceofcake2.github.io/security-headers/ja/) で、
Cookie は `config/core.php` で、エラーページはデバッグレベルで修正します。フルスキャンのアラート (XSS、SQL
インジェクションなど) は、そのページのコードで修正します。同じ箇所は
[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/ja/rules) のルールでも見つけられ、そちらに
さらに多くの修正方法があります。

## 修正できたことを確かめる

1. レスポンスを自分で見ます。たとえば、ページのヘッダーと Cookie です。

   ```bash
   curl -sI https://staging.example.com/ | grep -iE 'content-security|x-frame|x-content-type|set-cookie|x-powered'
   ```

   ブラウザの開発者ツール (Network でリクエストのヘッダー、Application で Cookie) でも同じものが見られます。
2. もう一度スキャンします。報告書からそのアラートが消えます。
3. 本番も確かめます。ステージングや本番に `--url` でベースラインスキャンをします。本番の Web サーバー、プロキシ、PHP は、
   スキャンのものとは違います。

| アラート ID | アラート | リスク | 修正方法 |
| --- | --- | --- | --- |
| 40012、40014、40026 | クロスサイトスクリプティング (反射型、持続型、DOM ベース) | 高 | [XSS](#xss) (フルスキャン) |
| 40018、40019、40020、40021、40022、40024 | SQL インジェクション | 高 | [SQL インジェクション](#sql-インジェクション) (フルスキャン) |
| 6、7 | パストラバーサル、リモートファイルインクルージョン | 高 | [リクエストのパスとファイル](#リクエストのパスとファイル) (フルスキャン) |
| 90020 | リモート OS コマンドインジェクション | 高 | [コマンド](#コマンド) (フルスキャン) |
| 20019 | 外部リダイレクト | 高 | [リダイレクト](#リダイレクト) (フルスキャン) |
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

## XSS

リクエストの値 (パラメーターやフォームの欄) が、エスケープされずにページに出力されています。値に入ったスクリプトが、
ページを開いたユーザーのブラウザで、そのセッションのまま動きます。反射型はそのリクエストのレスポンスに、持続型は
保存されてから別のページに、DOM ベースはページの JavaScript によって出力されるものです。

ビューのすべての値を `h()` でエスケープし、フォームやリンクはエスケープするヘルパーで作ってください。

```php
<!-- 見つかる書き方 -->
<p>Sent: <?php echo $message; ?></p>

<!-- 修正方法 -->
<p>Sent: <?= h($message) ?></p>
```

コードの中のこうした箇所は、[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/ja/rules#cakephp2-view-unescaped-output)
のルール `cakephp2-view-unescaped-output` で見つけられます。DOM ベースの XSS は、JavaScript で `innerHTML` や
`document.write()` を使って値をページに入れないでください。`textContent` を使います。`'unsafe-inline'` のない
Content Security Policy ([セキュリティヘッダー](#セキュリティヘッダー)) も、こうしたスクリプトのほとんどを止めます。

## SQL インジェクション

リクエストの値が、そのまま SQL に入っています。攻撃者がデータベースを読んだり変えたりできます。値をエスケープする
`find()` の条件の配列か、`query()` の値の配列を使ってください。

```php
// 見つかる書き方
$this->Post->query("SELECT * FROM posts WHERE title LIKE '%" . $q . "%'");

// 修正方法
$this->Post->find('all', ['conditions' => ['Post.title LIKE' => '%' . $q . '%']]);
$this->Post->query('SELECT * FROM posts WHERE title LIKE ?', ['%' . $q . '%']);
```

pieceofcake2/security-sast のルール
[cakephp2-query-concatenation](https://pieceofcake2.github.io/security-sast/ja/rules#cakephp2-query-concatenation) と
[cakephp2-conditions-string](https://pieceofcake2.github.io/security-sast/ja/rules#cakephp2-conditions-string) で、
こうした箇所を見つけられます。

## リクエストのパスとファイル

リクエストの値で、読み込むファイルや表示するビューを指定しています。値に `../` を入れると、ほかのファイルに届きます。
値をパスとして使わず、許可した名前の一覧に対応させてください。アップロードされたファイルの名前は `basename()` で
確かめます。ルール [cakephp2-render-request](https://pieceofcake2.github.io/security-sast/ja/rules#cakephp2-render-request)
もご覧ください。

## コマンド

リクエストの値が、システムのコマンド (`exec()`、`shell_exec()`、`system()`、バッククォート) に入っています。攻撃者が
サーバーでコマンドを実行できます。コマンドを使わないようにするか、値を 1 つずつ `escapeshellarg()` で渡してください。

## リダイレクト

リクエストの値をリダイレクト先の URL にしています。サイトのリンクから、ログインページの偽物など、別のサイトに
ユーザーを送れてしまいます。ルートの配列などでアプリケーションのパスにだけリダイレクトするか、URL を一覧と照らして
ください。ルール [cakephp2-redirect-request](https://pieceofcake2.github.io/security-sast/ja/rules#cakephp2-redirect-request)
もご覧ください。

## セキュリティヘッダー

ページにできることを、ブラウザに制限させるヘッダーです。実行してよいスクリプト (CSP)、ほかのサイトがフレームに
入れてよいか (クリックジャッキング)、ファイルの種類を推測してよいか、使ってよい機能やほかのオリジンのリソースを
指定します。

**1 か所だけ**で付けてください。

- **Web サーバーで** (推奨): `webroot/` のファイルや Web サーバー自体のエラーも含め、すべてのレスポンスに付きます。
  nginx と Apache の完全な設定は [Web サーバーの設定](web-server.md) をご覧ください。
- **[pieceofcake2/security-headers](https://pieceofcake2.github.io/security-headers/ja/) で**: Web サーバーを変えられないときや、Content Security Policy を
  アプリケーションと一緒に管理したいときです。ディスパッチャーフィルターが、CakePHP のすべてのレスポンスに付けます。
  リダイレクト、エラーページ、プラグインのファイルにも付き、`webroot/` のファイルには付きません。ポリシーはデフォルトで
  `Content-Security-Policy-Report-Only` で送り、ほかのサイトや Google のタグを名前で許可できます。
  `Strict-Transport-Security` など、Web サーバーで付けるヘッダーはデフォルトでオフです。

```bash
composer require pieceofcake2/security-headers
```

```php
// config/bootstrap.php (app/Config/bootstrap.php)
CakePlugin::load('SecurityHeaders');

Configure::write('Dispatcher.filters', [
    'AssetDispatcher',
    'CacheDispatcher',
    'SecurityHeaders.SecurityHeadersFilter',
]);
```

- **`AppController` で** (プラグインを使わない場合): CakePHP のすべてのページに付き、エラーページ (404、500) にも
  付きます (`CakeErrorController` は `AppController` を継承しています)。プラグインと `webroot/` のファイルには
  付きません。

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

2 か所では付けないでください。`X-Frame-Options` が 2 つになるなど、2 回送ったヘッダーは、ブラウザによっては無効に
なります。`Strict-Transport-Security` は Web サーバーで、ほかはプラグインで、のようにヘッダーごとに分けるのはかまいません。

- **CSP**: `default-src 'self'` は、アプリケーション自身のスクリプト、スタイル、画像だけを許します。インラインの
  スクリプト (`<script>...</script>`、`$this->Html->scriptBlock()`、`onclick="..."`) やほかのサイトのファイルを使う
  ページでは、足りません。スクリプトを `webroot/js/` のファイルに移すか、`script-src 'self' https://cdn.example.com`
  のようにサイトをポリシーに足してください (プラグインでは
  [`contentSecurityPolicyAllow`](https://pieceofcake2.github.io/security-headers/ja/#allowing-other-sites)。Google タグマネージャーと Google アナリティクスの
  ソースや、インラインのスニペットのための nonce もあります)。ポリシーは段階的に導入します。
  1. `Content-Security-Policy-Report-Only` で送ります。何もブロックせず、ブロックするはずのものをブラウザの
     コンソールに出します。
  2. ページを一通り開き (End-to-End テストが開きます)、インラインのスクリプトとスタイルをファイルに移すか、ページに
     必要なサイトを足します。
  3. コンソールに何も出なくなったら、`Content-Security-Policy` で送ります。

  1 と 2 の間も、ZAP はアラート 10038-1「CSP Header Not Set」(中) を出します。報告のみのポリシーは何もブロック
  しないので、ブラウザと同じく ZAP にとっても、ページにポリシーがないのと同じです。あわせて 10038-3「Report-Only
  Header Found」(情報) も出ます。ポリシーを合わせている間スキャンを通すには、[設定ファイル](configuration.md#設定ファイル)
  で 10038-1 だけを理由と一緒に除外し、3 で外してください。

  ```php
  'dast' => [
      'ignore' => ['10038-1' => 'CSP は、コンソールに何も出なくなるまで報告のみのモード'],
  ],
  ```

  除外した 10038-1 も報告書の「除外したアラート」に載り、10038-3 で報告のみのポリシーを送っていることがわかります
  ([アラートを除外する](report.md#アラートを除外する))。
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

  `CookieComponent` にはこのオプションがありません。Web サーバーで付けます。Apache の `Header always edit Set-Cookie`
  か、ほかのサーバーの前に置いた nginx の `proxy_cookie_flags` です ([Web サーバーの設定](web-server.md))。

`session.cookie_httponly` や `session.cookie_secure` を false にしないでください。pieceofcake2/security-sast が設定で
検査します (`cakephp2-config-session-cookie`)。Cookie は `curl -sI` か開発者ツールで確かめます。セッションの各
`Set-Cookie` に、`HttpOnly`、`Secure` (HTTPS のとき)、`SameSite=Lax` が付いています。

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
  (nginx) にします。nginx の後ろの PHP-FPM では、`fastcgi_hide_header X-Powered-By;` で PHP のバージョンも隠せます
  ([Web サーバーの設定](web-server.md))。

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

次のアラートは情報で、ほとんどのアプリケーションで修正する必要はありません。

- **モダン Web アプリケーション**: ページが JavaScript を使っています。`--ajax-spider=1` でそのリンクもクロールします。
- **認証リクエストの特定**: ZAP がログインフォームを見つけました。
- **疑わしいコメント**: HTML や JavaScript のコメントに「TODO」「password」「admin」などの語があります。秘密にすべき
  ことが書かれていないか確かめてください。
- **タイムスタンプの開示**: レスポンスの数字がタイムスタンプに見えます。ふつうは問題ありません。
