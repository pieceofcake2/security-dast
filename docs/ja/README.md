# Security DAST ドキュメント

CakePHP 2 アプリケーションの動的アプリケーションセキュリティテスト (DAST) です。アプリケーションを起動して
[OWASP ZAP](https://www.zaproxy.org/) でスキャンし、アラートをリスクごとの A4 の PDF の報告書にします。報告書は
[pieceofcake2/report](https://pieceofcake2.github.io/report/ja/) のデザインです。あるリスク以上のアラートがあれば、
終了コードで CI を失敗にします。

- [スキャンの種類](scans.md): ベースラインスキャンとフルスキャンがすること、どこで何を実行するか
- [ログインしてスキャンする](login.md)
- [End-to-End テストをシナリオにする](end-to-end.md)
- [設定](configuration.md): オプション、環境変数、設定ファイル
- [報告書](report.md): 読み方と、アラートの除外
- [アラート](alerts.md): 各アラートの意味と、CakePHP 2 アプリケーションでの修正方法
- [GitHub Actions での CI](ci.md)
- [トラブルシューティング](troubleshooting.md)

pieceofcake2 のセキュリティ診断の 1 つで、ほかに [pieceofcake2/security-sca](https://pieceofcake2.github.io/security-sca/ja/)
(Composer のパッケージの既知の脆弱性) と [pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/ja/)
(ソースコード) があります。pieceofcake2/security-check で、すべてを実行できます。

## 見つけるもの

DAST は、ブラウザや攻撃者と同じように、アプリケーションを外から見ます。どのコードが作ったかに関係なく、PHP や
Web サーバーの設定も含めて、レスポンスに表れるものを見つけます。

| | 例 |
| --- | --- |
| ベースラインスキャン (デフォルト) | セキュリティヘッダーの不足 (CSP、クリックジャッキング対策、`X-Content-Type-Options` など)、`HttpOnly`、`Secure`、`SameSite` のない Cookie、CSRF トークンのないフォーム、デバッグ情報を出すエラーページ、ヘッダーのバージョン、integrity のないほかのサイトのスクリプト |
| フルスキャン (`--scan=full`) | 同じものと、攻撃して表れるもの。XSS (反射型、持続型、DOM ベース)、SQL インジェクション、パストラバーサル、コマンドインジェクション、オープンリダイレクトなど |

レスポンスに表れないものは見つけません。スキャンが届かないページの脆弱性、あるユーザーがほかのユーザーのデータに
アクセスできるか (アプリケーションのルール)、どのリクエストでも表れないコードの弱さなどです。コードは
[pieceofcake2/security-sast](https://pieceofcake2.github.io/security-sast/ja/) で調べます。3 つの診断を組み合わせると、
それぞれ単独よりも広く調べられます。

## クイックスタート

```bash
composer require --dev pieceofcake2/security-dast

# PHP の組み込みウェブサーバーとテストのデータベースで、アプリケーションをベースラインスキャン
vendor/bin/security-dast --pdf=tmp/security-dast.pdf --language=ja
```

コマンドは、アプリケーションを `APP_ENV=test` で起動し、Docker の ZAP でスキャンして、まとめを出力します。

```text
17 alerts (4 medium, 10 low, 3 info) on 7 URLs of http://127.0.0.1:56557 (ZAP 2.17.0). Report: tmp/security-dast.pdf
```

終了コードは、`--fail-on` (デフォルト `high`) に達するアラートがなければ `0`、あれば `1`、ウェブサーバー、ZAP、
報告書のどれかが失敗したら `2` です。

続けて、順に進めます。

1. ベースラインスキャンのアラートを修正します。ほとんどは、`AppController` と Web サーバーで、アプリケーション全体に
   一度で修正できます ([アラート](alerts.md))。
2. ユーザーとしてスキャンし、ログインの先のページにも届くようにします ([ログインしてスキャンする](login.md))。
3. End-to-End テストでフルスキャンを、CI で毎晩実行します ([End-to-End テストをシナリオにする](end-to-end.md)、
   [CI](ci.md))。

## しくみ

1. プロジェクトのアプリケーションを、PHP の組み込みウェブサーバーで `127.0.0.1` に `APP_ENV=test` で起動します。
   アプリケーションは `config/.env.test` を読み、テストのデータベースを使います。ドキュメントルートはアプリケーションから
   取ります (`config/define.php` の `WWW_ROOT`、または `webroot/`、従来の構成では `app/webroot/`)。ルータースクリプトが、
   `webroot/.htaccess` と同じようにリクエストを `index.php` に渡します。
2. ZAP を Docker (`ghcr.io/zaproxy/zaproxy:stable`) で、Automation Framework の plan で実行します。サイトをクロール
   するか End-to-End テストを記録して、各リクエストとレスポンスを確かめます。フルスキャンでは、続けて見つけたページと
   パラメーターを攻撃します ([スキャンの種類](scans.md))。
3. ZAP の JSON の結果からアラートを読み、報告書の言語で PDF にします。`--language=ja` では、ZAP がアラートを日本語で
   書きます ([報告書](report.md))。

## 必要なもの

- PHP 8.0 以上
- Docker。ZAP のイメージ (約 1.5 GB) は初回に取得します。Linux ではコンテナがホストのネットワーク (`--network host`)
  を使い、macOS と Windows (Docker Desktop) では `host.docker.internal` からアプリケーションにアクセスします
- PDF の印刷に Google Chrome か Chromium 131 以上。日本語の報告書には、Linux では `fonts-noto-cjk`
- `APP_ENV=test` で動くアプリケーション。ページに必要なテーブルとデータのあるテストのデータベース (テストが読み込む
  スキーマなど) と、`config/.env.test`
- `--end-to-end` には、[pieceofcake2/panther](https://pieceofcake2.github.io/panther/ja/) の End-to-End テストと、
  Chrome とその chromedriver

スキャンしてよいアプリケーションだけをスキャンしてください。ベースラインスキャンでもサイトに多くのリクエストを送り、
フルスキャンは攻撃を送ります。`--url` には自分のサーバーを指定し、他人のサイトには決して使わないでください。
