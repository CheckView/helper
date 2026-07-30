#!/usr/bin/env bash
#
# Downloads the third-party sources the verification cases assert against.
#
# These are NOT committed: they are large, they are not ours, and pinning a copy
# in the repo would defeat the point. Several cases exist specifically to notice
# when one of these plugins changes a hook name, a priority or a default — so
# they must read whatever version is actually current.
#
# Usage:  tests/harness/fetch-fixtures.sh [--wordpress]
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FIXTURES="${HERE}/fixtures"
PLUGIN_ROOT="$(cd "${HERE}/../.." && pwd)"

mkdir -p "${FIXTURES}"

# Slugs as published on wordpress.org. Forminator is the free build; Pro is out
# of scope. Gravity Forms is intentionally absent — it is commercial and not
# downloadable here, so the GF cases assert against WordPress core's shortcode
# parser and the plugin's own source instead.
PLUGINS=(
  contact-form-7
  formidable
  forminator
  honeypot                 # WP Armour
  cleantalk-spam-protect
)

for slug in "${PLUGINS[@]}"; do
  if [ -d "${FIXTURES}/${slug}" ]; then
    echo "  ${slug} — already present, skipping"
    continue
  fi
  echo "  ${slug} — downloading"
  curl -fsSL "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" -o "${FIXTURES}/${slug}.zip"
  unzip -q -o "${FIXTURES}/${slug}.zip" -d "${FIXTURES}"
  rm -f "${FIXTURES}/${slug}.zip"
done

# A couple of cases assert against WordPress core itself — that shortcode
# parsing accepts single quotes, and that `shutdown` still fires after the die()
# ending an admin-ajax request. Opt-in because it is a large download and most
# cases do not need it.
if [ "${1:-}" = "--wordpress" ]; then
  if [ -d "${PLUGIN_ROOT}/wordpress" ]; then
    echo "  wordpress — already present, skipping"
  else
    echo "  wordpress — downloading"
    curl -fsSL "https://wordpress.org/latest.zip" -o "${FIXTURES}/wordpress.zip"
    unzip -q -o "${FIXTURES}/wordpress.zip" -d "${PLUGIN_ROOT}"
    rm -f "${FIXTURES}/wordpress.zip"
  fi
fi

echo
echo "Fixtures ready in ${FIXTURES}"
echo "Run: php tests/harness/run.php"
