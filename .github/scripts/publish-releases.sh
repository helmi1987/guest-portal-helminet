#!/usr/bin/env bash
# Publishes a GitHub release for every vX.Y.Z tag that has none yet (oldest first), so missing
# releases are filled in by the next run. Notes: commit subjects since the previous tag; the first
# tag gets the README highlights of its minor version. Needs GH_TOKEN, GITHUB_REPOSITORY and all tags.
set -euo pipefail

repo="${GITHUB_REPOSITORY:?}"
image="ghcr.io/${repo,,}"
existing=$(gh release list --repo "$repo" --limit 1000 --json tagName --jq '.[].tagName')
prev=""
for tag in $(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$'); do
  if grep -qxF "$tag" <<<"$existing"; then
    prev="$tag"; continue
  fi
  notes=$(mktemp)
  {
    if [ -n "$prev" ]; then
      echo "## Changes"
      echo
      git log --no-merges --format='- %s' "$prev..$tag" | grep -v '^- Bump version to ' | head -n 200 || true
    else
      v="${tag#v}"; minor="${v%.*}"
      echo "## Highlights of v$minor"
      echo
      git show "$tag:README.md" | awk -v start="- v$minor:" '
        index($0, start) == 1 { on = 1 }
        on && /^- v[0-9]/ && index($0, start) != 1 { exit }
        on && /^$/ { exit }
        on { print }' || true
    fi
    echo
    echo "Image: \`$image:${tag#v}\`"
  } > "$notes"
  gh release create "$tag" --repo "$repo" --verify-tag --title "$tag" --notes-file "$notes"
  echo "Published release $tag"
  rm -f "$notes"
  prev="$tag"
done
