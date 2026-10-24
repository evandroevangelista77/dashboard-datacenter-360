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

Dentro do editor de texto (TinyMCE) a paleta usa fundo liso, sem a imagem do corredor. O editor carrega o CSS da paleta por um endereço sem versão, que o GLPI manda guardar por 30 dias; para mudanças na paleta chegarem ao editor sem limpar o cache do navegador, ative [`glpi-theme/apache-tema-editor.conf`](glpi-theme/apache-tema-editor.conf), que faz só esse endereço ser revalidado:

```bash
sudo cp glpi-theme/apache-tema-editor.conf /etc/apache2/conf-available/glpi-tema-editor.conf
sudo a2enmod headers && sudo a2enconf glpi-tema-editor && sudo systemctl reload apache2
```

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

Por padrão o bloco de assinatura traz o logotipo do LibreSign como marca d'água e o texto "Assinado com LibreSign". Remover o fundo (`background_type: deleted`) **não** serve: nesse modo o LibreSign usa a assinatura desenhada como fundo do bloco e escreve o texto por cima. O jeito certo é um fundo personalizado **transparente**, que mantém o layout desenho | descrição:

```bash
cd /var/www/nextcloud
sudo -u www-data php occ config:app:set libresign signature_text --value='{"template":"Assinado digitalmente\n{{SignerCommonName}}\nEmissor: {{IssuerCommonName}}\nData: {{ServerSignatureDate}}"}'
sudo -u www-data php occ config:app:set libresign footer_signed_by --value='Documento assinado digitalmente.'
cat > /tmp/bg.php <<'PHP'
<?php
require '/var/www/nextcloud/lib/base.php';
$img = new Imagick(); $img->newImage(1050, 300, new ImagickPixel('transparent')); $img->setImageFormat('png32');
$tmp = tempnam(sys_get_temp_dir(), 'bg') . '.png'; $img->writeImage('png32:' . $tmp);
\OCP\Server::get(\OCA\Libresign\Service\SignatureBackgroundService::class)->updateImage($tmp); unlink($tmp);
PHP
sudo -u www-data php /tmp/bg.php && rm /tmp/bg.php
```

### E-mail de assinatura com a identidade da empresa (opcional)

- **Logotipo, nome e cor** do topo dos e-mails vêm do Tema do Nextcloud: `occ theming:config logo /caminho/logo.png`, `occ theming:config name "…"`, `occ theming:config primary_color "#2b3d47"`.
- **Idioma**: `occ config:system:set default_language --value=pt_BR` (o assinante sem conta recebe o e-mail no idioma padrão).
- **Título e assunto**: o app [`nextcloud-app/oakmail`](nextcloud-app/oakmail) troca o título dos e-mails do LibreSign por "RELATÓRIO DE ATENDIMENTO TÉCNICO" e o prefixo do assunto. Copie para `/var/www/nextcloud/apps/`, rode `occ app:enable oakmail` e `occ config:system:set mail_template_class --value='\OCA\OakMail\EMailTemplate'`.

Depois preencha *Configurar → Plugins → Assinatura digital*: URL do Nextcloud, usuário de serviço e senha de aplicativo (gravada criptografada). O botão **Testar conexão** valida as credenciais. Os assinantes precisam ter e-mail cadastrado no GLPI.

### Carimbo sem a marca do LibreSign (opcional)

O bloco de assinatura visível vem, por padrão, com o logotipo do LibreSign como marca d'água e o texto "Assinado com LibreSign". Para um carimbo neutro e um rodapé sem a marca (o QR code e o link de validação continuam):

```bash
cd /var/www/nextcloud
sudo -u www-data php occ config:app:set libresign signature_text --value='{"template":"Assinado digitalmente\n{{SignerCommonName}}\nEmissor: {{IssuerCommonName}}\nData: {{ServerSignatureDate}}","background_type":"deleted"}'
sudo -u www-data php occ config:app:set libresign footer_signed_by --value='Documento assinado digitalmente.'
```

Para voltar ao padrão: `occ config:app:delete libresign signature_text` e `occ config:app:delete libresign footer_signed_by`.

## Campos extras no cadastro de Projeto (plugin Fields)

[`glpi-projetos/criar_campos.py`](glpi-projetos/criar_campos.py) prepara o cadastro de **Projeto** do GLPI 12 com as colunas da planilha de controle de projetos, usando os campos nativos onde eles existem e o plugin [Fields](https://github.com/pluginsGLPI/fields) 1.25+ para o resto:

- **Nativos**: tipos de projeto (Projeto, Manutenção, Atividade Controlada) e estados (Iniciação, Planejamento, Execução, Pendente Cliente, Pendente OAK, Encerramento, Fechado). Nome, Descrição, Código (centro de custo), Gerente (GP) e Data de término planejada (prazo contratual) já existem no Projeto.
- **Bloco "Dados do projeto"** dentro do formulário, com 18 campos em 3 seções: *Comercial e financeiro* (Cliente, Horas GP, Receita, Receita de Serviço, Margem Venda, Margem EAC), *Manutenção e prazos* (Data do Aviso de Fechamento, Prazo EAC, Previsão Conclusão GP, Possui Manutenção, Período Manutenção) e *Acompanhamento* (Líder Técnico, Status Atual, Ponto de Atenção, Descrição do Ponto de Atenção, Esforço, Complexidade, Classificação).
- **Listas** preenchidas: Ponto de Atenção, Esforço, Complexidade, Classificação e, se existir `glpi-projetos/clientes.txt` (um cliente por linha; veja `clientes.exemplo.txt`), a lista de clientes. O arquivo real de clientes não vai para o repositório.

O script é idempotente (o que já existe é mantido):

```bash
GLPI_URL=http://<glpi>/apirest.php GLPI_APP_TOKEN=... GLPI_USER_TOKEN=... python3 glpi-projetos/criar_campos.py
```

## Importação de cronograma do MS Project (plugin)

[`glpi-plugin/cronograma`](glpi-plugin/cronograma) adiciona ao **Projeto** do GLPI 12 a aba **Cronograma (MS Project)**, que importa um cronograma `.mpp`/`.mpt` ou XML (MSPDI) como tarefas do projeto:

- **Tarefas e hierarquia**: nome, notas, datas planejadas e reais, duração planejada em tempo corrido (como a efetiva do GLPI), % concluído e marcos, respeitando os níveis de estrutura do Project. A tarefa-resumo do projeto pode atualizar as datas planejadas do próprio Projeto.
- **Dependências** (TI, II, TT, IT, com latência em dias), que aparecem no Gantt do GLPI.
- **Recursos**: quem tiver usuário no GLPI (login ou nome completo) entra na equipe da tarefa; os demais ficam registrados no comentário da tarefa.
- **Reimportação sem duplicar**: cada tarefa fica vinculada ao UID do Project, então uma nova importação atualiza as existentes, cria as novas e, se pedido, manda para a lixeira as que saíram do cronograma.
- O arquivo original é anexado como documento do projeto e cada importação fica no histórico da aba.
- Na lista **Tarefas do projeto**, o "Percentual finalizado" vira uma barra de evolução (verde concluída, azul a partir de 50%, laranja abaixo) e as linhas ficam alternadas, clara e escura.

### Instalação

O `.mpp` é convertido no servidor pelo [MPXJ](https://www.mpxj.org/) (Java). Sem Java, o plugin aceita só o XML exportado pelo Project (*Arquivo → Salvar como → XML*).

```bash
sudo apt install default-jre-headless
# MPXJ: mpxj.jar e a pasta lib/ do pacote de distribuição em /opt/mpxj
sudo cp -r glpi-plugin/cronograma /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/cronograma
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi cronograma
sudo -u www-data php /var/www/glpi/bin/console plugin:activate cronograma
```

Os caminhos do Java e do MPXJ ficam em `glpi_configs` (contexto `plugin:cronograma`, chaves `java` e `classpath`).

## PMO: gerenciamento de projetos ágeis e waterfall (plugin)

[`glpi-plugin/pmo`](glpi-plugin/pmo) transforma os **Projetos e Tarefas nativos** do GLPI 12 num ambiente de PMO, sem duplicar nada (cada tarefa do projeto é um cartão):

- **Gantt das atividades** logo abaixo do formulário do Projeto, na aba **Gantt** e em tela cheia: fases recolhíveis, % concluído, marcos, dependências (as mesmas importadas pelo plugin Cronograma), linha de hoje, zoom e arrastar para mudar datas. Funciona mesmo sem ativar o PMO no projeto.
- **Quadro Kanban** estilo Jira: colunas configuráveis com WIP, arrastar e soltar, raias por fase ou responsável, filtros e criação rápida.
- **Backlog e sprints** (Scrum): planejar, iniciar e concluir sprints, com velocidade.
- **Painéis**: saúde, % real × planejado, SPI, burndown, burnup com previsão de término, curva S, fluxo cumulativo e carga da equipe.
- **Portfólio do PMO** (com os campos do bloco "Dados do projeto" do plugin Fields) e **Meu trabalho**.
- **Documentação** com modelos (TAP, EAP, RACI, comunicação, ata, status report, lições aprendidas, retrospectiva) e **registro de riscos** com matriz 5×5.
- **Avisos por e-mail** pelas notificações do GLPI: sprint iniciada, sprint concluída, risco crítico e tarefa atrasada.

### Instalação

Baixe `oak-pmo-completo-<versão>.zip` em [Releases](https://github.com/evandroevangelista77/dashboard-datacenter-360/releases) e, no servidor do GLPI:

```bash
unzip oak-pmo-completo-1.1.0.zip && cd oak-pmo-completo
sudo bash instalar.sh
```

Ou manualmente, a partir deste repositório:

```bash
sudo cp -r glpi-plugin/pmo /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/pmo
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi pmo
sudo -u www-data php /var/www/glpi/bin/console plugin:activate pmo
```

Uso, permissões, cálculos e problemas comuns: [`glpi-plugin/pmo/README.md`](glpi-plugin/pmo/README.md). O pacote da release é gerado por `tools/empacotar-pmo.sh`.

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
| `glpi-theme/apache-tema-editor.conf` | Apache: paleta sempre atualizada dentro do editor de texto |
| `glpi-plugin/assinatura/` | Plugin do GLPI: assinatura digital no chamado via LibreSign |
| `nextcloud-app/oakmail/` | App do Nextcloud: título e assunto próprios nos e-mails do LibreSign |
| `glpi-plugin/cronograma/` | Plugin do GLPI: importação de cronograma do MS Project nas tarefas do projeto |
| `glpi-plugin/pmo/` | Plugin do GLPI: PMO com Gantt, Kanban, sprints, painéis, documentação e riscos |
| `glpi-plugin/reembolso/` | Plugin do GLPI: reembolso de despesas de técnicos (KM por rota ou odômetro, aprovação, dashboard e XLSX) |
| `tools/vigia-glpi.sh`, `tools/com.evandro.vigia-glpi.plist` | Vigia que religa a rede da VM do GLPI no VirtualBox (macOS) |
| `tools/empacotar-pmo.sh`, `tools/pmo-pacote/` | Geração do pacote de release do PMO (instalador e LEIA-ME) |
| `tools/empacotar-reembolso.sh`, `tools/reembolso-pacote/` | Geração do pacote de release do Reembolso (instalador e LEIA-ME) |
| `glpi-projetos/criar_campos.py` | Campos extras do cadastro de Projeto (plugin Fields) |
| `glpi-plugin/datacenter360/` | Plugin do GLPI: seção Dashboard no menu lateral |
| `logos.js` | Logotipos dos ativos (Simple Icons), gerado por `tools/gerar_logos.sh` |
| `api.php` | Backend somente leitura (Zabbix + GLPI) |
| `instalar.sh` | Instalação e atualização no servidor |
| `bg-datacenter.svg` | Fundo do corredor de datacenter, gerado por `tools/gerar_fundo.py` |
| `logo-zabbix.svg`, `logo-glpi.svg` | Logotipos dos sistemas integrados |
| `echarts.min.js` | Apache ECharts 5.5.1 (gráficos e gauges) |

## Reembolso de despesas (plugin)

[`glpi-plugin/reembolso`](glpi-plugin/reembolso) traz para o GLPI 12 o lançamento e a aprovação das despesas de técnicos em atendimentos externos, numa seção **Financeiro** do menu lateral:

- **Solicitação de reembolso** com técnico, centro de custo, chamado do atendimento, localização, local do atendimento, período e justificativa (código `RB-00001`).
- **30 tipos de despesa** já cadastrados, cada um com limite por lançamento e exigência de comprovante: quilometragem, combustível, pedágio, estacionamento, refeições, hospedagem, táxi ou aplicativo, passagens, locação de veículo, peças, ferramentas, frete, diária etc.
- **Quilometragem** pela **rota entre origem e destino** (OpenStreetMap, com ida e volta) ou pelo **odômetro**, multiplicada pelo valor por KM configurado.
- **Fluxo** rascunho → enviar para aprovação → aprovar ou recusar (com motivo) → pago, com comprovantes na aba *Documentos* e histórico completo.
- **Dashboard** com filtros, indicadores e gráficos, e **exportação para Excel (XLSX)**.
- Aba **Reembolsos** nos chamados e permissões por perfil (ver os próprios, ver todos, aprovar e registrar pagamento).

### Instalação

Baixe `oak-reembolso-completo-<versão>.zip` em [Releases](https://github.com/evandroevangelista77/dashboard-datacenter-360/releases) e, no servidor do GLPI:

```bash
unzip oak-reembolso-completo-1.0.0.zip && cd oak-reembolso-completo
sudo bash instalar.sh
```

Uso, configuração e permissões: [`glpi-plugin/reembolso/README.md`](glpi-plugin/reembolso/README.md). O pacote da release é gerado por `tools/empacotar-reembolso.sh`.

## Vigia da VM do GLPI (VirtualBox no macOS)

[`tools/vigia-glpi.sh`](tools/vigia-glpi.sh) é para um laboratório em que o GLPI roda numa VM do VirtualBox no Mac, em ponte com o Wi-Fi. Sem o driver de kernel do VirtualBox (modo *driverless*), essa ponte às vezes para de entregar pacotes à VM, que continua ligada mas some da rede. A cada 20 s o vigia testa o GLPI; se cair (com o Mac e o roteador respondendo), desliga e religa o cabo virtual da VM (`VBoxManage controlvm … setlinkstate1`), o que traz a rede de volta em segundos, e registra em `~/Library/Logs/vigia-glpi.log`.

```bash
install -m 755 tools/vigia-glpi.sh ~/.local/bin/vigia-glpi.sh
sed "s#SEU_USUARIO#$USER#g" tools/com.evandro.vigia-glpi.plist > ~/Library/LaunchAgents/com.evandro.vigia-glpi.plist
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.evandro.vigia-glpi.plist
```

Nome da VM, IP e gateway vêm das variáveis `VIGIA_VM`, `VIGIA_IP` e `VIGIA_GATEWAY` (os padrões são os do laboratório). A solução definitiva é liberar o driver do VirtualBox em *Ajustes do Sistema → Privacidade e Segurança* ou ligar o Mac por cabo.

## Licença

O código deste repositório (painel, plugins do GLPI, tema e scripts) é distribuído sob a [GNU General Public License versão 3](LICENSE) ou posterior (GPLv3+), a mesma licença do GLPI. Os componentes de terceiros abaixo mantêm as próprias licenças.

## Créditos e licenças de terceiros

- [Apache ECharts](https://echarts.apache.org/) 5.5.1, licença Apache 2.0, incluído sem alterações.
- [Simple Icons](https://simpleicons.org) 16.34.0, licença CC0 1.0, para os logotipos da topologia.
- Zabbix, GLPI e as demais marcas exibidas pertencem a seus respectivos titulares. Os logotipos aparecem apenas para identificar os sistemas monitorados.
