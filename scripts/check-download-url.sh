#!/usr/bin/env bash
# Pre-flight for the Pressable deploy. Run by pressable.yml before any site is
# touched, and by pr.yml so a problem shows up before a deploy does.
#
# scripts/pressable.ts must install from the old `inspry/checkview` URL, because
# the installer names the plugin folder after the repo in the URL (`checkview/`).
# That URL only works through GitHub's redirect to CheckView/helper. Fail if the
# script uses another URL, or the redirect no longer ends at this repo's quality
# zip with the plugin inside.
set -euo pipefail

url='https://github.com/inspry/checkview/archive/refs/heads/quality.zip'
want='https://codeload.github.com/CheckView/helper/zip/refs/heads/quality'
zip="${RUNNER_TEMP:-$(mktemp -d)}/quality.zip"

fail() {
  echo "::error::$1"
  exit 1
}

if [ "$(grep -c 'quality\.zip' scripts/pressable.ts)" != 1 ] || ! grep -qF "path: '$url'" scripts/pressable.ts; then
  fail "scripts/pressable.ts must install from $url, and only one line of it may mention quality.zip"
fi

command -v unzip >/dev/null || fail "unzip is not installed on the runner"

result=$(curl -sSL --retry 3 --retry-max-time 240 --proto '=https' --proto-redir '=https' --max-time 60 -o "$zip" -w '%{http_code} %{url_effective}' "$url") || fail "Could not download $url. Nothing was deployed. Re-run the failed job later."
echo "$url -> $result"
code=${result%% *}
final=${result#* }

case "$code" in
  200)
    [ "$final" = "$want" ] || fail "$url now ends at '$final', expected '$want'. If that is still CheckView/helper's quality zip at a new URL, update 'want' in scripts/check-download-url.sh. If it is another repo, do not deploy, and do not respell the URL in scripts/pressable.ts."
    ;;
  429 | 5??)
    fail "GitHub returned $code for $url (outage or rate limit). Nothing was deployed. Re-run the failed job later."
    ;;
  *)
    fail "$url returned $code at '$final'. A 404 means the inspry/checkview redirect is gone (a new repo with that name?) or CheckView/helper is private. Do not respell the URL in scripts/pressable.ts."
    ;;
esac

unzip -Z1 "$zip" | grep -E '^[^/]+/checkview\.php$' >/dev/null || fail "The download is not a zip or has no checkview.php"

# Git stores the commit id as the zip comment. Shown for the log only: a newer
# push to quality between checkout and download is harmless.
echo "zip commit: $(unzip -z "$zip" | tail -n 1), this run: ${GITHUB_SHA:-local}"
