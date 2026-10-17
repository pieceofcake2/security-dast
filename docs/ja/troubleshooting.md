# トラブルシューティング

## Docker と ZAP

**`ZAP failed (exit code 125): Cannot connect to the Docker daemon`**: Docker が動いていないか、そのユーザーが使えません。
Docker を起動するか、`--docker` で別のコマンド (`podman` など) を指定してください。

**ZAP の出力付きの `ZAP failed ...`**: メッセージに ZAP の最後の出力が出ます。ZAP の plan のエラーは、このパッケージの
不具合です。メッセージを添えて報告してください。

**ZAP がアプリケーションに届かない** (報告書に URL がない、ZAP の出力に `Connection refused` がある): Linux では
コンテナが `--network host` を使うので、Docker がホストのネットワークを使える必要があります (GitHub Actions の
ランナーでは使えます)。macOS と Windows では、Docker Desktop が用意する `host.docker.internal` でホストに届きます。
それ以外の Docker では、コンテナから届く起動済みのアプリケーションを `--url` で指定してください。

**初回の実行に時間がかかる**: Docker が ZAP のイメージ (約 1.5 GB) を取得しています。
`--image=ghcr.io/zaproxy/zaproxy:2.17.0` でバージョンを固定すると、取得は一度で済み、実行ごとに同じルールで
スキャンできます。

## アプリケーション

**`... has no index.php: give the document root with --webroot`**: ドキュメントルートが見つかりませんでした。
`--webroot` に、プロジェクトからの相対パスで指定してください。

**`PHP's built-in web server did not start`**: メッセージにサーバーの出力が出ます。ポートは空いているものを選ぶので、
拡張モジュールが足りないなど、PHP 自体が原因のことが多いです。

**すべてのページがエラー 500 になる**: アプリケーションが `APP_ENV=test` で動きません。テストのデータベースに
テーブルがないなどです。テストと同じようにスキーマを読み込んでからスキャンするか、別の `--app-env` を使ってください。
こうしたページは「アプリケーションエラーの露見」のアラートに出て、エラーは `logs/error.log` に出ます。

**トップページが 404 になる**: 新しいアプリケーションの `home` のページは、デバッグなし (`APP_ENV=test` と同じ
`debug` 0) では 404 です。スキャンの始まりになるページを用意してください。トップページからアプリケーションのページに
リンクするか、[End-to-End テスト](end-to-end.md)を使います。

**ZAP が見つけた URL が少ない**: クロールはページのリンクをたどり、実行ごとに見つける数が少し変わります。`--minutes`
を増やすか、JavaScript が作るリンクのために `--ajax-spider=1` を付けるか、[End-to-End テスト](end-to-end.md)を
スキャンしてください。

**フルスキャンでテストのデータベースが埋まる**: フォームを何度も送るためです。スキャンのあとに、テストと同じように
テストのデータベースを読み込み直し、送るべきでないページは `--exclude` で除いてください。

## ログイン

**`ZAP could not log in as ...`**: ユーザー名とパスワード、そのユーザーがテストのデータベースにいること
([テストのユーザー](login.md#テストのユーザー))、ログインページにほかの手順 (CAPTCHA や 2 段階認証など。テストの
ユーザーには付けないでください) がなく、ユーザー名とパスワードの欄があることを確かめてください。

**ユーザーのページが報告書にない**: スキャンがログアウトしています。`--exclude` に合わないログアウトの URL を通った
などです。その URL を `--exclude` に足し、ログアウトもリストに残してください。

## End-to-End テスト

**テストがスキャンのときだけ失敗する**: テストは相対パスの URL を使い、`PANTHER_EXTERNAL_BASE_URI` に従う必要が
あります。テストの出力は、コマンドのエラー出力に出ます。

**`The port 9515 is already in use`**: 別の chromedriver が動いています。ほかのプロジェクトの End-to-End テストなど
です。終わるのを待つか、テストに別の chromedriver のポートを指定してください。

**フルスキャンがテストのフォームで何も見つけない**: 除外したアラートと URL を確かめ、長いシナリオには
`--scan-minutes` を増やし、テストが最後にログアウトするなら `--login-url` を指定してください
([シナリオに向いたテストの書き方](end-to-end.md#シナリオに向いたテストの書き方))。

## 報告書

**`Chrome is needed to print the report`**: Chrome か Chromium をインストールするか、`REPORT_CHROME_BINARY` を設定して
ください ([pieceofcake2/report](https://pieceofcake2.github.io/report/ja/))。

**日本語の報告書の文字が四角になる**: 日本語のフォントをインストールしてください。Ubuntu では
`sudo apt-get install -y fonts-noto-cjk` です。
