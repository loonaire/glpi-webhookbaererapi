#!/usr/bin/env bash
#
# Regenerates locales/webhookbearerapi.pot from the sources, merges it into the .po files and
# compiles the .mo files. Requires GNU gettext (xgettext, msgcat, msgmerge, msgen, msgfmt).
#
# Usage: tools/update_locales.sh
# Then translate the new entries of locales/fr_FR.po and run the script again.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(sed -n "s/^define('PLUGIN_WEBHOOKBEARERAPI_VERSION', '\(.*\)');/\1/p" setup.php)

# Only the calls passing the plugin domain are extracted: the `t` suffix of the keywords sets the
# expected number of arguments, so `__('Name')` (GLPI core string) is ignored while
# `__('Token URL', 'webhookbearerapi')` is extracted.
KEYWORDS=(--keyword --keyword=__:1,2t --keyword=__s:1,2t --keyword=_n:1,2,4t --keyword=_x:1c,2,3t)
OPTIONS=(--from-code=UTF-8 --no-location --no-wrap "${KEYWORDS[@]}")

tmp=$(mktemp -d)
trap 'rm -rf "${tmp}"' EXIT

xgettext "${OPTIONS[@]}" --language=PHP -o "${tmp}/php.pot" \
    --package-name=webhookbearerapi --package-version="${VERSION}" \
    --msgid-bugs-address=https://github.com/loonaire/glpi-webhookbearerapi/issues \
    setup.php hook.php src/*.php front/*.php
# The Python lexer understands the `__('...', 'webhookbearerapi')` calls of the Twig templates.
xgettext "${OPTIONS[@]}" --language=Python -o "${tmp}/twig.pot" templates/*.twig

msgcat --use-first --sort-output --no-location --no-wrap -o locales/webhookbearerapi.pot \
    "${tmp}/php.pot" "${tmp}/twig.pot"
sed -i 's/charset=CHARSET/charset=UTF-8/' locales/webhookbearerapi.pot

for po in locales/*.po; do
    msgmerge --quiet --update --backup=none --no-fuzzy-matching --no-location --sort-output --no-wrap \
        "${po}" locales/webhookbearerapi.pot
    # Drop the strings that are no longer used by the sources.
    msgattrib --no-obsolete --no-wrap -o "${po}" "${po}"
done

# English catalogs: the translation is the source string.
for po in locales/en_*.po; do
    msgen --no-wrap -o "${po}" "${po}"
done

for po in locales/*.po; do
    printf '%s: ' "${po}"
    msgfmt --check --statistics -o "${po%.po}.mo" "${po}"
done
