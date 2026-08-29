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


// ── 一覧の保管と閲覧 ─────────────────────────
//
// 空き部屋一覧は GitHub にコミットしない。公開リポジトリに置くと、UR のサイトから
// 取ってきた物件名・家賃・間取りがそのまま誰にでも読める状態になるため。
// UR は「このサイトについて」で、私的使用と引用を除く転載を認めておらず、しかも
// 「ウェブページに貼り付けることは、運営者が個人であっても私的使用にはならない」と
// 明示している。公開をやめてここに移したのはそのため。
//
// 監視ジョブが KV へ置き、閲覧は Basic 認証を通った人だけに返す。
// KV は Cloudflare の画面で作り、この Worker に STORE という名前で結びつける。
const KV_LIST  = 'list';    // 一覧の HTML
const KV_STATE = 'state';   // 前回の状態（差分の判定に使う）

// 文字列の比較で、一致した文字数から答えを推測されないようにする。
// 早期 return にすると、当たっている桁数だけ応答が遅くなり総当たりの助けになる。
function safeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  const enc = new TextEncoder();
  const x = enc.encode(a), y = enc.encode(b);
  // 長さが違っても最後まで回す。長さの違い自体は隠せないので diff に混ぜる
  let diff = x.length ^ y.length;
  const n = Math.max(x.length, y.length);
  for (let i = 0; i < n; i++) diff |= (x[i] ?? 0) ^ (y[i] ?? 0);
  return diff === 0;
}

// 監視ジョブからの書き込み用。GitHub Actions が Secrets から渡す
function bearerOk(request, env) {
  const h = request.headers.get('Authorization') || '';
  if (!h.startsWith('Bearer ')) return false;
  return !!env.API_TOKEN && safeEqual(h.slice(7), env.API_TOKEN);
}

// 人が見るとき用。ブラウザが出す ID／パスワードの入力欄がこれ
function basicOk(request, env) {
  const h = request.headers.get('Authorization') || '';
  if (!h.startsWith('Basic ')) return false;
  let decoded;
  try {
    decoded = atob(h.slice(6));
  } catch {
    return false;   // base64 として壊れている
  }
  const i = decoded.indexOf(':');
  if (i < 0) return false;
  const user = decoded.slice(0, i);
  const pass = decoded.slice(i + 1);
  // 片方だけ合っていても通さない。両方を必ず比較する（早期 return を作らない）
  const okUser = safeEqual(user, env.VIEW_USER || '');
  const okPass = safeEqual(pass, env.VIEW_PASSWORD || '');
  return okUser && okPass;
}

function needAuth() {
  // charset="UTF-8" を付けても、ブラウザが送る文字コードは保証されない。
  // パスワードは英数字にしておくこと（README に書いてある）
  return new Response('認証が必要です', {
    status: 401,
    headers: {
      'WWW-Authenticate': 'Basic realm="ur-monitor", charset="UTF-8"',
      'Content-Type': 'text/plain; charset=utf-8',
    },
  });
}

export default {
  // 一覧の保管（監視ジョブ）と閲覧（人）。cron とは別の入口。
  async fetch(request, env) {
    const url = new URL(request.url);

    // 設定漏れのまま公開状態になるのを防ぐ。Secret が入っていなければ何も返さない
    if (!env.API_TOKEN || !env.VIEW_USER || !env.VIEW_PASSWORD) {
      return new Response('設定が未完了です（Secret を登録してください）', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    }
    if (!env.STORE) {
      return new Response('KV が結び付いていません（STORE という名前で設定してください）', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    }

    // ── 監視ジョブからの読み書き ──
    if (url.pathname === '/state' || url.pathname === '/list') {
      if (!bearerOk(request, env)) {
        return new Response('unauthorized', { status: 401 });
      }
      const key = url.pathname === '/state' ? KV_STATE : KV_LIST;

      if (request.method === 'PUT') {
        await env.STORE.put(key, await request.text());
        return new Response('ok', { headers: { 'Content-Type': 'text/plain' } });
      }
      // 一覧は書くだけ。読むのは人だけなので GET は state に限る
      if (request.method === 'GET' && key === KV_STATE) {
        const v = await env.STORE.get(KV_STATE);
        // 「まだ無い」と「読めなかった」を取り違えると全部屋が新着になる。
        // PHP 側が 404 を初回と決めつけず中止できるよう、明確に分けて返す
        if (v === null) return new Response('not found', { status: 404 });
        return new Response(v, {
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
        });
      }
      return new Response('method not allowed', { status: 405 });
    }

    // ── 人が一覧を見る ──
    if (url.pathname === '/' || url.pathname === '/index.html') {
      if (!basicOk(request, env)) return needAuth();
      const html = await env.STORE.get(KV_LIST);
      if (html === null) {
        return new Response('まだ一覧がありません（監視がまだ1回も成功していません）', {
          status: 404,
          headers: { 'Content-Type': 'text/plain; charset=utf-8' },
        });
      }
      return new Response(html, {
        headers: {
          'Content-Type': 'text/html; charset=utf-8',
          // 認証の内側なので本来は要らないが、取り違えて公開したときの保険
          'X-Robots-Tag': 'noindex, nofollow',
          // 5分ごとに変わるので、古いものを見せない
          'Cache-Control': 'no-store',
        },
      });
    }

    return new Response('not found', { status: 404 });
  },

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
