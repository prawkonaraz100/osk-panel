#!/usr/bin/env bash
set -euo pipefail

# One-time branch cleanup for prawkonaraz100/osk-panel.
#
# This file intentionally lives on archive/branch-snapshot-2026-09-15.
# It must never be merged into main.
#
# Safe default:
#   bash .branch-cleanup-2026-09-15.sh
# performs verification and prints the deletion plan only.
#
# Execute:
#   EXECUTE=1 \
#   CONFIRM_BRANCH_CLEANUP=delete-verified-branches-b35f0cf38a588bb27faa4c1f682c8a87864f828b \
#   bash .branch-cleanup-2026-09-15.sh
#
# The script is restart-safe after a partial cleanup: already-missing historical
# refs are accepted, but any unexpected ref or any historical ref moved away
# from its snapshotted SHA aborts the run.

REPOSITORY="prawkonaraz100/osk-panel"
ARCHIVE_BRANCH="archive/branch-snapshot-2026-09-15"
ARCHIVE_ROOT="b35f0cf38a588bb27faa4c1f682c8a87864f828b"
EXPECTED_MAIN="bb6f4639fbf1098f131e579dedbb610e0e7c7d97"
MANIFEST_PATH=".branch-archive-2026-09-15.tsv"
EXPECTED_MANIFEST_BLOB="5dad0763dee852182e21807fd372b2e2d1cd2e86"
CONFIRM_TOKEN="delete-verified-branches-${ARCHIVE_ROOT}"

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

note() {
  printf '%s\n' "$*"
}

command -v git >/dev/null 2>&1 || die "git is required"

origin_url="$(git remote get-url origin 2>/dev/null || true)"
case "$origin_url" in
  "https://github.com/${REPOSITORY}.git"|"https://github.com/${REPOSITORY}"|"git@github.com:${REPOSITORY}.git")
    ;;
  *)
    die "origin points to unexpected repository: ${origin_url:-<missing>}"
    ;;
esac

git fetch --prune origin   "+refs/heads/main:refs/remotes/origin/main"   "+refs/heads/${ARCHIVE_BRANCH}:refs/remotes/origin/${ARCHIVE_BRANCH}"

actual_main="$(git rev-parse "refs/remotes/origin/main")"
[[ "$actual_main" == "$EXPECTED_MAIN" ]] ||   die "main moved: expected $EXPECTED_MAIN, got $actual_main"

git cat-file -e "${ARCHIVE_ROOT}^{commit}" || die "archive root is missing locally"
git merge-base --is-ancestor "$ARCHIVE_ROOT" "refs/remotes/origin/${ARCHIVE_BRANCH}" ||   die "archive branch no longer descends from archive root $ARCHIVE_ROOT"

manifest_blob="$(git rev-parse "${ARCHIVE_ROOT}:${MANIFEST_PATH}")"
[[ "$manifest_blob" == "$EXPECTED_MANIFEST_BLOB" ]] ||   die "manifest blob mismatch: expected $EXPECTED_MANIFEST_BLOB, got $manifest_blob"

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT
manifest="$tmpdir/manifest.tsv"
live="$tmpdir/live.tsv"
remaining="$tmpdir/remaining.tsv"
unexpected="$tmpdir/unexpected.tsv"
moved="$tmpdir/moved.tsv"

git show "${ARCHIVE_ROOT}:${MANIFEST_PATH}" > "$manifest"

awk -F '\t' '
  BEGIN { OFS="\t" }
  /^#/ { next }
  $1 == "branch" { next }
  NF >= 2 { print $1, $2 }
' "$manifest" | sort -k1,1 > "$tmpdir/expected.tsv"

expected_count="$(wc -l < "$tmpdir/expected.tsv" | tr -d ' ')"
[[ "$expected_count" == "288" ]] || die "expected 288 manifest refs, got $expected_count"

expected_unique_tips="$(cut -f2 "$tmpdir/expected.tsv" | sort -u | wc -l | tr -d ' ')"
[[ "$expected_unique_tips" == "224" ]] ||   die "expected 224 unique manifest tips (main + 223 historical), got $expected_unique_tips"

git ls-remote --heads origin |
  awk '{ sha=$1; ref=$2; sub("^refs/heads/", "", ref); print ref "\t" sha }' |
  sort -k1,1 > "$live"

archive_live_sha="$(awk -F '\t' -v b="$ARCHIVE_BRANCH" '$1 == b { print $2 }' "$live")"
[[ -n "$archive_live_sha" ]] || die "archive branch is missing from origin"

main_live_sha="$(awk -F '\t' '$1 == "main" { print $2 }' "$live")"
[[ "$main_live_sha" == "$EXPECTED_MAIN" ]] ||   die "remote main mismatch: expected $EXPECTED_MAIN, got ${main_live_sha:-<missing>}"

awk -F '\t' -v archive="$ARCHIVE_BRANCH" '
  NR==FNR { expected[$1]=$2; next }
  $1 == archive { next }
  !($1 in expected) { print $0 }
' "$tmpdir/expected.tsv" "$live" > "$unexpected"

[[ ! -s "$unexpected" ]] || {
  cat "$unexpected" >&2
  die "unexpected live branches exist; snapshot no longer describes the repository"
}

awk -F '\t' -v archive="$ARCHIVE_BRANCH" '
  NR==FNR { live[$1]=$2; next }
  $1 == "main" { next }
  $1 == archive { next }
  ($1 in live) && live[$1] != $2 { print $1 "\t" $2 "\t" live[$1] }
' "$live" "$tmpdir/expected.tsv" > "$moved"

[[ ! -s "$moved" ]] || {
  cat "$moved" >&2
  die "one or more historical branches moved after the snapshot"
}

awk -F '\t' '
  NR==FNR { live[$1]=$2; next }
  $1 == "main" { next }
  ($1 in live) && live[$1] == $2 { print $1 "\t" $2 }
' "$live" "$tmpdir/expected.tsv" > "$remaining"

remaining_count="$(wc -l < "$remaining" | tr -d ' ')"
live_count="$(wc -l < "$live" | tr -d ' ')"

note "SNAPSHOT_VERIFY=PASS"
note "archive_root=$ARCHIVE_ROOT"
note "archive_live=$archive_live_sha"
note "main=$main_live_sha"
note "manifest_refs=$expected_count"
note "manifest_unique_tips=$expected_unique_tips"
note "live_refs=$live_count"
note "remaining_delete_refs=$remaining_count"

if [[ "$remaining_count" == "0" ]]; then
  final_count="$(wc -l < "$live" | tr -d ' ')"
  [[ "$final_count" == "2" ]] || die "nothing remains to delete but live branch count is $final_count, expected 2"
  note "BRANCH_CLEANUP=ALREADY_COMPLETE"
  exit 0
fi

note ""
note "Deletion plan (branch -> snapshotted SHA):"
cat "$remaining"

if [[ "${EXECUTE:-0}" != "1" ]]; then
  note ""
  note "DRY_RUN=PASS"
  note "No refs were deleted."
  note "To execute, set EXECUTE=1 and CONFIRM_BRANCH_CLEANUP=$CONFIRM_TOKEN"
  exit 0
fi

[[ "${CONFIRM_BRANCH_CLEANUP:-}" == "$CONFIRM_TOKEN" ]] ||   die "confirmation token mismatch"

note ""
note "EXECUTION=START"

while IFS=$'\t' read -r branch sha; do
  [[ -n "$branch" && -n "$sha" ]] || continue
  [[ "$branch" != "main" ]] || die "refusing to delete main"
  [[ "$branch" != "$ARCHIVE_BRANCH" ]] || die "refusing to delete archive branch"

  git push     --force-with-lease="refs/heads/${branch}:${sha}"     origin     ":refs/heads/${branch}"
done < "$remaining"

git ls-remote --heads origin |
  awk '{ sha=$1; ref=$2; sub("^refs/heads/", "", ref); print ref "\t" sha }' |
  sort -k1,1 > "$tmpdir/final.tsv"

final_count="$(wc -l < "$tmpdir/final.tsv" | tr -d ' ')"
[[ "$final_count" == "2" ]] || {
  cat "$tmpdir/final.tsv" >&2
  die "post-cleanup branch count is $final_count, expected 2"
}

grep -Fqx $'main\t'"$EXPECTED_MAIN" "$tmpdir/final.tsv" ||   die "post-cleanup main ref is missing or changed"

final_archive_sha="$(awk -F '\t' -v b="$ARCHIVE_BRANCH" '$1 == b { print $2 }' "$tmpdir/final.tsv")"
[[ -n "$final_archive_sha" ]] || die "post-cleanup archive ref is missing"

git fetch origin "+refs/heads/${ARCHIVE_BRANCH}:refs/remotes/origin/${ARCHIVE_BRANCH}"
git merge-base --is-ancestor "$ARCHIVE_ROOT" "refs/remotes/origin/${ARCHIVE_BRANCH}" ||   die "post-cleanup archive branch lost archive root ancestry"

note "BRANCH_CLEANUP=PASS"
note "final_branch_count=2"
note "main=$EXPECTED_MAIN"
note "archive=$final_archive_sha"
