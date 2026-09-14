// trigger/worker.js の scheduled（起動・詰まりの片付け・止まりの見張り）を、GitHub・Slack・KV を
// 模擬して確かめる。自動デプロイ（.github/workflows/deploy-trigger.yml）は、これが通ったときだけ反映する。
//
//   node trigger/worker.test.mjs
//
// worker.js は Cloudflare 向けの ES モジュールで、拡張子 .js のままだと Node が CommonJS として読むため、
// 中身を data: URL にして読み込んでいる。
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const src = readFileSync(fileURLToPath(new URL('./worker.js', import.meta.url)), 'utf8');
const worker = (await import('data:text/javascript;charset=utf-8,' + encodeURIComponent(src))).default;

const MIN = 60 * 1000;
// JST の日時を UNIX ミリ秒にする
const jst = (s) => Date.parse(s + '+09:00');

function makeStore() {
  const m = new Map();
  return {
    m,
    get: async (k) => (m.has(k) ? m.get(k) : null),
    put: async (k, v) => { m.set(k, v); },
    delete: async (k) => { m.delete(k); },
  };
}

// GitHub と Slack を模擬する。runs は新しい順の実行一覧、stuck は終わっていない実行
function setup({ now, runs = [], stuck = [], listFails = false, store = makeStore() }) {
  const calls = { dispatch: 0, slack: [], cancel: [], urls: [] };
  globalThis.fetch = async (url, opts = {}) => {
    const u = String(url);
    calls.urls.push(u);
    const json = (body, status = 200) => new Response(JSON.stringify(body), { status });
    if (u.includes('hooks.slack.test')) {
      calls.slack.push(JSON.parse(opts.body).text);
      return new Response('ok');
    }
    if (u.endsWith('/dispatches')) {
      calls.dispatch++;
      return new Response(null, { status: 204 });
    }
    if (/\/runs\/\d+\/(force-)?cancel$/.test(u)) {
      calls.cancel.push(u.split('/runs/')[1]);
      return new Response(null, { status: 202 });
    }
    if (u.includes('/runs?status=')) {
      const status = new URL(u).searchParams.get('status');
      return json({ workflow_runs: stuck.filter((r) => r.status === status) });
    }
    if (u.includes('/runs?per_page=')) {
      if (listFails) return new Response('boom', { status: 500 });
      return json({ workflow_runs: runs });
    }
    throw new Error('想定外の呼び出し: ' + u);
  };
  Date.now = () => now;
  const env = { GITHUB_TOKEN: 't', SLACK_WEBHOOK_URL: 'https://hooks.slack.test/x', STORE: store };
  const logs = [];
  const origLog = console.log;
  return {
    calls, store, logs,
    run: async () => {
      console.log = (...a) => logs.push(a.join(' '));
      try { await worker.scheduled({}, env, {}); } finally { console.log = origLog; }
    },
  };
}

const success = (id, updatedMs) => ({
  id, status: 'completed', conclusion: 'success',
  created_at: new Date(updatedMs - 1.5 * MIN).toISOString(), updated_at: new Date(updatedMs).toISOString(),
});
const cancelled = (id, createdMs) => ({
  id, status: 'completed', conclusion: 'cancelled',
  created_at: new Date(createdMs).toISOString(), updated_at: new Date(createdMs).toISOString(),
});

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('正常（5分前に成功）なら警告せず起動する。成功だけに絞り込む問い合わせは使わない', async () => {
  const now = jst('2026-09-14T19:40:00');
  const t = setup({ now, runs: [success(2, now - 3 * MIN), success(1, now - 8 * MIN)] });
  await t.run();
  assert.equal(t.calls.dispatch, 1);
  assert.equal(t.calls.slack.length, 0);
  assert.ok(!t.calls.urls.some((u) => u.includes('status=success')), 'status=success を使っている');
});

test('止まっていても1回目は警告せず、2回目で1回だけ警告する（3回目は黙る）', async () => {
  const store = makeStore();
  const lastOk = jst('2026-09-14T12:00:00');
  const runs = [cancelled(3, lastOk + 60 * MIN), success(1, lastOk)];
  let t = setup({ now: jst('2026-09-14T13:00:00'), runs, store });
  await t.run();
  assert.equal(t.calls.slack.length, 0, '1回目で警告した');
  assert.equal(store.m.get('stale_suspect'), '1');
  assert.equal(t.calls.dispatch, 1);

  t = setup({ now: jst('2026-09-14T13:05:00'), runs, store });
  await t.run();
  assert.equal(t.calls.slack.length, 1, '2回目で警告しない');
  assert.match(t.calls.slack[0], /監視が止まっています/);
  assert.match(t.calls.slack[0], /9\/14 12:00/);
  assert.equal(store.m.get('stale_alerted'), '1');
  assert.equal(store.m.has('stale_suspect'), false);

  t = setup({ now: jst('2026-09-14T13:10:00'), runs, store });
  await t.run();
  assert.equal(t.calls.slack.length, 0, '同じ停止で2回警告した');
});

test('1回だけ古い結果を見ても、次に正常なら疑いを消して警告しない（9/14 19:40 の誤報の再現）', async () => {
  const store = makeStore();
  const now = jst('2026-09-14T19:40:00');
  // 1回目: 一覧が前日の成功しか返さなかった
  let t = setup({ now, runs: [success(1, jst('2026-09-13T13:03:00'))], store });
  await t.run();
  assert.equal(t.calls.slack.length, 0);
  assert.equal(store.m.get('stale_suspect'), '1');
  // 2回目: 正しい一覧が返る
  t = setup({ now: now + 5 * MIN, runs: [success(9, now + 2 * MIN), success(8, now - 3 * MIN)], store });
  await t.run();
  assert.equal(t.calls.slack.length, 0);
  assert.equal(store.m.has('stale_suspect'), false, '疑いの印が消えていない');
});

test('直近の実行に成功が1件も無ければ、一番古い実行を下限にして警告する', async () => {
  const store = makeStore();
  const base = jst('2026-09-14T09:00:00');
  const runs = Array.from({ length: 50 }, (_, i) => cancelled(100 - i, base + (49 - i) * 5 * MIN));
  let t = setup({ now: jst('2026-09-14T14:00:00'), runs, store });
  await t.run();
  t = setup({ now: jst('2026-09-14T14:05:00'), runs, store });
  await t.run();
  assert.equal(t.calls.slack.length, 1);
  assert.match(t.calls.slack[0], /直近 50 回の実行に成功がありません/);
});

test('前夜に成功して朝8時台なら、夜間を数えないので警告しない', async () => {
  const t = setup({ now: jst('2026-09-15T08:20:00'), runs: [success(1, jst('2026-09-14T21:50:00'))] });
  await t.run();
  assert.equal(t.calls.slack.length, 0);
  assert.equal(t.calls.dispatch, 1);
});

test('20分を過ぎた待ちの実行は取り消し、40分を過ぎたら強制的に取り消す', async () => {
  const now = jst('2026-09-14T15:00:00');
  const stuck = [
    { id: 11, status: 'queued', created_at: new Date(now - 25 * MIN).toISOString() },
    { id: 12, status: 'queued', created_at: new Date(now - 45 * MIN).toISOString() },
    { id: 13, status: 'in_progress', created_at: new Date(now - 2 * MIN).toISOString() },
  ];
  const t = setup({ now, stuck, runs: [success(1, now - 3 * MIN)] });
  await t.run();
  assert.deepEqual(t.calls.cancel.sort(), ['11/cancel', '12/force-cancel']);
});

test('見張りが失敗しても起動は必ず出す', async () => {
  const t = setup({ now: jst('2026-09-14T15:00:00'), listFails: true });
  await t.run();
  assert.equal(t.calls.dispatch, 1);
  assert.ok(t.logs.some((l) => l.includes('止まりの見張りに失敗')));
});

test('稼働時間外（JST 3時）は何もしない', async () => {
  const t = setup({ now: jst('2026-09-15T03:00:00'), runs: [] });
  await t.run();
  assert.equal(t.calls.urls.length, 0);
});

let failed = 0;
for (const [name, fn] of tests) {
  try {
    await fn();
    console.log(`ok   ${name}`);
  } catch (e) {
    failed++;
    console.log(`FAIL ${name}\n     ${e.message}`);
  }
}
console.log(`\n${tests.length - failed}/${tests.length} 件成功`);
process.exit(failed ? 1 : 0);
