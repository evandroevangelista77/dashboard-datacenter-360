#!/usr/bin/env bash
# Gera o pacote da release do plugin Sistemas Internos 360: oak-sistemasinternos-completo-<versão>.zip
# (sistemasinternos/ + instalar.sh + LEIA-ME.txt + LICENSE), com a versão lida do setup.php.
#
# Uso: bash tools/empacotar-sistemasinternos.sh [pasta de saída]   (padrão: pasta atual)
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="$(cd "${1:-.}" && pwd)"
VERSAO="$(grep -oE "PLUGIN_SISTEMASINTERNOS_VERSION', '[^']+" "$RAIZ/glpi-plugin/sistemasinternos/setup.php" | cut -d"'" -f3)"
[ -n "$VERSAO" ] || { echo "versão não encontrada no setup.php"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/oak-sistemasinternos-completo"
mkdir -p "$PKG"
rsync -a --exclude '.DS_Store' "$RAIZ/glpi-plugin/sistemasinternos/" "$PKG/sistemasinternos/"
cp "$RAIZ/tools/sistemasinternos-pacote/instalar.sh" "$PKG/instalar.sh"
cp "$RAIZ/LICENSE" "$PKG/LICENSE"
sed "s/@VERSAO@/$VERSAO/g" "$RAIZ/tools/sistemasinternos-pacote/LEIA-ME.txt" > "$PKG/LEIA-ME.txt"
chmod 755 "$PKG/instalar.sh"

ZIP="$SAIDA/oak-sistemasinternos-completo-$VERSAO.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -qr -X "$ZIP" oak-sistemasinternos-completo)
echo "$ZIP"
