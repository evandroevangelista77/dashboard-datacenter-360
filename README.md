# Dashboard DataCenter 360

Painel de operações (NOC) que junta **Zabbix** e **GLPI** em uma só tela: alertas, problemas ativos, chamados, hosts, SLA, TMA, MTTD, MTTR e a saúde do banco de dados, com filtros por data, host e chamado.

Feito para ficar aberto numa TV de sala de operações (modo tela cheia) e também para uso no dia a dia pelo navegador.

## O que mostra

| Bloco | Origem | Detalhe |
|---|---|---|
| **KPIs** | Zabbix + GLPI | Alertas, problemas ativos, chamados, hosts, SLA de solução, TMA, MTTD e MTTR. Clicar leva à seção correspondente |
| **Ticker ao vivo** | Zabbix | Problemas ativos rolando no topo; clique filtra pelo host |
| **Banco de dados** | Zabbix (template *MySQL by Zabbix agent 2*) | Gauges de eficiência e uso do buffer pool, conexões em uso e consultas por segundo, mais threads, consultas lentas, tamanho do banco e uptime |
| **Saúde do GLPI** | Zabbix (template *GLPI by HTTP*) | Aplicação, banco, ações automáticas, sistema de arquivos e página de login |
| **SLAs de serviço** | Zabbix | SLO × SLI dos SLAs cadastrados |
| **Hosts** | Zabbix | Status de cada host, problemas e disponibilidade por ICMP no período; clique filtra |
| **Rankings** | Zabbix + GLPI | Hosts com mais alertas, chamados por categoria e por entidade; clique filtra |
| **Tabelas** | Zabbix + GLPI | Problemas ativos (filtro por severidade) e chamados (em aberto, solucionados, fora do SLA), ordenáveis |
| **Chamado em foco** | GLPI + Zabbix | Ao filtrar um chamado: dados do GLPI e a linha do tempo do evento no Zabbix (detecção, reconhecimentos, recuperação) |

### Definições das métricas

- **MTTD**: início do problema → primeiro reconhecimento no Zabbix.
- **MTTR**: início do problema → recuperação.
- **TMA**: abertura → solução do chamado no GLPI.
- **SLA de solução**: chamados solucionados dentro do prazo. Usa o SLA do próprio chamado; sem SLA, usa a meta por prioridade definida em `sla_hours` na configuração.
- **Disponibilidade**: média do item `icmpping` (trends) no período.

## Arquitetura

```
Navegador ──> /datacenter360/index.html + app.js (ECharts)
                │
                └──> api.php (PHP, no servidor do GLPI)
                       ├── API do Zabbix  (token de usuário só leitura)
                       └── MariaDB glpidb (usuário só com SELECT)
```

- Nenhuma credencial chega ao navegador: o `api.php` faz todas as consultas.
- As credenciais ficam em `/etc/datacenter360/config.php`, fora da pasta web, legível só por `root` e pelo Apache.
- O acesso é restrito à rede local (`Require ip` no Apache).
- Sem dependências externas em tempo de execução: ECharts, logotipos e fundo são servidos localmente.

A correlação entre chamado e host usa o título gerado pela integração Zabbix → GLPI (`[Severidade] HOST: problema`), e o vínculo com o evento usa o link `eventid=` gravado no corpo do chamado.

## Requisitos

- Zabbix 7.x com API habilitada.
- GLPI 11 ou 12 com banco MariaDB/MySQL local.
- PHP 8.3+ com `curl` e `mysqli`, servido pelo Apache.
- No Zabbix, para os gauges e a saúde do GLPI: o host do GLPI com os templates *MySQL by Zabbix agent 2* e *GLPI by HTTP*.

## Instalação

1. **No Zabbix**, crie um usuário só de leitura e um token de API para ele:
   - grupo de usuários sem acesso à interface, com permissão de **leitura** nos grupos de hosts;
   - papel *User role*;
   - token em *Usuários → Tokens de API*.

2. **No servidor do GLPI**, copie os arquivos e rode o instalador como root:

   ```bash
   sudo ZABBIX_TOKEN='<token do usuário só leitura>' bash instalar.sh /caminho/dos/arquivos
   ```

   Na primeira execução ele:
   - cria o usuário `dc360_ro` no MariaDB com `SELECT` em `glpidb`;
   - gera `/etc/datacenter360/config.php`;
   - publica os arquivos em `/var/www/datacenter360`;
   - cria o alias `/datacenter360` no Apache.

   Nas execuções seguintes ele só atualiza os arquivos e mantém a configuração.

3. Ajuste em `/etc/datacenter360/config.php`, se necessário:
   - os endereços do Zabbix e do GLPI;
   - `db_metrics_host`, o nome técnico do host no Zabbix que alimenta os gauges;
   - `sla_hours`, as metas de solução por prioridade;
   - a faixa de IP liberada em `/etc/apache2/conf-available/datacenter360.conf`.

4. Acesse `http://<servidor-glpi>/datacenter360/`.

## Arquivos

| Arquivo | Função |
|---|---|
| `index.html`, `app.css`, `app.js` | Interface |
| `api.php` | Backend somente leitura (Zabbix + GLPI) |
| `instalar.sh` | Instalação e atualização no servidor |
| `bg-datacenter.svg` | Fundo do corredor de datacenter, gerado por `tools/gerar_fundo.py` |
| `logo-zabbix.svg`, `logo-glpi.svg` | Logotipos dos sistemas integrados |
| `echarts.min.js` | Apache ECharts 5.5.1 (gráficos e gauges) |

## Créditos e licenças de terceiros

- [Apache ECharts](https://echarts.apache.org/) 5.5.1, licença Apache 2.0, incluído sem alterações.
- Zabbix e GLPI são marcas de seus respectivos titulares. Os logotipos aparecem apenas para identificar os sistemas integrados.
