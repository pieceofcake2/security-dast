# 設定

それぞれの設定は、コマンドのオプション、環境変数、設定ファイルのキーで指定します。オプションが環境変数より、環境変数が
設定ファイルより優先されます。

## オプション

### 何をスキャンするか

| オプション | 環境変数 | デフォルト | |
| --- | --- | --- | --- |
| `--project=DIR` | | カレントディレクトリ | アプリケーションのディレクトリ |
| `--url=URL` | `SECURITY_DAST_URL` | | 組み込みウェブサーバーの代わりにスキャンする、起動済みのアプリケーションの URL |
| `--webroot=DIR` | `SECURITY_DAST_WEBROOT` | アプリケーションの `WWW_ROOT` | 組み込みウェブサーバーのドキュメントルート。プロジェクトからの相対パス |
| `--app-env=ENV` | `SECURITY_DAST_APP_ENV` | `test` | 組み込みウェブサーバー (と End-to-End テスト) の `APP_ENV` |
| `--exclude=LIST` | `SECURITY_DAST_EXCLUDE` | `.*logout.*` | 除く URL の正規表現 (Java のもの)。カンマ区切り |

### どうスキャンするか

| オプション | 環境変数 | デフォルト | |
| --- | --- | --- | --- |
| `--scan=SCAN` | `SECURITY_DAST_SCAN` | `baseline` | `baseline`、またはページも攻撃する `full` ([スキャンの種類](scans.md)) |
| `--scan-minutes=N` | `SECURITY_DAST_SCAN_MINUTES` | `10` | フルスキャンの攻撃の最大の分数 |
| `--minutes=N` | `SECURITY_DAST_MINUTES` | `1` | サイトをクロールする分数。クロールしない `--end-to-end` では使いません |
| `--ajax-spider=1` | `SECURITY_DAST_AJAX_SPIDER` | | JavaScript が作るリンクのために、ブラウザでもクロールします。`--end-to-end` では使いません |
| `--end-to-end=COMMAND` | `SECURITY_DAST_END_TO_END` | | クロールの代わりにスキャンする End-to-End テストのコマンド ([End-to-End テスト](end-to-end.md)) |
| `--login-url=PATH` | `SECURITY_DAST_LOGIN_URL` | | ユーザーとしてスキャンするときのログインページ。パスか URL ([ログイン](login.md)) |
| `--username=NAME` | `SECURITY_DAST_USERNAME` | | ログインするユーザー |
| `--password=PASSWORD` | `SECURITY_DAST_PASSWORD` | | そのパスワード。環境変数で渡してください |

### 結果

| オプション | 環境変数 | デフォルト | |
| --- | --- | --- | --- |
| `--pdf=PATH` | `SECURITY_DAST_PDF` | `tmp/security-dast.pdf` | PDF の報告書 |
| `--fail-on=RISK` | `SECURITY_DAST_FAIL_ON` | `high` | 不合格にする最も低いリスク。`high`、`medium`、`low`、`info`、`none` |
| `--ignore=LIST` | `SECURITY_DAST_IGNORE` | | 不合格にしないアラート ID。カンマ区切り。報告書には載ります ([アラートを除外する](report.md#アラートを除外する)) |
| `--language=LANG` | `SECURITY_REPORT_LANGUAGE` | `en` | `en` か `ja`、または `--locale-dir` の言語 |
| `--locale-dir=DIR` | `SECURITY_REPORT_LOCALE_DIR` | | メッセージを足したり置き換えたりする `<言語>.json` のディレクトリ |
| `--title=TITLE` | `SECURITY_DAST_TITLE` | 言語のタイトル | 報告書のタイトル |
| `--project-name=NAME` | `SECURITY_REPORT_PROJECT` | `composer.json` の名前 | タイトルの下のプロジェクト名 |
| `--note=TEXT` | `SECURITY_DAST_NOTE` | | 表紙の注記 |

### ZAP と Docker

| オプション | 環境変数 | デフォルト | |
| --- | --- | --- | --- |
| `--image=IMAGE` | `SECURITY_DAST_IMAGE` | `ghcr.io/zaproxy/zaproxy:stable` | ZAP の Docker イメージ。バージョンを固定するときは `ghcr.io/zaproxy/zaproxy:2.17.0` など |
| `--docker=COMMAND` | `SECURITY_DAST_DOCKER` | `docker` | Docker のコマンド。`podman` など |
| `--zap-options=OPTIONS` | `SECURITY_DAST_ZAP_OPTIONS` | | ZAP のほかのオプション。空白区切り。`-config spider.maxDepth=5` など |
| `--settings=FILE` | `SECURITY_SETTINGS` | `config/security.php` か `app/Config/security.php` | 設定ファイル |

`--pdf`、`--settings`、`--locale-dir`、報告書の `dir` のパスは、プロジェクトではなくカレントディレクトリからの相対
パスです。プロジェクトで実行するか、絶対パスで指定してください。`--webroot` はプロジェクトからの相対パスです。

PDF を印刷する Chrome は、[pieceofcake2/report](https://pieceofcake2.github.io/report/ja/) の `REPORT_CHROME_BINARY` と
`REPORT_NO_SANDBOX` で指定します。

## 設定ファイル

pieceofcake2 のセキュリティ診断の設定は、プロジェクトの `config/security.php` (従来の構成では `app/Config/security.php`)
にまとめられます。診断ごとのセクション `sca`、`sast`、`dast` と、共通の `report` セクションを持つ配列を返します。

```php
<?php
// config/security.php
return [
    'report' => [
        'language' => 'ja',
        'dir' => 'tmp/security',
        'project' => 'Acme',
    ],
    'dast' => [
        'scan' => 'full',
        'scanMinutes' => 20,
        'failOn' => 'medium',
        'endToEnd' => 'vendor/bin/phpunit --testsuite EndToEnd',
        'loginUrl' => '/users/login',
        'username' => 'test@example.com',
        'password' => getenv('SECURITY_DAST_PASSWORD') ?: null,
        'exclude' => ['.*/users/logout.*', '.*/users/delete.*'],
        'ignore' => ['10049' => '公開のページはキャッシュされてよい'],
    ],
];
```

- `dast` セクションのキーは、オプションをキャメルケースにしたものです (`--fail-on` は `failOn`、`--end-to-end` は
  `endToEnd`)。リストは配列で書きます。知らないキーはエラーになります。
- `report` セクションには `language`、`localeDir`、`project` (プロジェクト名)、`note` と、報告書のディレクトリ `dir` が
  あります。この例では `tmp/security/security-dast.pdf` に書き出します。
- ファイルは PHP です。秘密は環境変数から読み、ファイルはコミットしてください。

ファイルの設定は、オプションや環境変数を指定しなければ使われます。たとえば、毎晩のフルスキャンの設定のまま、pull
request ではベースラインスキャンをします。

```bash
SECURITY_DAST_SCAN=baseline vendor/bin/security-dast
```
