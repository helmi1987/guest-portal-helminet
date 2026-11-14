#!/usr/bin/env bash
# Prints the GitHub release body for TAG from a notes file with one section per language:
#   <!-- lang:en -->  ...  <!-- lang:de -->  ...  <!-- lang:it -->  ...  <!-- lang:fr -->  ...
# English is shown, German/Italian/French are folded. Each section is wrapped in
# <!-- lang:xx --> ... <!-- /lang --> so the product website can take it as its translation.
# Usage: release-body.sh NOTES_FILE TAG
set -euo pipefail

file="$1"; tag="$2"
section() {
  awk -v want="$1" '
    /^<!-- lang:[a-z][a-z] -->[[:space:]]*$/ { cur = substr($0, 11, 2); next }
    cur == want { print }' "$file" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}'
}
block() {
  echo "<!-- lang:$1 -->"
  section "$1"
  echo "<!-- /lang -->"
}

block en
echo
echo "<details>"
echo "<summary>Deutsch · Italiano · Français</summary>"
for l in de it fr; do
  case "$l" in de) name="Deutsch" ;; it) name="Italiano" ;; fr) name="Français" ;; esac
  echo
  echo "**$name**"
  echo
  block "$l"
done
echo
echo "</details>"
echo
echo "Image: \`ghcr.io/${GITHUB_REPOSITORY,,}:${tag#v}\`"
