#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

echo "[repo-audit] root: $ROOT"
echo

echo "== themes =="
find wp-content/themes -maxdepth 1 -mindepth 1 -type d -printf '%f\n' | sort

echo
echo "== plugins =="
find wp-content/plugins -maxdepth 1 -mindepth 1 -type d -printf '%f\n' | sort

echo
echo "== key API usage counts (wp-content) =="
patterns=(
  'register_post_type('
  'register_taxonomy('
  'add_shortcode('
  'register_block_type('
  'wp_enqueue_style('
  'wp_enqueue_script('
  'register_rest_route('
  'add_rewrite_rule('
)
for p in "${patterns[@]}"; do
  count=$( (rg -n -F "$p" wp-content || true) | wc -l | tr -d ' ')
  printf '%-30s %s\n' "$p" "$count"
done

echo
echo "== build/deploy indicators =="
[ -f composer.json ] && echo "composer.json: yes" || echo "composer.json: no"
if find . -maxdepth 6 -type f \( -name package.json -o -name pnpm-lock.yaml -o -name yarn.lock \) | grep -q .; then
  echo "node manifests: yes"
  find . -maxdepth 6 -type f \( -name package.json -o -name pnpm-lock.yaml -o -name yarn.lock \) | sort
else
  echo "node manifests: no"
fi
[ -d .github/workflows ] && echo "github workflows: yes" || echo "github workflows: no"
