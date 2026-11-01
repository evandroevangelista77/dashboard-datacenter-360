#!/usr/bin/env bash
# Gera o pacote da release do plugin CSAT / e-NPS: oak-satisfacao-completo-<versão>.zip
# (satisfacao/ + instalar.sh + LEIA-ME.txt + LICENSE), com a versão lida do setup.php.
#
# Uso: bash tools/empacotar-satisfacao.sh [pasta de saída]   (padrão: pasta atual)
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="$(cd "${1:-.}" && pwd)"
VERSAO="$(grep -oE "PLUGIN_SATISFACAO_VERSION', '[^']+" "$RAIZ/glpi-plugin/satisfacao/setup.php" | cut -d"'" -f3)"
[ -n "$VERSAO" ] || { echo "versão não encontrada no setup.php"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/oak-satisfacao-completo"
mkdir -p "$PKG"
# sem lixo de sistema (macOS: .DS_Store e arquivos ._*)
rsync -a --exclude '.DS_Store' --exclude '._*' "$RAIZ/glpi-plugin/satisfacao/" "$PKG/satisfacao/"
cp "$RAIZ/tools/satisfacao-pacote/instalar.sh" "$PKG/instalar.sh"
cp "$RAIZ/LICENSE" "$PKG/LICENSE"
sed "s/@VERSAO@/$VERSAO/g" "$RAIZ/tools/satisfacao-pacote/LEIA-ME.txt" > "$PKG/LEIA-ME.txt"
chmod 755 "$PKG/instalar.sh"

ZIP="$SAIDA/oak-satisfacao-completo-$VERSAO.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -qr -X "$ZIP" oak-satisfacao-completo)
echo "$ZIP"
