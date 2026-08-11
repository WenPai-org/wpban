#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for tool in wp msgfmt msgcmp; do
  command -v "$tool" >/dev/null || { echo "BLOCKED: missing $tool" >&2; exit 2; }
done
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
wp i18n make-pot . "$work/wpban.pot" \
  --domain=wpban \
  --exclude=.git,node_modules,vendor,lib/plugin-update-checker \
  --skip-js >/dev/null
msgfmt --check --check-format --output-file=/dev/null languages/wpban-zh_CN.po
msgcmp --use-fuzzy languages/wpban-zh_CN.po "$work/wpban.pot"
printf 'PASS: wpban zh_CN catalog covers the current wpban source catalog\n'
