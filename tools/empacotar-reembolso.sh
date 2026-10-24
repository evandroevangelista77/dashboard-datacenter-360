#!/usr/bin/env bash
# Gera o pacote da release do plugin Reembolso: oak-reembolso-completo-<versão>.zip
# (reembolso/ + instalar.sh + LEIA-ME.txt + LICENSE), com a versão lida do setup.php.
#
# Uso: bash tools/empacotar-reembolso.sh [pasta de saída]   (padrão: pasta atual)
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="$(cd "${1:-.}" && pwd)"
VERSAO="$(grep -oE "PLUGIN_REEMBOLSO_VERSION', '[^']+" "$RAIZ/glpi-plugin/reembolso/setup.php" | cut -d"'" -f3)"
[ -n "$VERSAO" ] || { echo "versão não encontrada no setup.php"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/oak-reembolso-completo"
mkdir -p "$PKG"
rsync -a --exclude '.DS_Store' "$RAIZ/glpi-plugin/reembolso/" "$PKG/reembolso/"
cp "$RAIZ/tools/reembolso-pacote/instalar.sh" "$PKG/instalar.sh"
cp "$RAIZ/LICENSE" "$PKG/LICENSE"
sed "s/@VERSAO@/$VERSAO/g" "$RAIZ/tools/reembolso-pacote/LEIA-ME.txt" > "$PKG/LEIA-ME.txt"
chmod 755 "$PKG/instalar.sh"

ZIP="$SAIDA/oak-reembolso-completo-$VERSAO.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -qr -X "$ZIP" oak-reembolso-completo)
echo "$ZIP"
