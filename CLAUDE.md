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
| `php ur_monitor.php --dry-run` | スクレイピングのみ。Slack 通知も `state.json` / `docs/index.html` の更新もしない |
| `php ur_monitor.php` | 本番と同じ動作。**ローカルでは基本実行しない**（下記「状態はリポジトリにある」参照） |
| `php ur_monitor.php --setup` | ブラウザを表示し `debug_page.html` と `debug_*.png` を保存。セレクター調整用 |
| `php ur_monitor.php --check-robots` | robots.txt の確認のみ |

**テストスイートは無い。** 検証は `php -l` と `--dry-run` の実行ログで行う。PR で走る CI も無い
（`monitor.yml` は `schedule` + `workflow_dispatch`、`verify-cloud-setup.yml` は `workflow_dispatch` のみで、
どちらも `pull_request` トリガーを持たない）。変更したら `--dry-run` を実際に流して確かめること。

## 構成

`ur_monitor.php` 1ファイル（約900行）に全処理があり、フレームワークは使っていない。
分割していないのは、フォークした人が中身を1ファイルで追え、テストが無いまま
ファイルを跨いだ整合性を気にせずに済むため。増やすなら `// ──` で区切ってある
既存の節（ユーティリティ / robots / スクレイピング / HTML出力 / Slack / メイン）が
そのまま分割点になるが、**1,000行台のうちは分けない**。
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
             state.json / docs/index.html を書き出し
```

依存は `chrome-php/chrome`（Chrome DevTools Protocol）のみ。`composer.lock` は追跡している。
PR で走る CI が無いため、これを外すと依存の更新が本番実行で初めて壊れる形で表面化する。

## 触る前に知っておくこと

### 状態はリポジトリにある

`state.json` と `docs/index.html` は毎回の実行結果で、**Actions が実行のたびにコミットしている**
（ランナーは毎回まっさらでファイルが残らないため）。`--dry-run` を付けずにローカル実行すると
この2ファイルが書き換わり、Actions 側のコミットと衝突する。開発中は必ず `--dry-run` を使う。

### 「0件」は失敗の可能性が高く、成功として扱ってはいけない

ページは開けたのに0件になることが実際に起きる。そのまま受け取ると全部屋が「成約」扱いになり、
次回復活したときに誤った新着通知が飛ぶ。そのため3段構えで守っている:

1. 0件なら5秒待って1回取り直す
2. それでも0件なら**前回の状態を維持してスキップ**（`state.json` の `zero_streak` に回数を記録）
3. `zero_streak_limit` 回連続で0件なら、実際に空きが尽きたと判断して受け入れる

連続回数のしきい値は `config.json` の `zero_streak_limit`（既定6）。
ログに `0 件のため前回状態を維持` が出るのは**この設計が正しく働いている姿**であって、
直すべきバグではない。ここを「0件なら空き無し」に単純化しないこと。

区別すべきは「取得できたうえでの0件」と「取得に失敗しての0件」で、`run_monitor()` は
これを `$scrapedOk` で持っている。**信用できる結果が1つも無いときだけ** state と
`docs/index.html` を触らずに戻る。ここを「合計0件なら常に戻る」にすると、
全URLが同時に0件になったときに上記3段目の判定が永久に state へ書かれず、
古い部屋が一覧に残り続ける。

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

### robots.txt は判定結果を使う

`check_robots_txt()` は戻り値を返すだけでなく、`run_monitor()` が実際に見て
拒否された URL をスキップする。UR の `robots.txt` は
`Disallow: /chintai/*/result/?skcs=` のようにワイルドカードとクエリを使うため、
パスの前方一致では判定できない。`robots_rule_matches()` が `*` と末尾 `$` を
正規表現に変換し、クエリを含めた文字列で照合している。現在の監視URLはいずれも
許可されるが、**「確認しているが結果は無視する」状態に戻さないこと**（README で
robots.txt を尊重すると書いている以上、実装がそれを裏切ってはいけない）。

### state.json が壊れていたら止める

`load_state()` は JSON を解釈できなければ **exit(1) で中止する**。空とみなして続けると
全部屋が「新着」になり、条件に合うものがまとめて Slack へ飛ぶ。取得失敗より誤通知の
ほうが害が大きい、というのがこのプロジェクト全体の判断基準。

### 通知

`watch` の照合は `room_matches_watch()` の**部分一致**（`str_contains`）。`building` / `madori` は
空文字なら「その条件は問わない」の意味になる。全新着の通知（`notify_all_new`）はスパム防止で既定オフ。

Slack の Webhook URL は `SLACK_WEBHOOK_URL` 環境変数から渡す。`config.json` にも書けるが、
**実際の値を書いてコミットしないこと**。

### 実行コストが設計を縛っている

プライベートリポジトリの Actions 無料枠（月2,000分）に収めるため、`jitter_max_seconds` 0・
画像読み込み無効（`--blink-settings=imagesEnabled=false`）にしている。監視URLを増やしたり
間隔を詰めたりする変更は、この枠を超えないか確認してから行う。

課金は**ジョブ単位で分単位に切り上げ**られる。監視ジョブは実測 45〜65 秒なので、
数秒の追加は同じ課金分に収まることが多いが、**1分の境界を跨ぐ追加は1回あたり+1分**として効く。
公開を GitHub Pages に寄せて wrangler の実行（実測コールド9秒・ウォーム3秒）を
やめたのは、この境界を跨ぎにくくする意味もある。

1回あたりの実測は45〜85秒。課金はジョブごとに分単位へ切り上げられるので、
**設定上は**1日53回・月約1,600回で月およそ2,400分になり、private の無料枠を超える。
**public のうちは無制限に無料なので問題にならない**が、private に戻すなら間隔を戻すこと。
（なお下記のとおり実際の実行回数は設定より大幅に少ないため、実測はこれより小さくなる。）

**なお公開リポジトリなら Actions は無制限に無料**で、この枠自体が外れる。上記は
private に戻す場合に効いてくる話。ただし **private に戻すと GitHub Pages が使えなくなる**
（GitHub Free では公開リポジトリのみ）。一覧の公開をやめるか、有料プランにするかの選択になる。

`docs/index.html` は生成時刻を埋め込むため、**部屋に変化が無くても毎回内容が変わる**。
「変わったときだけ処理する」といった分岐を書くときは、この点を踏まえること。

### 実行間隔は設定どおりにならない

`monitor.yml` の cron は15分間隔だが、**GitHub がその間隔で実行してくれるわけではない**。
`schedule` は負荷で遅延し、発火自体が破棄されることもある。30分間隔だった頃の実測は
46〜64分で、**9時間で18回動くはずが12回**しか動いていなかった（約3分の1が欠落）。

`docs/index.html` は生成時刻を埋め込むため実行のたびに必ずコミットが残る。つまり
**コミット履歴が取りこぼしのない全実行記録**になっていて、上の数字はそこから数えたもの。
実行頻度を疑うときはここを見れば分かる。

15分にしているのは発火の試行を増やして実効間隔を縮めるためで、15分間隔になるからではない。
**ドキュメントに「15分ごとに実行される」と書かないこと。**書くなら「15分間隔で起動を試みる」
「実際はそれより広がる」と添える。

これに伴い、0件フェイルセーフの連続回数は `config.json` の `zero_streak_limit`（既定6）で
持たせている。**これは回数であって時間ではない**ため、実行間隔を変えたらここも合わせること
（実行間隔 × この回数 が、UR 側の一時的な不調に耐えられる時間になる）。

### 公開は GitHub Pages 一本

一覧は `docs/index.html` に書き出し、実行のたびにコミットする。GitHub Pages が `main` の
`/docs` を配信しているため、**push されれば自動で公開ページが最新になる**。
外部サービスもトークンも使わない。

| URL | 中身 |
|---|---|
| `https://<ユーザー名>.github.io/ur-monitor/` | 空き部屋一覧（`docs/index.html`） |
| `https://<ユーザー名>.github.io/ur-monitor/setup.html` | セットアップ手順書 |

**`docs/` は GitHub Pages の公開ディレクトリそのもの**で、ここに置いたファイルは
すべて `https://<ユーザー名>.github.io/ur-monitor/<ファイル名>` で配信される。
開発用のスクリプトや作業メモを置かないこと（`cloud-setup.sh` を `tools/` へ移したのは
この理由。Web に出す必要が無いものだった）。

`docs/.nojekyll` は Jekyll の変換を止めるための空ファイル。**消さないこと。**
これが無いと HTML 内の `{{ }}` や `{% %}` がテンプレート記法として解釈されて壊れる。

**監視のコミットに `[skip ci]` を付けないこと。** このワークフローには `push` トリガーが
無いので自己ループは起きず、逆に付けると Pages のビルドまで止めてしまう恐れがある。

生成 HTML には `noindex, nofollow` を入れている。狙っている物件が
`highlight_keywords` から読み取れるため、検索エンジンに載せない意図。外すときはその点を承知の上で。

以前は Cloudflare Pages の Direct Upload を使っていたが、GitHub Pages で足りるため廃止した。
API トークンと Account ID の Secrets、wrangler の実行手順、`deploy/_headers`、
「デプロイは常に一式を送る」という制約が、まとめて不要になっている。

## スタイル

- コード内のコメント・ログ・ドキュメント・コミットメッセージはすべて日本語
- コメントは「何をしているか」ではなく**なぜそうしているか**を書く（既存コードがその粒度）
- `.editorconfig` に従う
