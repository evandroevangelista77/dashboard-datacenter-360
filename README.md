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

### Topologia (`topologia.html`)

Mapa da rede monitorada, montado automaticamente a partir dos hosts do Zabbix e atualizado a cada 30 s:

- **Camadas**: Internet com os serviços externos verificados pelo Zabbix → gateway → switch núcleo da LAN → servidores, Zabbix server, proxy e estações.
- **Papel de cada ativo**: deduzido de templates, grupos e IP (*Zabbix server health*, *Zabbix proxy health*, *macOS*/*Windows*, interface `127.0.0.1` ou grupo *Website* para serviços externos, IP terminado em `.1` para o gateway).
- **Logotipos animados**: cada ativo mostra o logotipo da sua tecnologia (Zabbix, GLPI, Grafana, Prometheus, Vault, Ubuntu, CentOS, Apple, Google, Linux), com anel em órbita na cor do status e logotipo "respirando"; ativos offline ficam apagados.
- **Links animados**: pacotes trafegando nos dois sentidos em links ativos (mais rápidos com latência menor); link degradado em âmbar; link sem resposta em vermelho tracejado com ✕. Latência ICMP sobre cada link.
- **Status do nó**: ICMP quando existe; senão, disponibilidade das interfaces; problemas Alta/Desastre marcam como degradado; serviços externos pelo pior problema; proxy pelo último contato com o server.
- **Interação**: passar o mouse destaca o caminho do ativo até a internet; clique abre os detalhes (latência, perda, problemas, grupos, templates e atalhos para o painel e o Zabbix); busca por nome ou IP; zoom com a roda do mouse e arraste; "Fluxo de monitoramento" mostra a coleta do Zabbix (e do proxy) até cada ativo.

O usuário de API só leitura precisa de leitura também nos **grupos de templates**, senão o papel dos ativos não é identificado.

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

## Plugin para o GLPI (menu lateral)

A pasta [`glpi-plugin/datacenter360`](glpi-plugin/datacenter360) é um plugin do GLPI 11/12 que coloca o painel no menu lateral, **acima de Ativos**:

```
Dashboard
   ├─ DataCenter 360      → painel
   └─ Topologia da rede   → topologia
Ativos
...
```

- As páginas abrem o dashboard **dentro do GLPI** (cabeçalho e menu do GLPI), com botão "Abrir em tela cheia".
- Acesso controlado pelo direito **DataCenter 360**, em *Administração → Perfis → [perfil] → aba DataCenter 360*. Na instalação, a leitura é liberada para os perfis da interface padrão (técnicos e administradores); perfis self-service ficam sem acesso.
- Sessões abertas antes da instalação passam a ver o menu sem precisar relogar.

### Instalação do plugin

```bash
sudo cp -r glpi-plugin/datacenter360 /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/datacenter360
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install --username=glpi datacenter360
sudo -u www-data php bin/console plugin:activate datacenter360
sudo -u www-data php bin/console cache:clear
```

Ou pela interface, em *Configurar → Plugins*. Ao atualizar o GLPI, copie a pasta `plugins/` da versão anterior para a nova, como nas demais pastas de dados.

## Tema do GLPI (paleta DataCenter 360)

[`glpi-theme/datacenter360.scss`](glpi-theme/datacenter360.scss) é uma paleta do GLPI com as cores do painel (azul-noite, superfícies azul-escuras, destaque ciano e as mesmas cores de status), o corredor de datacenter bem transparente ao fundo e os mesmos detalhes de vidro, bordas e foco. Com ela, o GLPI e o painel parecem uma coisa só.

```bash
sudo install -o www-data -g www-data -m 644 glpi-theme/datacenter360.scss /var/www/glpi/files/_themes/
cd /var/www/glpi
sudo -u www-data php bin/console config:set palette datacenter360   # padrão para todos
sudo -u www-data php bin/console cache:clear
```

Cada usuário ainda pode escolher outra paleta em *Minhas configurações → Personalização*. Quem já estava logado passa a ver o tema novo depois de sair e entrar de novo.

## Plugin de assinatura digital no chamado (LibreSign)

[`glpi-plugin/assinatura`](glpi-plugin/assinatura) adiciona a aba **Assinatura digital** aos chamados do GLPI 12, integrada ao [LibreSign](https://libresign.coop) 15 (app do Nextcloud):

1. Na aba, escolha o **assinante** (padrão: requerente do chamado) e o **documento**: o PDF do chamado (gerado pelo plugin [PDF](https://github.com/pluginsGLPI/pdf) 4.2+) ou um PDF anexado.
2. O LibreSign envia ao assinante um e-mail com o link; ele assina com um clique e recebe um certificado emitido pela CA do LibreSign (assinatura PAdES válida).
3. O GLPI consulta o status ao abrir a aba e numa ação automática a cada 5 minutos. Quando assinado: anexa o **PDF assinado** ao chamado, registra um acompanhamento e, se configurado, **aprova a validação pendente** do assinante.

Solicitações pendentes podem ser canceladas na própria aba.

### Instalação

No Nextcloud, crie uma conta de serviço que possa solicitar assinaturas e uma senha de aplicativo:

```bash
cd /var/www/nextcloud
sudo -u www-data php occ group:add libresign-glpi
sudo -u www-data env OC_PASS='<senha aleatória>' php occ user:add --password-from-env --group libresign-glpi glpi-libresign
sudo -u www-data php occ config:app:set libresign groups_request_sign --value='["admin","libresign-glpi"]'
sudo -u www-data env OC_PASS='<mesma senha>' php occ user:auth-tokens:add --password-from-env glpi-libresign
```

No GLPI (com o plugin PDF instalado e ativo):

```bash
sudo cp -r glpi-plugin/assinatura /var/www/glpi/plugins/ && sudo chown -R www-data:www-data /var/www/glpi/plugins/assinatura
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install --username=glpi assinatura
sudo -u www-data php bin/console plugin:activate assinatura
```

Depois preencha *Configurar → Plugins → Assinatura digital*: URL do Nextcloud, usuário de serviço e senha de aplicativo (gravada criptografada). O botão **Testar conexão** valida as credenciais. Os assinantes precisam ter e-mail cadastrado no GLPI.

### Carimbo sem a marca do LibreSign (opcional)

O bloco de assinatura visível vem, por padrão, com o logotipo do LibreSign como marca d'água e o texto "Assinado com LibreSign". Para um carimbo neutro e um rodapé sem a marca (o QR code e o link de validação continuam):

```bash
cd /var/www/nextcloud
sudo -u www-data php occ config:app:set libresign signature_text --value='{"template":"Assinado digitalmente\n{{SignerCommonName}}\nEmissor: {{IssuerCommonName}}\nData: {{ServerSignatureDate}}","background_type":"deleted"}'
sudo -u www-data php occ config:app:set libresign footer_signed_by --value='Documento assinado digitalmente.'
```

Para voltar ao padrão: `occ config:app:delete libresign signature_text` e `occ config:app:delete libresign footer_signed_by`.

## Requisitos

- Zabbix 7.x com API habilitada.
- GLPI 11 ou 12 com banco MariaDB/MySQL local.
- PHP 8.3+ com `curl` e `mysqli`, servido pelo Apache.
- No Zabbix, para os gauges e a saúde do GLPI: o host do GLPI com os templates *MySQL by Zabbix agent 2* e *GLPI by HTTP*.

## Instalação

1. **No Zabbix**, crie um usuário só de leitura e um token de API para ele:
   - grupo de usuários sem acesso à interface, com permissão de **leitura** nos grupos de hosts e nos grupos de templates;
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
| `index.html`, `app.css`, `app.js` | Painel |
| `topologia.html`, `topologia.css`, `topologia.js` | Topologia animada da rede |
| `glpi-theme/datacenter360.scss` | Paleta do GLPI com as cores do painel |
| `glpi-plugin/assinatura/` | Plugin do GLPI: assinatura digital no chamado via LibreSign |
| `glpi-plugin/datacenter360/` | Plugin do GLPI: seção Dashboard no menu lateral |
| `logos.js` | Logotipos dos ativos (Simple Icons), gerado por `tools/gerar_logos.sh` |
| `api.php` | Backend somente leitura (Zabbix + GLPI) |
| `instalar.sh` | Instalação e atualização no servidor |
| `bg-datacenter.svg` | Fundo do corredor de datacenter, gerado por `tools/gerar_fundo.py` |
| `logo-zabbix.svg`, `logo-glpi.svg` | Logotipos dos sistemas integrados |
| `echarts.min.js` | Apache ECharts 5.5.1 (gráficos e gauges) |

## Créditos e licenças de terceiros

- [Apache ECharts](https://echarts.apache.org/) 5.5.1, licença Apache 2.0, incluído sem alterações.
- [Simple Icons](https://simpleicons.org) 16.34.0, licença CC0 1.0, para os logotipos da topologia.
- Zabbix, GLPI e as demais marcas exibidas pertencem a seus respectivos titulares. Os logotipos aparecem apenas para identificar os sistemas monitorados.
