#!/usr/bin/env bash
# Publishes a GitHub release for every vX.Y.Z tag that has none yet (oldest first) and updates the body of
# an existing release when its notes file changed. Notes: .github/release-notes/X.Y.Z.md (EN/DE/IT/FR,
# see release-body.sh) of the current checkout; without that file the commit subjects since the previous
# tag (English only). Needs GH_TOKEN, GITHUB_REPOSITORY and all tags.
set -euo pipefail

repo="${GITHUB_REPOSITORY:?}"
dir="$(cd "$(dirname "$0")" && pwd)"
notes_dir="$dir/../release-notes"
existing=$(gh release list --repo "$repo" --limit 1000 --json tagName --jq '.[].tagName')
body=$(mktemp)
prev=""
for tag in $(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$'); do
  file="$notes_dir/${tag#v}.md"
  if [ -f "$file" ]; then
    "$dir/release-body.sh" "$file" "$tag" > "$body"
  elif [ -n "$prev" ]; then
    {
      echo "## Changes"
      echo
      git log --no-merges --format='- %s' "$prev..$tag" | grep -v '^- Bump version to ' | head -n 200 || true
      echo
      echo "Image: \`ghcr.io/${repo,,}:${tag#v}\`"
    } > "$body"
  else
    { echo "First tagged release."; echo; echo "Image: \`ghcr.io/${repo,,}:${tag#v}\`"; } > "$body"
  fi

  if ! grep -qxF "$tag" <<<"$existing"; then
    gh release create "$tag" --repo "$repo" --verify-tag --title "$tag" --notes-file "$body"
    echo "Published release $tag"
  elif [ -f "$file" ] && [ "$(gh release view "$tag" --repo "$repo" --json body --jq .body)" != "$(cat "$body")" ]; then
    gh release edit "$tag" --repo "$repo" --notes-file "$body"
    echo "Updated the notes of release $tag"
  fi
  prev="$tag"
done
rm -f "$body"
