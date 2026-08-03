#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

if [[ ! -f VERSION ]]; then
  echo "VERSION file not found" >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is not clean. Commit or stash changes first." >&2
  git status --short >&2
  exit 1
fi

if ! git rev-parse --abbrev-ref --symbolic-full-name '@{u}' >/dev/null 2>&1; then
  echo "Current branch has no upstream remote. Set upstream before releasing." >&2
  exit 1
fi

CURRENT="$(tr -d '[:space:]' < VERSION)"
if [[ ! "$CURRENT" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  echo "VERSION must be MAJOR.MINOR.PATCH, got: $CURRENT" >&2
  exit 1
fi

MAJOR="${BASH_REMATCH[1]}"
MINOR="${BASH_REMATCH[2]}"
PATCH="${BASH_REMATCH[3]}"
NEW_PATCH=$((PATCH + 1))
NEW_VERSION="${MAJOR}.${MINOR}.${NEW_PATCH}"
TAG="v${NEW_VERSION}"
MESSAGE="Release ${TAG}"

echo "$NEW_VERSION" > VERSION
git add VERSION
git commit -m "$MESSAGE"
git tag -a "$TAG" -m "$MESSAGE"
git push origin HEAD
git push origin "$TAG"

echo "Released ${TAG}"
