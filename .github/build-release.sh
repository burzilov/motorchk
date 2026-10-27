#!/usr/bin/env bash
# Сборка артефактов релиза ядра. Вызывается из .github/workflows/release.yml (после composer install).
# Запуск из корня репозитория ядра: bash .github/build-release.sh VERSION OUT_DIR
#
#   motorchk-core-X.Y.Z.zip    — только ядро: папка motorchk-core-X.Y.Z/ (= public_html/core/).
#                                Его скачивает self-update (Updater ищет ровно это имя) — формат не менять.
#   motorchk-install-X.Y.Z.zip — новая установка: распаковать в public_html/. Внутри core/, а также
#                                .htaccess и index.php — копии из core/skeleton/, чтобы /admin-panel/ (мастер)
#                                открывался сразу после распаковки, без ручных шагов.
set -euo pipefail

VERSION="${1:?VERSION}"
OUT="$(cd "${2:?OUT_DIR}" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

CORE_STAGE="motorchk-core-${VERSION}"
mkdir -p "$TMP/$CORE_STAGE"
# tests/ и scripts/ (исходники CSS) — только для разработки; plugins/ и plugin-data/ принадлежат сайту и в ядро не входят
rsync -a --exclude '.git' --exclude '/tests' --exclude '/scripts' --exclude '/plugins' --exclude '/plugin-data' ./ "$TMP/$CORE_STAGE/"
(cd "$TMP" && zip -qr "$OUT/${CORE_STAGE}.zip" "$CORE_STAGE")

INST="$TMP/install"
mkdir -p "$INST"
cp -a "$TMP/$CORE_STAGE" "$INST/core"
cp "$INST/core/skeleton/.htaccess" "$INST/.htaccess"
cp "$INST/core/skeleton/index.php" "$INST/index.php"
(cd "$INST" && zip -qr "$OUT/motorchk-install-${VERSION}.zip" .htaccess index.php core)

echo "Built ${OUT}/${CORE_STAGE}.zip and ${OUT}/motorchk-install-${VERSION}.zip"
