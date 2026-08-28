# 起動トリガー（Cloudflare Workers）

15分おきに GitHub Actions の監視ワークフローを起動する。

## なぜ要るのか

GitHub の `schedule` は**設定した間隔で動いてくれない**。混雑時に遅延し、発火自体が
破棄される。実測がこれ。

| 設定 | 実際 |
|---|---|
| 30分間隔 | 46〜64分。9時間で18回のはずが12回 |
| 15分間隔 | 数日は20回前後／日で動いたが、**1日2回まで落ちた**（2026-08-27〜28） |

1日2回では早い者勝ちの物件監視に使えない。そこで**発火だけを外から与える**。
監視そのものは従来どおり GitHub Actions 側で走るので、ワークフローの中身は変えていない。

`workflow_dispatch` は「叩けば必ず動く」ため、GitHub の混雑に左右されない。

## 仕組み

```
Cloudflare Workers（15分おき・正確）
    ↓ workflow_dispatch を POST
GitHub Actions（従来どおり45〜85秒で監視）
    ↓
Slack / docs/index.html
```

GitHub 側の `schedule` は1日4回だけ残してある。外部トリガーが死んだときの保険と、
「監視が止まっていないか」を見張る心拍を兼ねる（`ur_monitor.php` の `warn_if_stale`）。

## セットアップ

### 1. GitHub のトークンを作る

[Settings → Developer settings → Personal access tokens → Fine-grained tokens](https://github.com/settings/personal-access-tokens/new)

| 項目 | 値 |
|---|---|
| Token name | `ur-monitor-trigger` |
| Expiration | **No expiration**（個人リポジトリなら選べる） |
| Repository access | **Only select repositories** → `ur-monitor` だけ |
| Permissions → Actions | **Read and write** |

**このトークンでできるのは「このリポジトリのワークフローを起動すること」だけ。**
コードも他のリポジトリも読めないので、万一漏れても被害は「勝手に空室チェックが走る」で済む。

> `Actions` だけで 403 になる場合は `Contents: Read and write` も足す。
> それでも通らなければ classic token（`repo` + `workflow`）に切り替える。ただし権限は広くなる。

生成された文字列は**この画面でしか見られない**。次の手順ですぐ使う。

### 2. Cloudflare で Worker を作る

1. [Cloudflare](https://dash.cloudflare.com/) にアカウントを作る（無料）
2. **Workers & Pages** → **Create** → **Start with Hello World!** → 名前を `ur-monitor-trigger` にして **Deploy**
3. **Edit code** を開き、中身をすべて消して [`worker.js`](worker.js) の内容を貼り付け → **Deploy**

### 3. トークンを Secret に入れる

Worker の **Settings** → **Variables and Secrets** → **Add**

| 項目 | 値 |
|---|---|
| Type | **Secret** |
| Name | `GITHUB_TOKEN` |
| Value | 手順1のトークン |

Secret にすると、あとから画面上でも読み出せなくなる。**Variable（平文）にしないこと。**

### 4. 15分おきに動かす

Worker の **Settings** → **Trigger Events** → **Cron Triggers** → **Add**

```
*/15 * * * *
```

UTC で15分おきに起動し、稼働時間帯（JST 08:00〜21:00）の判定は `worker.js` の中で行う。
無料プランは**1ワーカーあたり cron 3本まで**なので、1本で済ませて余裕を残してある。

### 5. 動作確認

Worker の画面で **Deployments** → 最新の実行ログを見る。

- `JST 14時: 起動した` → 成功
- `起動に失敗: 401 ...` → トークンが違う、または期限切れ
- `起動に失敗: 403 ...` → 権限不足。手順1の注記を参照
- `JST 3時は稼働時間外のため起動しない` → 正常（夜間）

GitHub の **Actions** タブに `workflow_dispatch` の実行が15分おきに並べば完了。

## 変更するとき

`wrangler` を使うなら、このディレクトリで次を実行する。

```bash
npx wrangler deploy
npx wrangler secret put GITHUB_TOKEN
```

画面から貼り付けても同じ。どちらでもよい。

## 費用

無料枠に十分収まる。1日96回の起動に対し、無料プランは10万リクエスト/日。
