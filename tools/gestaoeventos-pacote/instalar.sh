#!/usr/bin/env bash
# Instala (ou atualiza) o plugin "Gestão de Eventos" no GLPI 11 ou 12.
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
[ -f "$AQUI/gestaoeventos/setup.php" ] || erro "Rode o script de dentro da pasta descompactada (falta gestaoeventos/)"

# usuário do servidor web (dono dos arquivos do GLPI)
WEB_USER="$(stat -c %U "$GLPI_DIR/files" 2>/dev/null || stat -c %U "$GLPI_DIR")"
[ "$WEB_USER" = "root" ] && WEB_USER="$(id -un www-data 2>/dev/null || id -un apache 2>/dev/null || echo www-data)"
console() { sudo -u "$WEB_USER" php "$GLPI_DIR/bin/console" "$@"; }
ok "GLPI em $GLPI_DIR · usuário web: $WEB_USER"

# versão do GLPI (o plugin é para 11.x e 12.0.x)
VER="$(console --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
case "$VER" in
  11.*|12.0.*) ok "GLPI $VER" ;;
  "")          aviso "Não consegui ler a versão do GLPI; seguindo assim mesmo" ;;
  *)           erro "GLPI $VER: este plugin é para GLPI 11 e 12.0.x" ;;
esac

# plugin (atualização: troca os arquivos; os dados ficam no banco)
JA_HAVIA=0
[ -d "$GLPI_DIR/plugins/gestaoeventos" ] && JA_HAVIA=1
rm -rf "$GLPI_DIR/plugins/gestaoeventos"
cp -r "$AQUI/gestaoeventos" "$GLPI_DIR/plugins/gestaoeventos"
chown -R "$WEB_USER:$WEB_USER" "$GLPI_DIR/plugins/gestaoeventos"
find "$GLPI_DIR/plugins/gestaoeventos" -type d -exec chmod 755 {} + && find "$GLPI_DIR/plugins/gestaoeventos" -type f -exec chmod 644 {} +
[ "$JA_HAVIA" = 1 ] && ok "Arquivos do plugin atualizados em $GLPI_DIR/plugins/gestaoeventos" || ok "Plugin copiado para $GLPI_DIR/plugins/gestaoeventos"

console plugin:install --force --username="$GLPI_ADMIN" gestaoeventos || aviso "plugin:install avisou algo acima (normal se já estava instalado)"
console plugin:activate gestaoeventos || aviso "plugin:activate avisou algo acima (normal se já estava ativo)"
console cache:clear >/dev/null 2>&1 || true
VERSAO_PACOTE="$(grep -oE "PLUGIN_GESTAOEVENTOS_VERSION', '[^']+" "$AQUI/gestaoeventos/setup.php" | cut -d"'" -f3)"
LINHA="$(console plugin:list 2>/dev/null | grep -E "^\| gestaoeventos " || true)"
if echo "$LINHA" | grep -q "$VERSAO_PACOTE" && echo "$LINHA" | grep -qi -E "habilitado|enabled"; then
  ok "Plugin Gestão de Eventos $VERSAO_PACOTE instalado e ativo"
else
  erro "O plugin não ficou ativo: ${LINHA:-não aparece na lista}. Veja a mensagem acima ou ative em Configurar > Plugins."
fi

LINHA_A="$(console plugin:list 2>/dev/null | grep -E "^\| avisos " || true)"
if echo "$LINHA_A" | grep -qi -E "habilitado|enabled"; then ok "Plugin Avisos ativo: as etapas avisam no sino, no pop-up e no canal do Teams"; else aviso "Plugin Avisos não está ativo (opcional): sem ele, as áreas recebem só o chamado de cada etapa"; fi

echo
ok "Pronto. No GLPI: menu lateral → Gestão de Eventos (Eventos, Painel, Aprovações, Relatórios, Documentos, Etapas e SLOs, Alçadas e regras)."
echo "  Quem já estava logado precisa sair e entrar de novo para ver o menu."
echo "  Depois: crie os grupos das áreas (Marketing, Diretoria, PMO, Compras, Logística, Financeiro), escolha o grupo de cada etapa em Etapas e SLOs"
echo "  e o grupo de cada aprovador em Alçadas e regras. Perfis: Administração > Perfis > aba Gestão de Eventos."
