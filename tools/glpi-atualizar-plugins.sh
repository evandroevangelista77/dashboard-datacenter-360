#!/usr/bin/env bash
#
# Atualiza os plugins do GLPI direto do GitHub, sem depender de nenhum Mac.
# Rode no servidor do GLPI:
#
#   sudo bash glpi-atualizar-plugins.sh              # todos os plugins
#   sudo bash glpi-atualizar-plugins.sh crm vmo      # só estes (pela chave do plugin)
#   sudo bash glpi-atualizar-plugins.sh --lista      # mostra o mapa plugin -> repositório
#
# Todos os repositórios dos plugins são públicos: não precisa de token nem de login.
# (Se um dia algum voltar a ser privado, crie /etc/glpi-plugins.env com a linha GH_TOKEN=<token de leitura>.)
#
# O que faz, para cada plugin: baixa a versão mais nova do GitHub, copia para <GLPI>/plugins/<chave>,
# ajusta o dono para o servidor web e, se a versão do plugin mudou, roda plugin:install e plugin:activate.
# No fim, limpa o cache do GLPI.

set -euo pipefail

GLPI="${GLPI:-/var/www/glpi}"
SRC="${SRC:-/opt/glpi-plugins-src}"
OWNER="${OWNER:-evandroevangelista77}"
WEB_USER="${WEB_USER:-www-data}"
GLPI_USER="${GLPI_USER:-glpi}"
ENV_FILE="${ENV_FILE:-/etc/glpi-plugins.env}"

# chave-do-plugin | repositório | subpasta dentro do repositório ("." = raiz)
MAPA=(
  "crm|crm-glpi|."
  "vmo|vmo-glpi|."
  "ciclo|ciclo-glpi|."
  "gestaoeventos|gestao-de-eventos|."
  "indicadores|gestao-de-indicadores|indicadores"
  "operacional360|operacional360|."
  "datacenter360|dashboard-datacenter-360|glpi-plugin/datacenter360"
  "pmo|dashboard-datacenter-360|glpi-plugin/pmo"
  "rh|dashboard-datacenter-360|glpi-plugin/rh"
  "reembolso|dashboard-datacenter-360|glpi-plugin/reembolso"
  "sistemasinternos|dashboard-datacenter-360|glpi-plugin/sistemasinternos"
  "controleequipamentos|dashboard-datacenter-360|glpi-plugin/controleequipamentos"
  "satisfacao|dashboard-datacenter-360|glpi-plugin/satisfacao"
  "avisos|dashboard-datacenter-360|glpi-plugin/avisos"
  "assinatura|dashboard-datacenter-360|glpi-plugin/assinatura"
  "cronograma|dashboard-datacenter-360|glpi-plugin/cronograma"
)

if [ "${1:-}" = "--lista" ]; then
  printf '%-22s %-28s %s\n' PLUGIN REPOSITORIO SUBPASTA
  for l in "${MAPA[@]}"; do IFS='|' read -r k r s <<<"$l"; printf '%-22s %-28s %s\n' "$k" "$r" "$s"; done
  exit 0
fi

[ "$(id -u)" -eq 0 ] || { echo "Rode com sudo." >&2; exit 1; }
[ -f "$GLPI/bin/console" ] || { echo "GLPI não encontrado em $GLPI (use GLPI=/caminho)." >&2; exit 1; }
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

mkdir -p "$SRC"

# git sempre em HTTP/1.1 (evita quedas de HTTP/2 em redes instáveis); o token, se existir, vai só na linha de comando
git_auth() {
  if [ -n "${GH_TOKEN:-}" ]; then
    local b64
    b64=$(printf 'x-access-token:%s' "$GH_TOKEN" | base64 -w0)
    git -c http.version=HTTP/1.1 -c "http.https://github.com/.extraheader=AUTHORIZATION: basic $b64" "$@"
  else
    git -c http.version=HTTP/1.1 "$@"
  fi
}

versao() { # lê a versão do setup.php de um plugin
  grep -hoE "define\('PLUGIN_[A-Z0-9_]+_VERSION', *'[^']+'" "$1/setup.php" 2>/dev/null | head -1 | sed -E "s/.*, *'([^']+)'.*/\1/" || true
}

declare -A PRONTO=()
baixar() { # baixa/atualiza o repositório (uma vez por execução)
  local repo="$1"
  [ -n "${PRONTO[$repo]:-}" ] && return "${PRONTO[$repo]}"
  local dir="$SRC/$repo" url="https://github.com/$OWNER/$repo.git" rc=1 tentativa
  for tentativa in 1 2 3; do
    if [ -d "$dir/.git" ]; then
      if git_auth -C "$dir" fetch -q origin main && git -C "$dir" reset -q --hard origin/main; then rc=0; break; fi
    else
      rm -rf "$dir"
      if git_auth clone -q --depth 50 "$url" "$dir"; then rc=0; break; fi
    fi
    echo "   tentativa $tentativa de 3 falhou para $repo; repetindo..." >&2
    sleep 3
  done
  PRONTO[$repo]=$rc
  return $rc
}

console() { sudo -u "$WEB_USER" php "$GLPI/bin/console" "$@"; }

FILTRO=("$@")
quer() { # plugin pedido?
  [ ${#FILTRO[@]} -eq 0 ] && return 0
  local p; for p in "${FILTRO[@]}"; do [ "$p" = "$1" ] && return 0; done
  return 1
}

OK=(); FALHA=(); IGUAL=()
for l in "${MAPA[@]}"; do
  IFS='|' read -r chave repo sub <<<"$l"
  quer "$chave" || continue
  if ! baixar "$repo"; then
    echo "!! $chave: não consegui baixar $OWNER/$repo após 3 tentativas (rede fora do ar ou repositório privado; neste caso configure GH_TOKEN em $ENV_FILE)"
    FALHA+=("$chave"); continue
  fi
  origem="$SRC/$repo"; [ "$sub" != "." ] && origem="$origem/$sub"
  [ -f "$origem/setup.php" ] || { echo "!! $chave: $origem/setup.php não existe"; FALHA+=("$chave"); continue; }
  destino="$GLPI/plugins/$chave"
  antes=$(versao "$destino"); novo=$(versao "$origem")
  mkdir -p "$destino"
  rsync -a --exclude '.git' --exclude 'tests' --exclude 'docs' --exclude 'manual' --exclude 'demo' --exclude '.DS_Store' "$origem/" "$destino/"
  chown -R "$WEB_USER:$WEB_USER" "$destino"
  if [ "$antes" != "$novo" ]; then
    echo ">> $chave: ${antes:-novo} -> $novo (instalando/atualizando)"
    if console plugin:install "$chave" -u "$GLPI_USER" && console plugin:activate "$chave"; then OK+=("$chave $novo"); else FALHA+=("$chave"); fi
  else
    echo "== $chave $novo: arquivos sincronizados (versão igual)"
    IGUAL+=("$chave")
  fi
done

console cache:clear >/dev/null && echo "Cache do GLPI limpo."
echo
echo "Atualizados : ${OK[*]:-nenhum}"
echo "Mesma versão: ${IGUAL[*]:-nenhum}"
echo "Falharam    : ${FALHA[*]:-nenhum}"
[ ${#FALHA[@]} -eq 0 ]
