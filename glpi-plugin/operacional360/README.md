# Operacional 360 para GLPI 11 e 12

Painel operacional **por entidade**: os chamados da entidade (e das filhas) no GLPI e os alertas do Zabbix dos **host groups** ligados a ela. O layout, os campos e o tema são os do NOC 360 (cards, gráficos, tabelas, filtros, alertas por voz e pop-up), sem os botões de versão do Zabbix e do GLPI.

## Como funciona

| Quem | O que vê |
|---|---|
| **Gestor de uma entidade** | Painel da entidade escolhida (seletor aparece quando ele gerencia mais de uma): chamados da entidade e das filhas, e os alertas dos host groups configurados para ela. |
| **Administrador** | Tudo acima, mais o menu **Configurar Operacional 360** (Configurar → Plugins). |

- A entidade que o usuário pode ver é a do mapa **e** liberada no perfil dele (`Session::haveAccessToEntity`). Nenhuma outra entidade aparece, mesmo com um ID digitado na URL.
- Os **chamados** vêm do banco do GLPI, da entidade e das filhas (pelo nome completo). O "cliente" de cada chamado é a filha direta da entidade do painel.
- Os **alertas** vêm do Zabbix, só dos host groups do mapa da entidade. Cada host group tem as **severidades** que aparecem para aquela entidade (ex.: no grupo *Firewalls* só Desastre e Alta).
- A conexão com o Zabbix usa uma conta de **somente leitura** (a API só aceita métodos `.get`). O token é cifrado no GLPI e nunca vai para o navegador.

## Configuração (só administrador)

Configurar → Plugins → **Operacional 360**:

1. **Conexão com o Zabbix**: endereço da API e token. O botão de salvar testa a conexão e mostra a versão da API.
2. **Mapa**: uma linha por *entidade × host group*, com as severidades que aparecem. Linhas em branco aparecem para incluir mais.
3. **Painel**: severidades exibidas nos cartões, metas de solução por prioridade (horas), limites de lista e cache.
4. **Alertas do navegador**: canais de cada tipo (sinalizador, som, pop-up, voz, fica até fechar) e preferências.

A permissão de configurar é a do próprio GLPI (`config` → atualizar), ou seja, só o administrador. O direito *Operacional 360* (ver o painel) é dado a todos os perfis da interface central; a entidade e o host group limitam o que cada um vê.

## Instalação

```bash
sudo cp -r operacional360 /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/operacional360
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi operacional360
sudo -u www-data php /var/www/glpi/bin/console plugin:activate operacional360
```

Requisitos: GLPI 11.0 a 12.0, PHP com `curl` e `json`.

## Arquivos

| Caminho | Função |
|---|---|
| `setup.php`, `hook.php` | Versão, menu, configuração, direito e instalação |
| `src/Settings.php` | Configuração, mapa entidade → host group, alertas |
| `src/Scope.php` | Entidades e host groups que o usuário pode ver |
| `src/Api.php` | Painel, alertas do navegador, históricos (dados em JSON) |
| `src/Zabbix.php` | Cliente de leitura da API do Zabbix |
| `src/Glpi.php` | Leituras do banco do GLPI (entidades, chamados, histórico) |
| `front/painel.php` | Página do GLPI com o quadro do painel |
| `front/op360.php` | Serve a página e a API (com o login e o direito do GLPI) |
| `front/config.form.php` | Configuração (só administrador) |
| `public/static/` | Interface do painel (cópia do NOC 360, sem versões e sem TrackingTime) |

## Diferenças em relação ao NOC 360

- Sem os botões de versão do Zabbix e do GLPI, nem a verificação de atualizações.
- Sem o painel de horas do TrackingTime (é outra integração, com outra conta).
- Sem a topologia da rede: é o painel de chamados e alertas por entidade.
- O escopo por entidade e host group substitui o cliente de nome da versão standalone.
