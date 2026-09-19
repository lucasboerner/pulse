#!/usr/bin/env bash
#
# Cut a release tag for the pulse monorepo.
#
# The annotated git tag `vX.Y.Z` is the source of truth for a release: pushing it
# runs `.github/workflows/publish.yml`, which builds `pulse-api` and `pulse-app`
# and pushes them to GHCR tagged `X.Y.Z`, `X.Y` and — for a non-prerelease tag —
# `latest` (see the README, "Releasing"). This script cuts such a tag. It reads
# the current version from the latest tag, suggests the next one, writes it into
# both component manifests so they stay in lockstep, refreshes composer.lock's
# content hash, prepends the commit log to CHANGELOG.md for review, then commits,
# tags and (optionally) pushes.
#
# The repo has no single VERSION file: the git tag is the version, and the
# manifests below mirror it.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

CHANGELOG="CHANGELOG.md"
NOW="$(date +'%B %d, %Y')"
TAG_GLOB='v[0-9]*.[0-9]*.[0-9]*'

# Every file that declares the version. Each carries a single top-level "version"
# key, so replacing the first match is safe — dependency constraints are values
# (`"pkg": "^1.2"`), never a `"version":` key.
MANIFESTS=(
  app/package.json
  api/composer.json
)

die() { echo "error: $*" >&2; exit 1; }

read_json_version() { # $1 = file -> prints the first "version" value
  perl -0777 -ne 'print $1 if /"version"\s*:\s*"([^"]*)"/' "$1"
}

set_json_version() { # $1 = file, $2 = version -> rewrites the first "version" value in place
  perl -0777 -i -pe 'BEGIN { $v = shift } s/("version"\s*:\s*")[^"]*(")/${1}${v}${2}/' "$2" "$1"
}

# api/composer.json's "version" is part of composer.lock's content hash, so every
# bump makes the lock look stale ("not up to date with the latest changes"). Only
# that hash has to be rewritten, which is what `composer update --lock` does — no
# dependency is touched. It runs in the api container like every other command in
# this project, and a missing container aborts the release rather than commit a
# stale lock.
refresh_composer_lock() {
  local out
  docker compose ps --services --status running 2>/dev/null | grep -qx api \
    || die "the api container is not running — start it with 'docker compose up -d api',
       then re-run. The manifests are already bumped; undo with:
       git checkout -- ${MANIFESTS[*]}"
  out="$(docker compose exec -T api composer update --lock --no-scripts --no-interaction 2>&1)" \
    || { echo "$out" >&2; die "composer update --lock failed in the api container"; }
  echo "  set api/composer.lock -> content hash refreshed"
}

# The latest annotated vX.Y.Z tag is the current release. Before the very first
# tag there is none, so fall back to what the manifests already declare and offer
# that as the first tag rather than a bump.
LAST_TAG="$(git describe --tags --match "$TAG_GLOB" --abbrev=0 2>/dev/null || true)"

if [ -n "$LAST_TAG" ]; then
  CURRENT="${LAST_TAG#v}"
  IFS=. read -r MAJOR MINOR _PATCH <<<"$CURRENT"
  SUGGESTED="${MAJOR}.$((MINOR + 1)).0" # default to the next minor
  RANGE="${LAST_TAG}..HEAD"             # changelog: commits since the last tag
  echo "Current version: ${CURRENT}  (tag ${LAST_TAG})"
else
  CURRENT="$(read_json_version app/package.json)"
  [ -n "$CURRENT" ] || die "no ${TAG_GLOB} tag yet and app/package.json declares no version"
  SUGGESTED="$CURRENT"                  # first tag == the already-declared version
  RANGE=""                              # no prior tag -> log every commit
  echo "No release tag yet. Current declared version: ${CURRENT}"
fi

printf 'New version [%s]: ' "$SUGGESTED"
read -r INPUT
NEW="${INPUT:-$SUGGESTED}"

echo "$NEW" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([-+].*)?$' \
  || die "'$NEW' is not a semantic version (MAJOR.MINOR.PATCH)"
git rev-parse -q --verify "refs/tags/v${NEW}" >/dev/null \
  && die "tag v${NEW} already exists"

# Write the new version into every manifest so none drifts from the tag.
for f in "${MANIFESTS[@]}"; do
  [ -f "$f" ] || die "missing manifest: $f"
  set_json_version "$f" "$NEW"
  echo "  set $f -> $NEW"
done
refresh_composer_lock

# Prepend a new section to the changelog. `all commits` when this is the first
# tag, otherwise everything since the previous tag.
{
  echo "## ${NEW} (${NOW})"
  if [ -n "$RANGE" ]; then
    git log --pretty=format:'  - %s' "$RANGE"
  else
    git log --pretty=format:'  - %s'
  fi
  echo
  echo
  if [ -f "$CHANGELOG" ]; then
    cat "$CHANGELOG"
  fi
} >"${CHANGELOG}.tmp"
mv "${CHANGELOG}.tmp" "$CHANGELOG"

printf '\nReview and edit %s if needed, then press enter to commit and tag (Ctrl-C to abort)… ' "$CHANGELOG"
read -r _

git add "$CHANGELOG" "${MANIFESTS[@]}" api/composer.lock
git commit -m "Release ${NEW}"
git tag -a "v${NEW}" -m "Release ${NEW}"
echo "Committed and tagged v${NEW}."

printf 'Push commit and tag to origin now? [y/N]: '
read -r PUSH
case "$PUSH" in
  y | Y | yes | Yes | YES)
    git push origin HEAD
    git push origin "v${NEW}"
    echo "Pushed."
    ;;
  *)
    echo "Not pushed. Push manually with:  git push origin HEAD && git push origin v${NEW}"
    ;;
esac
