#!/usr/bin/env bash
# Gera o pacote da release do plugin Ciclo Financeiro: oak-ciclo-completo-<versão>.zip
# (ciclo/ + instalar.sh + LEIA-ME.txt + LICENSE), com a versão lida do setup.php.
#
# Uso: bash tools/empacotar-ciclo.sh [pasta de saída]   (padrão: pasta atual)
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="$(cd "${1:-.}" && pwd)"
VERSAO="$(grep -oE "PLUGIN_CICLO_VERSION', '[^']+" "$RAIZ/glpi-plugin/ciclo/setup.php" | cut -d"'" -f3)"
[ -n "$VERSAO" ] || { echo "versão não encontrada no setup.php"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/oak-ciclo-completo"
mkdir -p "$PKG"
rsync -a --exclude '.DS_Store' "$RAIZ/glpi-plugin/ciclo/" "$PKG/ciclo/"
cp "$RAIZ/tools/ciclo-pacote/instalar.sh" "$PKG/instalar.sh"
cp "$RAIZ/LICENSE" "$PKG/LICENSE"
sed "s/@VERSAO@/$VERSAO/g" "$RAIZ/tools/ciclo-pacote/LEIA-ME.txt" > "$PKG/LEIA-ME.txt"
chmod 755 "$PKG/instalar.sh"

ZIP="$SAIDA/oak-ciclo-completo-$VERSAO.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -qr -X "$ZIP" oak-ciclo-completo)
echo "$ZIP"
