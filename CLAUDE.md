# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

UR賃貸の空き部屋を定期監視し、条件に合う新着を Slack へ通知する。本番は GitHub Actions のみで動く。
セットアップ手順・設定項目・運用上の制約は `README.md` に詳しい。ここには README と重複しない、
コードを触るときに必要な前提だけを書く。

## コマンド

```bash
composer install
php ur_monitor.php --dry-run   # 開発中は基本これ
php -l ur_monitor.php          # 構文チェック
```

| コマンド | 用途 |
|---|---|
| `php ur_monitor.php --dry-run` | スクレイピングのみ。Slack 通知も `state.json` / `results.html` の更新もしない |
| `php ur_monitor.php` | 本番と同じ動作。**ローカルでは基本実行しない**（下記「状態はリポジトリにある」参照） |
| `php ur_monitor.php --setup` | ブラウザを表示し `debug_page.html` と `debug_*.png` を保存。セレクター調整用 |
| `php ur_monitor.php --check-robots` | robots.txt の確認のみ |

**テストスイートは無い。** 検証は `php -l` と `--dry-run` の実行ログで行う。PR で走る CI も無い
（`monitor.yml` は `schedule` + `workflow_dispatch`、`verify-cloud-setup.yml` は `workflow_dispatch` のみで、
どちらも `pull_request` トリガーを持たない）。変更したら `--dry-run` を実際に流して確かめること。

## 構成

`ur_monitor.php` 1ファイル（約820行）に全処理があり、フレームワークは使っていない。
振る舞いは `config.json` で決まる。**セレクター・監視URL・通知条件はすべて設定側にあり、
コードにハードコードされていない**ため、UR のサイト構造が変わったときに触るのは
原則 `config.json` の `selectors` であってコードではない。

主要な流れは `run_monitor()`（`ur_monitor.php:632`）に集約されている。

```
config.json ──> run_monitor()
                  ├─ create_browser()      Chrome を1回だけ起動して全URLを処理
                  └─ URLごとに:
                       check_robots_txt()  PHP の file_get_contents（Chrome は使わない）
                       scrape_url()        wait_for_rooms() で描画完了を待ってから抽出
                  ↓
             前回 state との差分 ──> watch 条件に合致 ──> Slack
                  ↓
             state.json / results.html を書き出し
```

依存は `chrome-php/chrome`（Chrome DevTools Protocol）のみ。

## 触る前に知っておくこと

### 状態はリポジトリにある

`state.json` と `results.html` は毎回の実行結果で、**Actions が実行のたびにコミットしている**
（ランナーは毎回まっさらでファイルが残らないため）。`--dry-run` を付けずにローカル実行すると
この2ファイルが書き換わり、Actions 側のコミットと衝突する。開発中は必ず `--dry-run` を使う。

### 「0件」は失敗の可能性が高く、成功として扱ってはいけない

ページは開けたのに0件になることが実際に起きる。そのまま受け取ると全部屋が「成約」扱いになり、
次回復活したときに誤った新着通知が飛ぶ。そのため3段構えで守っている:

1. 0件なら5秒待って1回取り直す
2. それでも0件なら**前回の状態を維持してスキップ**（`state.json` の `zero_streak` に回数を記録）
3. 3回連続（約1.5時間）で0件なら、実際に空きが尽きたと判断して受け入れる

ログに `0 件のため前回状態を維持` が出るのは**この設計が正しく働いている姿**であって、
直すべきバグではない。ここを「0件なら空き無し」に単純化しないこと。

### 0件を見たらまず環境を疑う

開発中に全URLが0件になったら、セレクターより先に**そもそもページに到達できているか**を確かめる。
`check_robots_txt()` は Chrome ではなく PHP の `file_get_contents()` を使うため、
**`robots.txt チェック OK` が出ていても Chrome が通信できているとは限らない**。
実際に、`curl` は 200 を返すのに Chrome だけ `ERR_CONNECTION_RESET` になる環境があった
（詳細と回避策は README の「Claude Code on the web で開発する場合」）。

切り分けは、ページ内で `location.href` を見るのが速い。`chrome-error://chromewebdata/` なら
描画待ちの問題ではなく取得自体が失敗している。

### 描画待ち

UR のページは物件情報を HTML と一緒に返さず、描画後に JS が差し込む。`wait_for_rooms()` は
部屋行の件数が1秒間変化しなくなるまで待つ（順次描画の途中で読んで取りこぼすのを防ぐため）。
固定秒数の待機に置き換えないこと。

### Chrome の解決

`detect_chrome_path()` が3OS分の既定パスを探す。環境差の逃げ道として `config.json` の
`chrome_path`（実行ファイル）と `chrome_flags`（起動フラグ）があり、どちらも**本番では未設定のまま**使う。
`chrome_flags` は既定フラグの後ろに追加されるので後勝ちで上書きできる。

### 通知

`watch` の照合は `room_matches_watch()` の**部分一致**（`str_contains`）。`building` / `madori` は
空文字なら「その条件は問わない」の意味になる。全新着の通知（`notify_all_new`）はスパム防止で既定オフ。

Slack の Webhook URL は `SLACK_WEBHOOK_URL` 環境変数から渡す。`config.json` にも書けるが、
**実際の値を書いてコミットしないこと**。

### 実行コストが設計を縛っている

プライベートリポジトリの Actions 無料枠（月2,000分）に収めるため、30分間隔・`jitter_max_seconds` 0・
画像読み込み無効（`--blink-settings=imagesEnabled=false`）にしている。監視URLを増やしたり
間隔を詰めたりする変更は、この枠を超えないか確認してから行う。

課金は**ジョブ単位で分単位に切り上げ**られる。監視ジョブは実測 64〜65 秒（＝2分課金）なので、
数秒〜十数秒の追加はたいてい同じ課金分に収まる。Cloudflare Pages へのデプロイ手順を
足しているのはこの余地があるため（wrangler の導入は実測でコールド9秒・ウォーム3秒）。
逆に言えば、1分や2分の境界を跨ぐ追加は1回あたり+1分として効いてくる。

`results.html` は生成時刻を埋め込むため、**部屋に変化が無くても毎回内容が変わる**。
「変わったときだけ処理する」といった分岐を書くときは、この点を踏まえること。

### 公開されるのは dist/ の中身だけ

Cloudflare Pages へ配信するのは `results.html` と `deploy/_headers` に限っている。
このリポジトリには `ur_monitor.php` や `config.json` など**公開してはいけないファイルがある**ため、
リポジトリ直下を配信する構成にはできない。公開物を増やすときは `monitor.yml` の
「公開用ディレクトリを用意」で明示的に `dist/` へコピーすること。

生成 HTML には `noindex, nofollow` を入れている。狙っている物件が
`highlight_keywords` から読み取れるため、検索エンジンに載せない意図。外すときはその点を承知の上で。

## スタイル

- コード内のコメント・ログ・ドキュメント・コミットメッセージはすべて日本語
- コメントは「何をしているか」ではなく**なぜそうしているか**を書く（既存コードがその粒度）
- `.editorconfig` に従う
