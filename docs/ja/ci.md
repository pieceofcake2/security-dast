# GitHub Actions での CI

ほとんどのアプリケーションは、2 つのワークフローで足ります。

| ワークフロー | いつ | スキャン |
| --- | --- | --- |
| [docs/examples/security-dast.yml](../examples/security-dast.yml) | push と pull request のたびと、毎週 | ジョブのテストのデータベースでの、アプリケーションのベースラインスキャン |
| [docs/examples/security-dast-full.yml](../examples/security-dast-full.yml) | 毎晩と、手動で | テストのユーザーでの、End-to-End テストのフルスキャン |

どちらも、報告書を PDF のまま (zip にせずに) アップロードします。Ubuntu のランナーには Docker と Google Chrome が
入っています。

## ベースラインスキャン

```yaml
- run: composer install --no-interaction --no-progress
- run: sudo apt-get install -y fonts-noto-cjk
- run: vendor/bin/security-dast --pdf=tmp/security-dast.pdf --fail-on=medium --language=ja
- uses: actions/upload-artifact@v7
  if: ${{ !cancelled() }}
  with:
    path: tmp/security-dast.pdf
    archive: false
```

- アプリケーションは `APP_ENV=test` で動くので、テストと同じものが必要です。テストのデータベース (ジョブの
  サービスと `DATABASE_URL`) と、そのテーブルです。ないとすべてのページがエラー 500 になり、アプリケーションの代わりに
  エラーページをスキャンすることになります。
- ジョブの環境変数 (`DATABASE_URL` など) は、アプリケーションに渡ります。
- Linux では、ZAP はホストのネットワーク (`--network host`) を使い、`127.0.0.1` のウェブサーバーにアクセスします。
- `if: ${{ !cancelled() }}` で、スキャンがジョブを失敗させたときにも報告書をアップロードします。報告書が必要なのは
  そのときです。
- 既存のアプリケーションでは、まず `--fail-on=none` でジョブを失敗させずにアラートを確かめ、ヘッダーを修正してから基準を
  上げてください ([アラート](alerts.md))。

## End-to-End テストでフルスキャン

フルスキャンはアプリケーションを攻撃するので、ジョブの組み込みウェブサーバーと、ジョブとともに捨てられるジョブの
テストのデータベースで実行します。

```yaml
- name: Scan the End-to-End tests with attacks
  env:
    SECURITY_DAST_PASSWORD: ${{ secrets.TEST_USER_PASSWORD }}
  run: >-
    vendor/bin/security-dast --scan=full --scan-minutes=30
    --end-to-end="vendor/bin/phpunit --testsuite EndToEnd"
    --login-url=/users/login --username=test@example.com
    --pdf=tmp/security-dast-full.pdf --fail-on=high --language=ja
```

- ジョブには、End-to-End テストそのものと同じく、テストのための Chrome と chromedriver が要ります
  (`vendor/bin/bdi detect drivers`)。
- スキャンの前に、テストのユーザーをテストのデータベースに入れ ([テストのユーザー](login.md#テストのユーザー))、
  そのパスワードはリポジトリのシークレット (`TEST_USER_PASSWORD`) にしてください。
- ほかのジョブより時間がかかります。push のたびではなく毎晩 (`schedule`) 実行し、シナリオに足りる `--scan-minutes`
  を指定してください。

## ZAP のバージョンの固定

デフォルトのイメージ `ghcr.io/zaproxy/zaproxy:stable` は ZAP のリリースに従います。ルールが変わると、何も変えて
いないスキャンのアラートが変わることがあります。実行ごとの報告書を比べるときはバージョンを固定し、上げるときは意図して
上げてください。

```bash
vendor/bin/security-dast --image=ghcr.io/zaproxy/zaproxy:2.17.0
```

ジョブごとに、スキャンの前にイメージ (約 1.5 GB) を取得します。

## ステージング

デプロイのあとにステージングのサーバーをスキャンするときは、その URL を指定します。何も変えないベースライン
スキャンだけにしてください。

```yaml
- run: vendor/bin/security-dast --url=https://staging.example.com --pdf=tmp/security-dast-staging.pdf
```

コマンドのまとめは、ジョブのログに出ます。

```text
11 alerts (2 medium, 7 low, 2 info) on 14 URLs of http://127.0.0.1:49855 (ZAP 2.17.0). Report: tmp/security-dast.pdf
```
