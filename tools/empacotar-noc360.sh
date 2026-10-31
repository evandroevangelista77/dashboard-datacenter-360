#!/usr/bin/env bash
# Gera o pacote da release do NOC 360: oak-noc360-completo-<versão>.zip
# (painel/ + plugin datacenter360/ + instalar.sh + aquecer.sh + LEIA-ME.txt + LICENSE), versão lida do setup.php.
#
# Uso: bash tools/empacotar-noc360.sh [pasta de saída]   (padrão: pasta atual)
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="$(cd "${1:-.}" && pwd)"
VERSAO="$(grep -oE "PLUGIN_DATACENTER360_VERSION', '[^']+" "$RAIZ/glpi-plugin/datacenter360/setup.php" | cut -d"'" -f3)"
[ -n "$VERSAO" ] || { echo "versão não encontrada no setup.php"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/oak-noc360-completo"
mkdir -p "$PKG/painel"
for f in api.php index.html app.css app.js logo-zabbix.svg logo-glpi.svg bg-datacenter.svg topologia.html topologia-classica.html topologia-embed.html topologia.css topologia.js mapa.css mapa.js ticker.js logos.js instalar.sh aquecer.sh; do
  cp "$RAIZ/$f" "$PKG/painel/$f"
done
rsync -a --exclude '.DS_Store' "$RAIZ/glpi-plugin/datacenter360/" "$PKG/datacenter360/"
cp "$RAIZ/LICENSE" "$PKG/LICENSE"
cp "$RAIZ/README.md" "$PKG/README.md"
sed "s/@VERSAO@/$VERSAO/g" "$RAIZ/tools/noc360-pacote/LEIA-ME.txt" > "$PKG/LEIA-ME.txt"
chmod 755 "$PKG/painel/instalar.sh" "$PKG/painel/aquecer.sh"

ZIP="$SAIDA/oak-noc360-completo-$VERSAO.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -qr -X "$ZIP" oak-noc360-completo)
echo "$ZIP"
