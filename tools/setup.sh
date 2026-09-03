#!/bin/bash
# ur-monitor セットアップの呼び水（Windows / Mac 共通）。
#
# 【使い方】
#   bash tools/setup.sh          通常のセットアップ
#   bash tools/setup.sh --mock   Cloudflare / GitHub に触れずに動きを確認する
#
# 【なぜ Windows も bash なのか】
# git を入れると、Windows でも Git Bash が必ず付いてくる。PowerShell 用に
# 別スクリプトを持つと、テストスイートが無いこのリポジトリでは片方の
# 修正漏れに気づけるのが本番実行時になる。前提として git を要求している
# 以上、bash 1本にまとめるほうが書き分けの手間もリスクも小さい。
#
# 【この呼び水がすること】
# 前提コマンド（git / GitHub CLI / Node.js）を確かめ、無ければ入れる
# （Windows は winget、Mac は Homebrew）。それ以外の対話・検証・
# API 呼び出しはすべて tools/setup.mjs（Node 製、OS 共通）に任せる。
# ここに処理を増やさないこと。

set -u
cd "$(dirname "${BASH_SOURCE[0]}")/.."

has() { command -v "$1" >/dev/null 2>&1; }

case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*) OS=windows ;;
  Darwin)                OS=mac ;;
  *)                     OS=other ;;
esac

install() {
  local tool="$1"
  case "$OS:$tool" in
    windows:git)  winget install --id Git.Git -e --accept-source-agreements --accept-package-agreements ;;
    windows:gh)   winget install --id GitHub.cli -e --accept-source-agreements --accept-package-agreements ;;
    windows:node) winget install --id OpenJS.NodeJS.LTS -e --accept-source-agreements --accept-package-agreements ;;
    mac:git)  brew install git ;;
    mac:gh)   brew install gh ;;
    mac:node) brew install node ;;
    *)
      echo "${tool} が見つかりません。お使いの環境のパッケージ管理で入れてから、" >&2
      echo "もう一度 bash tools/setup.sh を実行してください。" >&2
      exit 1
      ;;
  esac
}

if [ "$OS" = windows ] && ! has winget; then
  echo "winget が見つかりません。Microsoft Store から「アプリ インストーラー」を" >&2
  echo "入れてから、もう一度実行してください。" >&2
  exit 1
fi

if [ "$OS" = mac ] && ! has brew && { ! has git || ! has gh || ! has node; }; then
  echo "Homebrew を導入しています..."
  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
  # インストール直後はこのシェルにまだ PATH が通っていないことがある。
  [ -x /opt/homebrew/bin/brew ] && eval "$(/opt/homebrew/bin/brew shellenv)"
  [ -x /usr/local/bin/brew ]    && eval "$(/usr/local/bin/brew shellenv)"
fi

installed_any=0
for tool in git gh node; do
  if ! has "$tool"; then
    echo "${tool} を導入しています..."
    install "$tool"
    installed_any=1
  fi
done

if [ "$installed_any" = 1 ]; then
  for tool in git gh node; do
    if ! has "$tool"; then
      echo "" >&2
      echo "${tool} をまだ見つけられません。いったんこのウィンドウを閉じて" >&2
      echo "ターミナルを開き直し、もう一度 bash tools/setup.sh を実行してください" >&2
      echo "（今インストールしたばかりのコマンドが、この画面にはまだ反映されていないだけです）。" >&2
      exit 1
    fi
  done
fi

exec node tools/setup.mjs "$@"
