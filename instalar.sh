#!/bin/bash
# Instala/atualiza o NOC 360 (DataCenter 360) no servidor GLPI (idempotente).
# Uso (no servidor, como root):  ZABBIX_TOKEN=... bash instalar.sh /caminho/dos/arquivos
#   - ZABBIX_TOKEN só é exigido na primeira instalação (depois o config é mantido)
set -euo pipefail

SRC=${1:-.}
WEB=/var/www/datacenter360
CONF_DIR=/etc/datacenter360
CONF=$CONF_DIR/config.php

mkdir -p "$WEB" "$CONF_DIR"

if [ ! -f "$CONF" ]; then
    : "${ZABBIX_TOKEN:?defina ZABBIX_TOKEN na primeira instalação}"
    DB_PASS=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-28)
    mariadb <<SQL
CREATE USER IF NOT EXISTS 'dc360_ro'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER 'dc360_ro'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT ON glpidb.* TO 'dc360_ro'@'localhost';
FLUSH PRIVILEGES;
SQL
    cat > "$CONF" <<PHP
<?php
// DataCenter 360 — credenciais somente leitura (fora da pasta web)
return [
    'timezone'     => 'America/Sao_Paulo',
    'zabbix_url'   => 'http://192.168.15.77/zabbix/api_jsonrpc.php',
    'zabbix_token' => '$ZABBIX_TOKEN', // usuário dc360-api (só leitura)
    'zabbix_front' => 'http://192.168.15.77/zabbix',
    'glpi_front'   => 'http://192.168.15.45',
    'db' => ['host' => 'localhost', 'name' => 'glpidb', 'user' => 'dc360_ro', 'pass' => '$DB_PASS'],
    // host técnico do Zabbix cujas métricas de banco alimentam os gauges
    'db_metrics_host' => 'glpi',
    // meta de solução (horas) por prioridade GLPI, usada quando o chamado não tem SLA
    'sla_hours' => [6 => 1, 5 => 2, 4 => 4, 3 => 8, 2 => 24, 1 => 48],
];
PHP
    chown root:www-data "$CONF"
    chmod 640 "$CONF"
    echo "config criado em $CONF"
fi

for f in api.php index.html app.css app.js logo-zabbix.svg logo-glpi.svg bg-datacenter.svg topologia.html topologia-embed.html topologia.css topologia.js ticker.js logos.js; do
    [ -f "$SRC/$f" ] && install -o root -g www-data -m 644 "$SRC/$f" "$WEB/$f"
done

# versão nos links dos arquivos (?v=...): o navegador nunca mistura HTML novo com JS/CSS antigo em cache
V=$(date +%s)
for f in index.html topologia.html topologia-embed.html; do
    [ -f "$WEB/$f" ] && sed -i -E "s#(href|src)=\"(app\.css|app\.js|topologia\.css|topologia\.js|ticker\.js|logos\.js)(\?v=[0-9]+)?\"#\1=\"\2?v=$V\"#g" "$WEB/$f"
done
a2enmod -q headers >/dev/null 2>&1 || true

cat > /etc/apache2/conf-available/datacenter360.conf <<'APACHE'
# NOC 360 — dashboard Zabbix x GLPI (somente rede local)
Alias /datacenter360 /var/www/datacenter360
<Directory /var/www/datacenter360>
    Options -Indexes
    DirectoryIndex index.html
    Require ip 192.168.15.0/24 127.0.0.1 ::1
    # a tela e seus scripts mudam com frequência: o navegador confere a versão a cada abertura
    <FilesMatch "\.(html|js|css)$">
        Header set Cache-Control "no-cache"
    </FilesMatch>
</Directory>
APACHE
a2enconf -q datacenter360 >/dev/null
apache2ctl configtest
systemctl reload apache2
php -l "$WEB/api.php"

# opcional: mantém o cache aquecido (a primeira consulta de um período no Zabbix pode levar ~10 s).
#   DC360_AQUECER=1 bash instalar.sh /caminho/dos/arquivos
if [ "${DC360_AQUECER:-0}" = "1" ] && [ -f "$SRC/aquecer.sh" ]; then
    install -m 755 "$SRC/aquecer.sh" /usr/local/bin/dc360-aquecer
    echo '* * * * * www-data /usr/local/bin/dc360-aquecer' > /etc/cron.d/dc360-aquecer
    echo "aquecimento do cache agendado (a cada minuto)"
fi
echo "NOC 360 publicado em /datacenter360"
