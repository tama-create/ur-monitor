// GitHub Actions の schedule は遅延・欠落するため、発火だけを外から与える。
//
// 30分間隔で運用したときの実測は46〜64分、15分間隔にしたあとも1日2回まで落ちた
// （2026-08-27〜28）。schedule はベストエフォートで、混雑時は間引かれる。
// このワーカーは workflow_dispatch を叩くだけで、監視そのものは従来どおり
// GitHub Actions 側で走る。ここに UR のスクレイピングは持ち込まない。
//
// 発火が正確になった副作用として、毎回きっかり同じ秒に UR を叩く形になった。
// ゆらぎは config.json の jitter_max_seconds（PHP 側の待機）で入れている。
// ここで待つと Workers の実行時間を無駄に使うため、待機はワーカーに置かない。

// フォークした人はここを自分のリポジトリに変えること。
const OWNER = 'tama-create';
const REPO = 'ur-monitor';
const WORKFLOW = 'monitor.yml';
const REF = 'main';

// 稼働時間帯（JST）。START <= 時 <= END の間だけ起動する。
// wrangler.toml の cron 側でも同じ時間帯に絞ってあるので、通常ここには
// 時間外の呼び出しは来ない。二重になるが消さないこと。cron は UTC 指定で
// 時がずれるため間違えやすく、これが時間外へはみ出すのを止める最後の番人になる。
// 変えるときは wrangler.toml の crons と config.json の monitoring_hours も一緒に。
const START_HOUR = 8;
const END_HOUR = 21;

export default {
  async scheduled(event, env, ctx) {
    // Workers の時刻は UTC。JST に直してから時間帯を判定する
    const jstHour = new Date(Date.now() + 9 * 60 * 60 * 1000).getUTCHours();
    if (jstHour < START_HOUR || jstHour > END_HOUR) {
      console.log(`JST ${jstHour}時は稼働時間外のため起動しない`);
      return;
    }

    const url = `https://api.github.com/repos/${OWNER}/${REPO}/actions/workflows/${WORKFLOW}/dispatches`;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        // トークンはリポジトリに置かない。Cloudflare の Secret から読む
        Authorization: `Bearer ${env.GITHUB_TOKEN}`,
        Accept: 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        // GitHub API は User-Agent が無いと 403 を返す
        'User-Agent': 'ur-monitor-trigger',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ ref: REF }),
    });

    // 成功は 204 No Content。それ以外は例外にして Cloudflare 側のログに残す
    // （握りつぶすと、トークン切れに気づけないまま止まる）
    if (res.status !== 204) {
      const body = await res.text();
      throw new Error(`起動に失敗: ${res.status} ${body}`);
    }
    console.log(`JST ${jstHour}時: 起動した`);
  },
};
