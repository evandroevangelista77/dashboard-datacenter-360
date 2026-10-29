#!/usr/bin/env bash
# Instala (ou atualiza) o plugin "Sistemas Internos 360" no GLPI 12.
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
[ -f "$AQUI/sistemasinternos/setup.php" ] || erro "Rode o script de dentro da pasta descompactada (falta sistemasinternos/)"

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
[ -d "$GLPI_DIR/plugins/sistemasinternos" ] && JA_HAVIA=1
rm -rf "$GLPI_DIR/plugins/sistemasinternos"
cp -r "$AQUI/sistemasinternos" "$GLPI_DIR/plugins/sistemasinternos"
chown -R "$WEB_USER:$WEB_USER" "$GLPI_DIR/plugins/sistemasinternos"
find "$GLPI_DIR/plugins/sistemasinternos" -type d -exec chmod 755 {} + && find "$GLPI_DIR/plugins/sistemasinternos" -type f -exec chmod 644 {} +
[ "$JA_HAVIA" = 1 ] && ok "Arquivos do plugin atualizados em $GLPI_DIR/plugins/sistemasinternos" || ok "Plugin copiado para $GLPI_DIR/plugins/sistemasinternos"

# já instalado (atualização): o install avisa e seguimos
console plugin:install --username="$GLPI_ADMIN" sistemasinternos || aviso "plugin:install avisou algo acima (normal se já estava instalado)"
console plugin:activate sistemasinternos || aviso "plugin:activate avisou algo acima (normal se já estava ativo)"
console cache:clear >/dev/null 2>&1 || true
# confere de verdade: o plugin precisa estar ativo e na versão do pacote
VERSAO_PACOTE="$(grep -oE "PLUGIN_SISTEMASINTERNOS_VERSION', '[^']+" "$AQUI/sistemasinternos/setup.php" | cut -d"'" -f3)"
LINHA="$(console plugin:list 2>/dev/null | grep -E "^\| sistemasinternos " || true)"
if echo "$LINHA" | grep -q "$VERSAO_PACOTE" && echo "$LINHA" | grep -qi -E "habilitado|enabled"; then
  ok "Plugin Sistemas Internos 360 $VERSAO_PACOTE instalado e ativo"
else
  erro "O plugin não ficou ativo: ${LINHA:-não aparece na lista}. Veja a mensagem acima ou ative em Configurar > Plugins."
fi

# extensão curl (API do Zabbix)
php -m | grep -qi "^curl$" && ok "Extensão PHP curl presente (API do Zabbix)" || erro "Falta a extensão PHP curl (ex.: apt install php-curl)"

echo
ok "Pronto. No GLPI: menu lateral → Sistemas Internos → Configurações."
echo "  1) Cole o endereço da API e o token da conta de serviço do Zabbix e clique em Testar a conexão."
echo "  2) Escolha os grupos do Zabbix e a entidade do departamento; ajuste os alertas."
echo "  Quem já estava logado precisa sair e entrar de novo para ver o menu."
