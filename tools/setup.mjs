#!/usr/bin/env node
// ur-monitor セットアップウィザード本体。
//
// 【使い方】直接は起動しません。呼び水（tools/setup.sh）から呼ばれます。
//   bash tools/setup.sh
//
// 【なぜ Node で書いてあるか】
// Cloudflare の wrangler が Node を要求するため、このツールを使う人の環境には
// Node が必ず入ります。そこに本体を置けば、対話も検証も API 呼び出しも
// Windows と Mac で1つのコードのまま動きます。呼び水を bash 1本にしているのは、
// git を入れると Git Bash が必ず付いてくる（Windows でも）ため、こちらも
// 書き分けが要らないからです。OS 差はコマンド名（winget / brew）だけです。
//
// 【設計方針】
// - 各段階の終わりに必ず検証を入れる。「登録したつもり」で先へ進ませない
// - 途中で失敗しても再開できる（.setup-state.json に済んだ段階を残す）
// - 秘密情報は画面にもファイルにもシェル履歴にも残さない（後述の run_secret）
// - --mock を付けると、Cloudflare / GitHub の実アカウントを一切変更せずに
//   全11段階を通しで確認できる（詳しくは「── 模擬実行 ──」を参照）

import { spawn } from 'node:child_process';
import { randomInt } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import readline from 'node:readline';
import { fileURLToPath } from 'node:url';

const ROOT        = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const TRIGGER_DIR = path.join(ROOT, 'trigger');
const LOCAL_TOML  = path.join(TRIGGER_DIR, 'wrangler.local.toml');
const UPSTREAM    = 'tama-create/ur-monitor';
const MOCK_DIR    = path.join(ROOT, 'tools', '.mock-run');

// 資料ページに直書きしてある一覧へのリンク。フォークした人の Worker に差し替える。
const DOC_FILES   = ['index.html', 'guide.html', 'setup.html', 'architecture.html'];
const DOC_URL_RE  = /https:\/\/ur-monitor-trigger\.[a-z0-9-]+\.workers\.dev\/?/g;

// 進行状況の記録先。--mock は本番の進み具合と混ざらないよう別ファイルに書く
// （本番で完了済みの段階が、模擬実行のせいで誤って「完了済み」扱いになる事故を防ぐ）。
const stateFile = (mock) => path.join(ROOT, 'tools', mock ? '.setup-state.mock.json' : '.setup-state.json');

// 本番のファイルを一切変更しないよう、--mock のときは生成物をすべて
// tools/.mock-run/ 以下へ逃がす。relPath は ROOT からの相対パス。
function outPath(state, relPath) {
  if (!state.mock) return path.join(ROOT, relPath);
  const dest = path.join(MOCK_DIR, relPath);
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  return dest;
}

// ── 画面表示 ─────────────────────────────────────────────

const C = process.stdout.isTTY
  ? { g: '\x1b[32m', y: '\x1b[33m', r: '\x1b[31m', b: '\x1b[36m', d: '\x1b[2m', B: '\x1b[1m', x: '\x1b[0m' }
  : { g: '', y: '', r: '', b: '', d: '', B: '', x: '' };

const say  = (s = '') => console.log(s);
const ok   = (s) => say(`  ${C.g}✓${C.x} ${s}`);
const warn = (s) => say(`  ${C.y}!${C.x} ${s}`);
const bad  = (s) => say(`  ${C.r}✗${C.x} ${s}`);
const note = (s) => say(`  ${C.d}${s}${C.x}`);

function heading(n, total, title) {
  say();
  say(`${C.B}${C.b}[${n}/${total}] ${title}${C.x}`);
  say(`${C.d}${'─'.repeat(56)}${C.x}`);
}

// 利用者にブラウザ作業をお願いする箇所の枠。何をすれば良いかを外さないよう、
// 「開く URL」と「やること」と「戻ってきて入力するもの」を必ず並べて出す。
function guide(lines) {
  say();
  for (const l of lines) say(`  ${l}`);
  say();
}

// ── 入力 ─────────────────────────────────────────────────
//
// readline は「1つだけ作って使い回し、その非同期イテレータから1行ずつ
// 取り出す」形にしてある。rl.question() を毎回呼ぶ素朴な書き方だと、
// 届いた入力をまとめて先読みしてしまい、2問目以降が「聞いていない」
// 扱いで取りこぼされることがある（--mock をパイプ入力で検証したときに
// 実際に踏んだ。人がキーボードで1行ずつ打つ通常の使い方では起きないが、
// 直しておく）。非同期イテレータはこの取りこぼしが起きない、Node 公式の
// 対話用パターン。

let rlSingleton = null;
let lineIterator = null;
function getReadline() {
  if (!rlSingleton) {
    rlSingleton = readline.createInterface({ input: process.stdin, output: process.stdout });
    lineIterator = rlSingleton[Symbol.asyncIterator]();
  }
  return rlSingleton;
}

function closeReadline() {
  if (rlSingleton) { rlSingleton.close(); rlSingleton = null; lineIterator = null; }
}

async function ask(query, { allowEmpty = true } = {}) {
  const rl = getReadline();
  for (;;) {
    rl.output.write(`  ${query}`);
    const { value, done } = await lineIterator.next();
    const t = done ? '' : String(value).trim();
    if (!t && !allowEmpty) { say(`  ${C.r}入力してください。${C.x}`); continue; }
    return t;
  }
}

async function askYesNo(query, def = true) {
  const a = (await ask(`${query} ${def ? '[Y/n]' : '[y/N]'} `)).toLowerCase();
  if (!a) return def;
  return a === 'y' || a === 'yes';
}

// 秘密情報の入力。打った文字を画面に出さない。
// 肩越しに見られる事故と、ターミナルのスクロールバックに残る事故の両方を防ぐ。
// プロンプトは自分で書き出してから muted にするので、プロンプトは見えたまま
// 打った文字だけ隠れる（対話端末でのみ効く。パイプ入力では readline 自身が
// 何も反響しないので、この仕組みは何もしなくても安全）。
async function askSecret(query) {
  const rl = getReadline();
  const original = rl._writeToOutput;
  let muted = false;
  rl._writeToOutput = function (s) {
    if (!muted) original.call(rl, s);
  };
  rl.output.write(`  ${query}`);
  muted = true;
  const { value, done } = await lineIterator.next();
  rl._writeToOutput = original;
  process.stdout.write('\n');
  return done ? '' : String(value).trim();
}

// ── コマンド実行 ─────────────────────────────────────────

function run(cmd, args, { cwd = ROOT, input = null, quiet = true } = {}) {
  return new Promise((resolve) => {
    // Windows では npx などが .cmd 経由でしか呼べないため shell を挟む必要があるが、
    // shell:true と配列の args を素直に組み合わせると Node が非推奨警告を出す
    // （naive に連結されるだけでエスケープされないため）。ここでは自分で
    // 1本のコマンド文字列に組み立てて渡し、args 配列は使わない形にする。
    const onWindows = process.platform === 'win32';
    const quote = (a) => `"${String(a).replace(/"/g, '""')}"`;
    const spawnCmd  = onWindows ? [cmd, ...args].map(quote).join(' ') : cmd;
    const spawnArgs = onWindows ? undefined : args;
    const p = spawn(spawnCmd, spawnArgs, {
      cwd,
      shell: onWindows,
      stdio: [input === null ? 'ignore' : 'pipe', 'pipe', 'pipe'],
    });
    let out = '', err = '';
    p.stdout.on('data', (d) => { out += d; if (!quiet) process.stdout.write(d); });
    p.stderr.on('data', (d) => { err += d; if (!quiet) process.stderr.write(d); });
    p.on('error', (e) => resolve({ code: -1, out, err: String(e.message) }));
    p.on('close', (code) => resolve({ code, out, err }));
    if (input !== null) { p.stdin.write(input); p.stdin.end(); }
  });
}

// 秘密情報を渡す実行。値は必ず標準入力から流し込み、引数には決して置かない。
// 引数に置くと、シェルの履歴と、同じ機械の他利用者から見えるプロセス一覧に残る。
const runSecret = (cmd, args, value, opts = {}) => run(cmd, args, { ...opts, input: value });

async function has(cmd) {
  const probe = process.platform === 'win32' ? ['where', [cmd]] : ['which', [cmd]];
  const r = await run(probe[0], probe[1]);
  return r.code === 0;
}

// ── 合言葉の生成 ─────────────────────────────────────────

// 閲覧用のパスワードは人が手で打つので、紛らわしい文字（0とO、1とlとI）を外す。
// Basic 認証は送出時の文字コードが規格で定まっておらず、記号や日本語だと
// 環境によって弾かれるため、いずれも英数字だけで作る。
const HUMAN_CHARS   = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const MACHINE_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

function generate(len, chars) {
  let s = '';
  for (let i = 0; i < len; i++) s += chars[randomInt(chars.length)];
  return s;
}

// ── 状態の保存 ───────────────────────────────────────────
// セットアップは必ずどこかで失敗する（トークンの権限不足が典型）。
// 済んだ段階を覚えておいて、再実行したら続きから再開できるようにする。
// **このファイルには秘密情報を入れない。**

function loadState(mock) {
  try { return JSON.parse(fs.readFileSync(stateFile(mock), 'utf8')); }
  catch { return { done: [] }; }
}

function saveState(s, mock) {
  const f = stateFile(mock);
  fs.mkdirSync(path.dirname(f), { recursive: true });
  fs.writeFileSync(f, JSON.stringify(s, null, 2) + '\n');
}

// ── 純粋な処理（--self-test で検証する） ─────────────────

// wrangler が KV を作ったときの出力から ID を拾う。出力の書式は版によって
// 変わる（TOML 片だったり JSON 片だったり）ので、書式ではなく
// 「32桁の16進数」という値の形で拾う。
export function parseKvId(text) {
  const m = String(text).match(/\b[0-9a-f]{32}\b/);
  return m ? m[0] : null;
}

// deploy の出力から Worker の URL を拾う。
export function parseWorkerUrl(text) {
  const m = String(text).match(/https:\/\/[a-z0-9-]+\.[a-z0-9-]+\.workers\.dev/i);
  return m ? m[0] : null;
}

// 資料ページのリンク差し替え。作者の Worker を利用者のものに置き換える。
export function replaceDocUrl(html, workerUrl) {
  return html.replace(DOC_URL_RE, workerUrl.replace(/\/$/, '') + '/');
}

// wrangler.toml から、KV の ID だけを実値に差し替えた控えを作る。
// 実値を wrangler.toml 側に書くとコミットして公開してしまうので、
// 生成物は追跡しない別ファイルに逃がす。
export function buildLocalToml(baseToml, kvId) {
  return '# このファイルは tools/setup.mjs が生成します。追跡しません（.gitignore 済み）。\n'
       + '# KV の ID は環境ごとに違うため、雛形の wrangler.toml とは分けてあります。\n'
       + baseToml.replace(/^(\s*id\s*=\s*).*$/m, `$1"${kvId}"`);
}

// 監視条件を組み立てる。既存の selectors などは触らずに groups だけ差し替える。
export function buildConfig(base, { primaryUrls, madori, referenceUrls, docsBaseUrl }) {
  const groups = [{ name: '第一希望', notify: true, madori, urls: primaryUrls }];
  if (referenceUrls.length) {
    groups.push({ name: '参考', notify: false, madori: [], urls: referenceUrls });
  }
  return { ...base, groups, docs_base_url: docsBaseUrl };
}

// ── 各段階 ───────────────────────────────────────────────

async function stepTools() {
  const need = [['git', 'git'], ['gh', 'GitHub CLI'], ['node', 'Node.js']];
  let missing = [];
  for (const [cmd, label] of need) {
    if (await has(cmd)) ok(`${label} は入っています`);
    else { bad(`${label} が見つかりません`); missing.push(label); }
  }
  if (missing.length) {
    warn('呼び水スクリプト（tools/setup.sh）から起動し直してください。');
    note('前提コマンドの導入はそちらが行います。');
    throw new Error(`前提コマンドが足りません: ${missing.join(', ')}`);
  }
  const major = Number(process.versions.node.split('.')[0]);
  if (major < 18) throw new Error(`Node 18 以上が必要です（現在 ${process.versions.node}）`);
  ok(`Node ${process.versions.node}`);
  return {};
}

async function stepGhAuth() {
  let r = await run('gh', ['auth', 'status']);
  if (r.code !== 0) {
    guide([
      'GitHub へのログインが必要です。ブラウザが開きます。',
      '画面の指示にしたがって承認してください。',
    ]);
    if (!(await askYesNo('ログインを始めますか？'))) throw new Error('中止しました');
    r = await run('gh', ['auth', 'login', '--web', '--git-protocol', 'https'], { quiet: false });
    if (r.code !== 0) throw new Error('ログインに失敗しました');
  }
  const who = await run('gh', ['api', 'user', '--jq', '.login']);
  if (who.code !== 0) throw new Error('ログイン状態を確認できませんでした');
  const login = who.out.trim();
  ok(`GitHub にログイン済みです（${login}）`);
  return { login };
}

async function stepFork(state) {
  const login = state.login;
  const remote = await run('git', ['remote', 'get-url', 'origin']);
  const url = remote.out.trim();

  if (url.includes(`${login}/ur-monitor`)) {
    ok('すでにご自身のフォークで作業しています');
    return { repo: `${login}/ur-monitor` };
  }

  say('  いまの作業場所は作者のリポジトリを指しています。');
  note('  フォーク（自分用の複製）を作り、以降はそちらへ変更を送ります。');
  if (!(await askYesNo('フォークを作りますか？'))) throw new Error('中止しました');

  const repo = `${login}/ur-monitor`;

  if (state.mock) {
    ok(`[MOCK] フォークを作り、origin を ${repo} に向けたものとして進めます`);
    note('実際の git remote は変更していません。');
    return { repo };
  }

  const f = await run('gh', ['repo', 'fork', UPSTREAM, '--clone=false', '--remote=false']);
  if (f.code !== 0 && !/already exists/i.test(f.err)) throw new Error(`フォークに失敗しました: ${f.err.trim()}`);

  // origin を自分のフォークへ向け直す。作者のリポジトリには push 権限が無いため、
  // ここを直さないと後の段階（設定の反映）で必ず失敗する。
  await run('git', ['remote', 'set-url', 'origin', `https://github.com/${repo}.git`]);
  await run('git', ['remote', 'add', 'upstream', `https://github.com/${UPSTREAM}.git`]);
  ok(`フォークを作り、origin を ${repo} に向けました`);
  return { repo };
}

async function stepConfig(state) {
  const file = path.join(ROOT, 'config.json');
  const base = JSON.parse(fs.readFileSync(file, 'utf8'));

  guide([
    'UR のサイトで、見張りたいページを開いてください。',
    `${C.d}団地ページ（例：.../saitama/50_3030.html）が確実です。${C.x}`,
    `${C.d}エリアや駅で絞った検索結果ページでも構いません。${C.x}`,
    'そのページの URL をブラウザからコピーして、下に貼ってください。',
  ]);

  const primaryUrls = [];
  for (;;) {
    const u = await ask(primaryUrls.length ? '第一希望の URL（追加、空行で次へ）: ' : '第一希望の URL: ');
    if (!u) { if (primaryUrls.length) break; say(`  ${C.r}最低1本は必要です。${C.x}`); continue; }
    if (!/^https:\/\/www\.ur-net\.go\.jp\//.test(u)) { say(`  ${C.r}UR のページの URL を貼ってください。${C.x}`); continue; }
    primaryUrls.push(u);
    ok(`登録しました（${primaryUrls.length}本目）`);
  }

  say();
  note('通知したい間取りをカンマ区切りで入力します（例: 1LDK,2DK）。');
  note('空のまま Enter を押すと、間取りを問わずすべて通知します。');
  const madoriRaw = await ask('間取り: ');
  const madori = madoriRaw ? madoriRaw.split(',').map((s) => s.trim()).filter(Boolean) : [];

  say();
  note('参考として一覧にだけ出したいページがあれば追加できます（通知は鳴りません）。');
  const referenceUrls = [];
  for (;;) {
    const u = await ask('参考の URL（不要なら空行）: ');
    if (!u) break;
    if (!/^https:\/\/www\.ur-net\.go\.jp\//.test(u)) { say(`  ${C.r}UR のページの URL を貼ってください。${C.x}`); continue; }
    referenceUrls.push(u);
    ok(`登録しました（${referenceUrls.length}本目）`);
  }

  const docsBaseUrl = `https://${state.login}.github.io/ur-monitor`;
  const next = buildConfig(base, { primaryUrls, madori, referenceUrls, docsBaseUrl });
  const outFile = outPath(state, 'config.json');
  fs.writeFileSync(outFile, JSON.stringify(next, null, 2) + '\n');

  // 書いた直後に読み直して妥当性を見る。壊れた config.json のまま先へ進むと、
  // 失敗するのは本番実行のときになる。
  JSON.parse(fs.readFileSync(outFile, 'utf8'));
  ok(`config.json を更新しました（${state.mock ? '模擬出力へ、' : ''}第一希望 ${primaryUrls.length}本 / 参考 ${referenceUrls.length}本）`);
  return {};
}

async function stepSlack(state) {
  guide([
    `${C.B}Slack の通知先を用意します。${C.x}`,
    '',
    '「Incoming Webhook URL」を1本手に入れるのがゴールです。',
    `${C.d}これは Slack が発行する専用の URL で、そこへメッセージを送ると${C.x}`,
    `${C.d}指定したチャンネルに投稿される、という仕組みです。${C.x}`,
    '',
    `${C.B}ブラウザでの作業:${C.x}`,
    '  1. https://api.slack.com/apps を開く',
    '  2. Create New App → From scratch でアプリを作る',
    '  3. Incoming Webhooks を開き、スイッチを On にする',
    '  4. Add New Webhook to Workspace を押し、通知したいチャンネルを選ぶ',
    '  5. 表示された URL をコピーする',
  ]);
  if (state.mock) note('[MOCK] ブラウザは開きません。実在しない URL でも構いません。');
  else await openBrowser('https://api.slack.com/apps');

  for (;;) {
    const hook = await askSecret('Webhook URL を貼り付け（表示されません）: ');
    if (!/^https:\/\/hooks\.slack\.com\//.test(hook)) {
      bad('Slack の Webhook URL ではないようです。もう一度貼ってください。');
      continue;
    }

    if (state.mock) {
      ok('[MOCK] テスト投稿・GitHub への登録はどちらも行いません');
      note('形式が正しいことだけ確認し、登録したものとして進めます。');
      return {};
    }

    // 登録する前に実際に投げてみる。「登録したつもり」で最後まで進み、
    // 初回通知のときに初めて気づく、という失敗を防ぐ。
    say('  テスト投稿を送っています...');
    const res = await fetch(hook, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: 'ur-monitor のセットアップ確認です。この投稿が見えていれば通知先の設定は完了しています。' }),
    }).catch((e) => ({ ok: false, statusText: e.message }));

    if (!res.ok) { bad(`送信できませんでした（${res.statusText}）。URL を確認してください。`); continue; }
    ok('テスト投稿を送りました。Slack を確認してください。');
    if (!(await askYesNo('投稿は届きましたか？'))) continue;

    const r = await runSecret('gh', ['secret', 'set', 'SLACK_WEBHOOK_URL', '--repo', state.repo], hook);
    if (r.code !== 0) throw new Error(`GitHub への登録に失敗しました: ${r.err.trim()}`);
    ok('SLACK_WEBHOOK_URL を GitHub に登録しました');
    return {};
  }
}

async function stepPat(state) {
  if (state.mock) {
    ok('[MOCK] トークンの発行画面は開きません');
    note('実際のトークンは発行していません。secrets 段階でも Cloudflare / GitHub には登録しません。');
    return { pat: `mock-pat-${generate(12, MACHINE_CHARS)}` };
  }

  guide([
    `${C.B}Cloudflare から GitHub を起動するためのトークンを発行します。${C.x}`,
    '',
    `${C.d}このトークンで出来るのは「このリポジトリのワークフローを動かすこと」だけです。${C.x}`,
    `${C.d}コードも他のリポジトリも読めないため、万一漏れても被害は限定されます。${C.x}`,
    '',
    `${C.B}ブラウザでの作業:${C.x}`,
    '  1. 開いた画面で次のとおり設定する',
    `     Token name        : ${C.B}ur-monitor-trigger${C.x}`,
    `     Expiration        : ${C.B}No expiration${C.x}`,
    `     Repository access : ${C.B}Only select repositories${C.x} → ${state.repo}`,
    `     Permissions       : Actions を ${C.B}Read and write${C.x}`,
    '  2. Generate token を押し、表示された文字列をコピーする',
    '',
    `${C.y}この文字列はこの画面でしか見られません。${C.x}`,
  ]);
  await openBrowser('https://github.com/settings/personal-access-tokens/new');

  for (;;) {
    const token = await askSecret('トークンを貼り付け（表示されません）: ');
    if (!token) { bad('入力してください。'); continue; }

    // 権限が足りているかを、登録する前に GitHub に問い合わせて確かめる。
    // 権限不足は最も多い失敗で、しかも症状が「静かに動かない」になる。
    say('  トークンを確認しています...');
    const res = await fetch(`https://api.github.com/repos/${state.repo}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.github+json' },
    }).catch((e) => ({ ok: false, status: 0, statusText: e.message }));

    if (res.status === 401) { bad('トークンが認識されませんでした。貼り直してください。'); continue; }
    if (!res.ok) { bad(`確認できませんでした（${res.status}）。対象リポジトリの指定を見直してください。`); continue; }
    ok('トークンは有効です');
    return { pat: token };  // メモリ内でのみ持ち回り、状態ファイルには書かない
  }
}

async function stepCfAuth(state) {
  if (state.mock) {
    ok('[MOCK] Cloudflare へのログインは省略します');
    return {};
  }

  let r = await run('npx', ['--yes', 'wrangler', 'whoami']);
  if (r.code !== 0 || /not authenticated|You are not/i.test(r.out + r.err)) {
    guide([
      'Cloudflare へのログインが必要です。ブラウザが開きます。',
      'アカウントが無ければ、その場で無料で作れます。',
    ]);
    if (!(await askYesNo('ログインを始めますか？'))) throw new Error('中止しました');
    r = await run('npx', ['--yes', 'wrangler', 'login'], { quiet: false });
    if (r.code !== 0) throw new Error('ログインに失敗しました');
  }
  ok('Cloudflare にログイン済みです');
  return {};
}

async function stepDeploy(state) {
  if (state.mock) {
    const baseToml = fs.readFileSync(path.join(TRIGGER_DIR, 'wrangler.toml'), 'utf8');
    const kvId = state.kvId || generate(32, 'abcdef0123456789');
    const workerUrl = state.workerUrl
      || `https://ur-monitor-trigger.mock-${generate(6, 'abcdefghijklmnopqrstuvwxyz0123456789')}.workers.dev`;
    fs.writeFileSync(outPath(state, path.join('trigger', 'wrangler.local.toml')), buildLocalToml(baseToml, kvId));
    ok(`[MOCK] 保管庫を作ったものとして進めます（${kvId.slice(0, 8)}…）`);
    ok(`[MOCK] Worker を配備したものとして進めます: ${workerUrl}`);
    note('実際の Cloudflare には触れていません。');
    return { kvId, workerUrl };
  }

  let kvId = state.kvId;

  if (!kvId) {
    say('  保管庫（KV）を作っています...');
    const r = await run('npx', ['--yes', 'wrangler', 'kv', 'namespace', 'create', 'STORE'], { cwd: TRIGGER_DIR });
    kvId = parseKvId(r.out + r.err);
    if (!kvId) throw new Error(`KV を作れませんでした: ${(r.err || r.out).trim().slice(0, 300)}`);
    ok(`保管庫を作りました（${kvId.slice(0, 8)}…）`);
  } else {
    ok('保管庫は作成済みです');
  }

  const baseToml = fs.readFileSync(path.join(TRIGGER_DIR, 'wrangler.toml'), 'utf8');
  fs.writeFileSync(LOCAL_TOML, buildLocalToml(baseToml, kvId));
  note('wrangler.local.toml を生成しました（追跡しません）');

  say('  Worker を配備しています...');
  const d = await run('npx', ['--yes', 'wrangler', 'deploy', '--config', 'wrangler.local.toml'], { cwd: TRIGGER_DIR });
  const workerUrl = parseWorkerUrl(d.out + d.err);
  if (d.code !== 0 || !workerUrl) throw new Error(`配備に失敗しました: ${(d.err || d.out).trim().slice(0, 300)}`);

  ok(`Worker を配備しました: ${workerUrl}`);
  note('5分おきの起動設定（cron）も同時に入りました');
  return { kvId, workerUrl };
}

async function stepSecrets(state) {
  // API_TOKEN は機械同士が突き合わせるだけの値で、人が目にする必要が一切ない。
  // VIEW_* は閲覧時に人が打つので、紛らわしい文字を外した文字種で作る。
  const apiToken     = generate(48, MACHINE_CHARS);
  const viewUser     = generate(8,  HUMAN_CHARS);
  const viewPassword = generate(24, HUMAN_CHARS);

  if (state.mock) {
    note('[MOCK] Cloudflare / GitHub への登録は行いません。');
    ok('[MOCK] GITHUB_TOKEN / API_TOKEN / VIEW_USER / VIEW_PASSWORD を Cloudflare に登録したものとして進めます');
    ok('[MOCK] STORE_URL / STORE_TOKEN を GitHub に登録したものとして進めます');
    ok('[MOCK] 一覧ページへの接続確認は省略します');
  } else {
    const cfArgs = (name) => ['--yes', 'wrangler', 'secret', 'put', name, '--config', 'wrangler.local.toml'];
    const put = async (name, value) => {
      const r = await runSecret('npx', cfArgs(name), value + '\n', { cwd: TRIGGER_DIR });
      if (r.code !== 0) throw new Error(`${name} の登録に失敗しました: ${r.err.trim().slice(0, 200)}`);
      ok(`${name} を Cloudflare に登録しました`);
    };

    await put('GITHUB_TOKEN', state.pat);
    await put('API_TOKEN', apiToken);
    await put('VIEW_USER', viewUser);
    await put('VIEW_PASSWORD', viewPassword);

    for (const [name, value] of [['STORE_URL', state.workerUrl], ['STORE_TOKEN', apiToken]]) {
      const r = await runSecret('gh', ['secret', 'set', name, '--repo', state.repo], value);
      if (r.code !== 0) throw new Error(`${name} の登録に失敗しました: ${r.err.trim()}`);
      ok(`${name} を GitHub に登録しました`);
    }

    // 登録した合言葉で実際に開けるかを確かめる。ここを飛ばすと、
    // 閲覧できないことに気づくのは一覧を見たくなったときになる。
    say('  一覧ページに接続して確かめています...');
    const auth = Buffer.from(`${viewUser}:${viewPassword}`).toString('base64');
    const res = await fetch(state.workerUrl, { headers: { Authorization: `Basic ${auth}` } })
      .catch((e) => ({ status: 0, statusText: e.message }));
    if (res.status === 200) ok('認証を通って一覧ページを開けました');
    else warn(`確認できませんでした（${res.status}）。配備直後は少し待つと通ることがあります。`);
  }

  // 閲覧用の ID とパスワードは利用者が使うので、控えを残す。リポジトリの中に
  // 置くとコミット事故が起きるため、ホームディレクトリに書く。--mock のときは
  // 本物の控えと混ざらないよう、別のファイル名にする。
  const credFile = path.join(os.homedir(), state.mock ? 'ur-monitor-credentials.mock.txt' : 'ur-monitor-credentials.txt');
  fs.writeFileSync(credFile,
    (state.mock ? '【模擬実行】実際には登録していません\n\n' : '')
    + `ur-monitor 空き部屋一覧の閲覧用\n\n`
    + `URL      : ${state.workerUrl}\n`
    + `ID       : ${viewUser}\n`
    + `パスワード : ${viewPassword}\n\n`
    + `このファイルは他人に見せないでください。\n`, { mode: 0o600 });

  say();
  say(`  ${C.B}${C.y}▼ 一覧を見るための ID とパスワードです。ここでしか表示しません。${C.x}`);
  say(`     URL      : ${C.B}${state.workerUrl}${C.x}`);
  say(`     ID       : ${C.B}${viewUser}${C.x}`);
  say(`     パスワード : ${C.B}${viewPassword}${C.x}`);
  say();
  note(`控えを ${credFile} にも保存しました`);
  await ask('控えたら Enter を押してください: ');

  return { viewSaved: true };
}

async function stepDocs(state) {
  // 読み込みは常に本物の docs/ から。書き込み先だけ --mock で切り替える
  // （本番の資料ページに、模擬実行で作った偽の Worker URL が焼き込まれるのを防ぐ）。
  let changed = 0;
  for (const f of DOC_FILES) {
    const p = path.join(ROOT, 'docs', f);
    if (!fs.existsSync(p)) continue;
    const before = fs.readFileSync(p, 'utf8');
    const after  = replaceDocUrl(before, state.workerUrl);
    if (before !== after) { fs.writeFileSync(outPath(state, path.join('docs', f)), after); changed++; }
  }
  ok(`資料ページのリンクを差し替えました（${state.mock ? '模擬出力へ、' : ''}${changed}ファイル）`);

  if (state.mock) {
    note('[MOCK] GitHub Pages の有効化・コミット・push は行いません。');
    return {};
  }

  // GitHub Pages は有効化できれば有効にする。失敗しても止めない。
  // 資料の公開は任意で、通知そのものには関係しないため。
  const pages = await run('gh', ['api', '-X', 'POST', `repos/${state.repo}/pages`,
    '-f', 'source[branch]=main', '-f', 'source[path]=/docs']);
  if (pages.code === 0) ok('GitHub Pages を有効にしました');
  else note('GitHub Pages は手動で有効にしてください（Settings → Pages）');

  say('  設定をフォークへ反映しています...');
  await run('git', ['add', 'config.json', 'docs']);
  const st = await run('git', ['status', '--porcelain']);
  if (!st.out.trim()) { ok('反映すべき変更はありませんでした'); return {}; }

  await run('git', ['commit', '-m', '自分の監視条件と一覧の場所を設定']);
  const branch = (await run('git', ['branch', '--show-current'])).out.trim() || 'main';
  const push = await run('git', ['push', 'origin', `${branch}:main`]);
  if (push.code !== 0) throw new Error(`反映に失敗しました: ${push.err.trim()}`);
  ok('フォークへ反映しました');
  return {};
}

async function stepSeed(state) {
  guide([
    `${C.B}最後に、監視の土台になる「前回の状態」を用意します。${C.x}`,
    '',
    `${C.d}保管庫が空のままだと「前回は0件」とみなされ、いま出ている部屋が${C.x}`,
    `${C.d}すべて新着として通知されてしまいます。それを避けるための一手です。${C.x}`,
  ]);

  if (state.mock) {
    ok('[MOCK] ワークフローの起動は行いません');
    note('実際の Actions は動いていません。');
    return {};
  }

  const r = await run('gh', ['workflow', 'run', 'monitor.yml', '--repo', state.repo, '-f', 'seed_state=true']);
  if (r.code !== 0) throw new Error(`起動に失敗しました: ${r.err.trim()}`);
  ok('初回の準備を開始しました');

  say();
  note('GitHub の Actions タブで結果を確認できます:');
  say(`  ${C.b}https://github.com/${state.repo}/actions${C.x}`);
  say();
  warn('この直後の1回だけ、いま出ている部屋がまとめて通知されることがあります。');
  note('2回目以降は、新しく出た部屋だけが通知されます。');
  return {};
}

// ── ブラウザを開く ───────────────────────────────────────

async function openBrowser(url) {
  const cmd = process.platform === 'win32' ? ['cmd', ['/c', 'start', '', url]]
            : process.platform === 'darwin' ? ['open', [url]]
            : ['xdg-open', [url]];
  const r = await run(cmd[0], cmd[1]);
  if (r.code !== 0) note(`ブラウザで開いてください: ${url}`);
}

// ── 進行 ─────────────────────────────────────────────────

const STEPS = [
  { id: 'tools',   title: '前提コマンドを確かめる',           run: stepTools },
  { id: 'gh-auth', title: 'GitHub にログインする',            run: stepGhAuth },
  { id: 'fork',    title: '自分用の複製を作る',               run: stepFork },
  { id: 'config',  title: '見張るページと通知条件を決める',    run: stepConfig },
  { id: 'slack',   title: 'Slack の通知先を用意する',         run: stepSlack },
  { id: 'pat',     title: 'GitHub のトークンを発行する',      run: stepPat },
  { id: 'cf-auth', title: 'Cloudflare にログインする',        run: stepCfAuth },
  { id: 'deploy',  title: '保管庫を作り、時計を配備する',      run: stepDeploy },
  { id: 'secrets', title: '合言葉を作って登録する',           run: stepSecrets },
  { id: 'docs',    title: '一覧の場所を資料に反映する',        run: stepDocs },
  { id: 'seed',    title: '初回の状態を用意する',             run: stepSeed },
];

// pat と secrets は同じ実行の中で連続していないと成立しない。
// トークンを状態ファイルに書かない方針のため、再開時は pat からやり直す。
const NEEDS_PAT = new Set(['secrets']);

// main() の進行判定だけを取り出した純粋関数。副作用（実際の段階の実行）を
// 起こさずに「どの順で段階が実行されるか」だけを求める。ネットワークもファイルも
// 使わないので --self-test で検証できる。main() 側のループと分岐を変えたら
// ここも必ず合わせること。
export function planStepOrder(steps, needsPatIds, doneIds, hasPat) {
  const done = new Set(doneIds);
  const order = [];
  for (let i = 0; i < steps.length; i++) {
    const step = steps[i];
    if (done.has(step.id)) continue;
    if (needsPatIds.has(step.id) && !hasPat) {
      done.delete('pat');
      i = steps.findIndex((s) => s.id === 'pat') - 1;
      continue;
    }
    order.push(step.id);
    done.add(step.id);
    if (step.id === 'pat') hasPat = true;
  }
  return order;
}

async function main() {
  const args = process.argv.slice(2);
  if (args.includes('--help') || args.includes('-h')) return printHelp();
  if (args.includes('--self-test')) return selfTest();

  const mock = args.includes('--mock');
  const state = loadState(mock);
  state.mock = mock;
  if (args.includes('--restart')) state.done = [];

  say();
  say(`${C.B}ur-monitor セットアップ${mock ? `${C.y}（模擬実行）${C.x}` : ''}${C.x}`);
  say(`${C.d}UR賃貸の空き部屋を見張り、条件に合う新着を Slack へ通知する仕組みを用意します。${C.x}`);
  if (mock) {
    note('Cloudflare / GitHub の実アカウントは一切変更しません。生成物は tools/.mock-run/ に置きます。');
  }
  if (state.done.length) note(`前回の続きから再開します（${state.done.length}段階が完了済み）`);

  // 「pat が要るのに無ければ pat からやり直す」という巻き戻しは、状態を
  // 直接いじりながらループするとバグを踏みやすい（実際に、完了済みの
  // secrets 段階まで作り直してしまう不具合を一度作った）。判定は
  // planStepOrder() に切り出してあるので、ここでは決まった順番をなぞるだけにする。
  for (const id of planStepOrder(STEPS, NEEDS_PAT, state.done, Boolean(state.pat))) {
    const i = STEPS.findIndex((s) => s.id === id);
    const step = STEPS[i];
    heading(i + 1, STEPS.length, step.title);
    try {
      Object.assign(state, (await step.run(state)) || {});
    } catch (e) {
      say();
      bad(e.message);
      say();
      note('直してから同じコマンドをもう一度実行すると、この段階から再開します。');
      const { pat, ...persist } = state;
      saveState(persist, mock);
      process.exit(1);
    }
    if (!state.done.includes(step.id)) state.done.push(step.id);
    const { pat, ...persist } = state;
    saveState(persist, mock);
  }

  say();
  if (mock) {
    say(`${C.y}${C.B}模擬実行が完了しました。${C.x}`);
    say();
    say('  Cloudflare にも GitHub にも変更は加わっていません。');
    say(`  生成物は ${C.b}tools/.mock-run/${C.x} にあります。消しても構いません。`);
    say('  本番として進めるときは、--mock を外してもう一度実行してください');
    say('  （模擬実行の進み具合とは別に記録されるので、最初からになります）。');
  } else {
    say(`${C.g}${C.B}セットアップが完了しました。${C.x}`);
    say();
    say('  これ以降は放っておいて構いません。08:00〜21:00 の間、5分おきに');
    say('  自動で見に行き、条件に合う新着が出たときだけ Slack が鳴ります。');
    say();
    say(`  空き部屋一覧 : ${C.b}${state.workerUrl}${C.x}`);
    say(`  実行の記録   : ${C.b}https://github.com/${state.repo}/actions${C.x}`);
  }
  say();
  // readline を開いたままだと標準入力を待ち続け、プロセスが終わらない。
  closeReadline();
}

function printHelp() {
  say(`
ur-monitor セットアップウィザード

  使い方:
    node tools/setup.mjs [オプション]

  オプション:
    --mock        Cloudflare / GitHub の実アカウントを変更せずに全段階を試す
                  （Slack 送信・トークン発行・Worker 配備・合言葉の登録・
                  push・ワークフロー起動をすべて省略し、模擬の値で進める。
                  進み具合は本番とは別に記録するので、毎回 --mock を付けること）
    --restart     最初からやり直す（登録済みの内容は消えません）
    --self-test   ネットワークに触れずに内部処理だけを検証する
    --help        この説明

  通常は呼び水から起動してください（Windows / Mac 共通）。
    bash tools/setup.sh
`);
}

// ── 自己検査 ─────────────────────────────────────────────
// テストスイートが無いリポジトリなので、少なくとも文字列を組み立てる処理だけは
// ネットワークもアカウントも無しに検証できるようにしておく。

function selfTest() {
  let failed = 0;
  const check = (name, actual, expected) => {
    const a = JSON.stringify(actual), e = JSON.stringify(expected);
    if (a === e) { ok(name); } else { bad(`${name}\n     期待: ${e}\n     実際: ${a}`); failed++; }
  };

  say(`\n${C.B}自己検査${C.x}\n`);

  check('KV の ID を拾える',
    parseKvId('{ "binding": "STORE", "id": "0123456789abcdef0123456789abcdef" }'),
    '0123456789abcdef0123456789abcdef');
  check('ID が無ければ null', parseKvId('作成に失敗しました'), null);

  check('Worker の URL を拾える',
    parseWorkerUrl('Published ur-monitor-trigger (1.2 sec)\n  https://ur-monitor-trigger.example.workers.dev'),
    'https://ur-monitor-trigger.example.workers.dev');

  check('資料のリンクを差し替える',
    replaceDocUrl('<a href="https://ur-monitor-trigger.tsutao.workers.dev/">一覧</a>',
                  'https://ur-monitor-trigger.me.workers.dev'),
    '<a href="https://ur-monitor-trigger.me.workers.dev/">一覧</a>');

  check('末尾の / が重複しない',
    replaceDocUrl('https://ur-monitor-trigger.a.workers.dev', 'https://ur-monitor-trigger.b.workers.dev/'),
    'https://ur-monitor-trigger.b.workers.dev/');

  const toml = buildLocalToml('binding = "STORE"\nid = "ここに KV の ID を入れる"\n', 'abc');
  check('KV の ID だけを差し替える', /id = "abc"/.test(toml) && /binding = "STORE"/.test(toml), true);

  const cfg = buildConfig({ selectors: { name: '.x' }, zero_streak_limit: 18 }, {
    primaryUrls: ['https://www.ur-net.go.jp/a.html'],
    madori: ['2DK'],
    referenceUrls: [],
    docsBaseUrl: 'https://me.github.io/ur-monitor',
  });
  check('selectors を保つ', cfg.selectors, { name: '.x' });
  check('参考が無ければグループは1つ', cfg.groups.length, 1);
  check('第一希望は通知する', cfg.groups[0].notify, true);

  const cfg2 = buildConfig({}, {
    primaryUrls: ['https://www.ur-net.go.jp/a.html'],
    madori: [],
    referenceUrls: ['https://www.ur-net.go.jp/b.html'],
    docsBaseUrl: 'https://me.github.io/ur-monitor',
  });
  check('参考は通知しない', cfg2.groups[1].notify, false);

  const pw = generate(24, HUMAN_CHARS);
  check('合言葉の長さ', pw.length, 24);
  check('合言葉は英数字のみ', /^[0-9A-Za-z]+$/.test(pw), true);
  check('紛らわしい文字を含まない', /[0O1lI]/.test(generate(400, HUMAN_CHARS)), false);

  // ここは実際に踏んだバグの再現テスト。secrets まで完了済みの状態で
  // もう一度実行しても、合言葉を作り直す pat / secrets が再実行されないこと。
  const allIds = STEPS.map((s) => s.id);
  check('完了済みの再実行では何も走らない（合言葉が作り直されない）',
    planStepOrder(STEPS, NEEDS_PAT, allIds, false), []);

  // pat の直後、secrets の前で中断 → 再開したプロセスは state.pat を
  // 持たない。secrets の手前まで完了済みなら、pat だけやり直して secrets
  // 以降へ続くこと（すでに終わった段階を巻き込んで作り直さないこと）。
  const doneBeforeSecrets = allIds.slice(0, allIds.indexOf('secrets'));
  check('secrets の手前で中断すると pat から secrets 以降へ続く',
    planStepOrder(STEPS, NEEDS_PAT, doneBeforeSecrets, false),
    ['pat', 'secrets', 'docs', 'seed']);

  // 何も完了していない、まっさらな実行では、全段階が定義順に並ぶこと。
  check('初回実行では全段階が順番どおり',
    planStepOrder(STEPS, NEEDS_PAT, [], false), allIds);

  say();
  if (failed) { bad(`${failed}件 失敗しました`); process.exit(1); }
  ok('すべて通りました');
}

main().catch((e) => { bad(e.stack || e.message); process.exit(1); });
