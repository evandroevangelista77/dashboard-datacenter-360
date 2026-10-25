#!/usr/bin/env bash
# Instala (ou atualiza) o plugin "Avisos" (sino, pop-up e Teams) no GLPI 12.
#
# Uso (no servidor do GLPI, dentro da pasta descompactada):
#   sudo bash instalar.sh                      # GLPI em /var/www/glpi, admin "glpi"
#   sudo GLPI_DIR=/caminho/glpi GLPI_ADMIN=usuario bash instalar.sh
set -euo pipefail

GLPI_DIR="${GLPI_DIR:-/var/www/glpi}"
GLPI_ADMIN="${GLPI_ADMIN:-glpi}"
AQUI="$(cd "$(dirname "$0")" && pwd)"
ok()   { echo -e "\033[32m✔\033[0m $*"; }
aviso(){ echo -e "\033[33m!\033[0m $*"; }
erro() { echo -e "\033[31m✘ $*\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || erro "Rode como root: sudo bash instalar.sh"
[ -f "$GLPI_DIR/bin/console" ] || erro "GLPI não encontrado em $GLPI_DIR (use GLPI_DIR=/caminho/do/glpi)"
[ -f "$AQUI/avisos/setup.php" ] || erro "Rode o script de dentro da pasta descompactada (falta avisos/)"

# usuário do servidor web (dono dos arquivos do GLPI)
WEB_USER="$(stat -c %U "$GLPI_DIR/files" 2>/dev/null || stat -c %U "$GLPI_DIR")"
[ "$WEB_USER" = "root" ] && WEB_USER="$(id -un www-data 2>/dev/null || id -un apache 2>/dev/null || echo www-data)"
console() { sudo -u "$WEB_USER" php "$GLPI_DIR/bin/console" "$@"; }
ok "GLPI em $GLPI_DIR · usuário web: $WEB_USER"

# versão do GLPI (o plugin é para 12.0.x)
VER="$(console --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
case "$VER" in
  12.0.*) ok "GLPI $VER" ;;
  "")     aviso "Não consegui ler a versão do GLPI; seguindo assim mesmo" ;;
  *)      erro "GLPI $VER: este plugin é para GLPI 12.0.x" ;;
esac

# plugin (atualização: troca os arquivos; os dados ficam no banco)
JA_HAVIA=0
[ -d "$GLPI_DIR/plugins/avisos" ] && JA_HAVIA=1
rm -rf "$GLPI_DIR/plugins/avisos"
cp -r "$AQUI/avisos" "$GLPI_DIR/plugins/avisos"
chown -R "$WEB_USER:$WEB_USER" "$GLPI_DIR/plugins/avisos"
find "$GLPI_DIR/plugins/avisos" -type d -exec chmod 755 {} + && find "$GLPI_DIR/plugins/avisos" -type f -exec chmod 644 {} +
[ "$JA_HAVIA" = 1 ] && ok "Arquivos do plugin atualizados em $GLPI_DIR/plugins/avisos" || ok "Plugin copiado para $GLPI_DIR/plugins/avisos"

# já instalado (atualização): o install avisa e seguimos
console plugin:install --username="$GLPI_ADMIN" avisos || aviso "plugin:install avisou algo acima (normal se já estava instalado)"
console plugin:activate avisos || aviso "plugin:activate avisou algo acima (normal se já estava ativo)"
console cache:clear >/dev/null 2>&1 || true
# confere de verdade: o plugin precisa estar ativo e na versão do pacote
VERSAO_PACOTE="$(grep -oE "PLUGIN_AVISOS_VERSION', '[^']+" "$AQUI/avisos/setup.php" | cut -d"'" -f3)"
LINHA="$(console plugin:list 2>/dev/null | grep -E "^\| avisos " || true)"
if echo "$LINHA" | grep -q "$VERSAO_PACOTE" && echo "$LINHA" | grep -qi -E "habilitado|enabled"; then
  ok "Plugin Avisos $VERSAO_PACOTE instalado e ativo"
else
  erro "O plugin não ficou ativo: ${LINHA:-não aparece na lista}. Veja a mensagem acima ou ative em Configurar > Plugins."
fi

# plugins que mandam avisos para cá
for p in rh pmo reembolso; do
  LINHA_P="$(console plugin:list 2>/dev/null | grep -E "^\| $p " || true)"
  if echo "$LINHA_P" | grep -qi -E "habilitado|enabled"; then ok "Plugin $p ativo: os avisos dele passam a aparecer no sino, no pop-up e no Teams"; else aviso "Plugin $p não está ativo (opcional)"; fi
done
# acesso ao Teams
if curl -s -m 8 -o /dev/null https://login.microsoftonline.com; then ok "Servidor acessa a internet: envio para o Teams disponível"; else aviso "Sem acesso à internet: o envio para o Teams não vai funcionar (configure o proxy do GLPI, se houver)"; fi

echo
ok "Pronto. No GLPI: o sino aparece no topo da tela (recarregue a página)."
echo "  Teams: Configurar > Plugins > Avisos, cole a URL do webhook de cada canal (RH, PMO, Financeiro) e clique em Testar."
