# GitHub Actions での CI

[docs/examples/security-dast.yml](../examples/security-dast.yml) は、push と pull request のたびと毎週、アプリケーションを
組み込みウェブサーバーで起動して ZAP でスキャンし、報告書を PDF のまま (zip にせずに) アップロードします。Ubuntu の
ランナーには Docker と Google Chrome が入っています。

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
- ZAP のイメージは大きく (約 1.5 GB)、ジョブごとにスキャンの前に取得します。
- `if: ${{ !cancelled() }}` で、スキャンがジョブを失敗させたときにも報告書をアップロードします。報告書が必要なのは
  そのときです。
- 既存のアプリケーションでは、まず `--fail-on=none` でジョブを失敗させずにアラートを確かめ、ヘッダーを直してから基準を
  上げてください ([アラート](alerts.md))。

デプロイのあとにステージングのサーバーをスキャンするときは、その URL を指定します。

```yaml
- run: vendor/bin/security-dast --url=https://staging.example.com --pdf=tmp/security-dast.pdf
```

コマンドのまとめは、ジョブのログに出ます。

```text
11 alerts (2 medium, 7 low, 2 info) on 14 URLs of http://127.0.0.1:49855 (ZAP 2.17.0). Report: tmp/security-dast.pdf
```
