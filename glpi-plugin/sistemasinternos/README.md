# Sistemas Internos 360 para GLPI 12

O painel do departamento de Sistemas Internos dentro do GLPI: o que o Zabbix monitora nos grupos do departamento, os chamados da entidade no GLPI, o cadastro dos hosts do Zabbix e os alertas, só nas telas do próprio plugin. Cor do departamento **#3769B2** (Sistemas da Informação), com a árvore ao fundo e a marca-d'água.

## O que tem

| Recurso | Descrição |
|---|---|
| **Visão 360** | **O mesmo dashboard do SI 360**, com o mesmo layout e todas as funções: situação geral, ação necessária, infraestrutura, **access points do Wi-Fi**, disponibilidade e tempo fora, atendimento do GLPI, prestadores, linha do tempo, **vulnerabilidades** (patches e softwares), **resumo operacional** (book mensal), **topologia** (também no menu, direto) e voz. Roda **inteiro dentro do GLPI**, sem servidor à parte: o plugin calcula tudo em PHP (`src/Si360*.php`), com a conta de serviço do Zabbix e o banco do GLPI, e serve a página pela `front/si.php`. O navegador só fala com o GLPI, com o login e os direitos do GLPI. A cor pode ser a do departamento (#3769B2, árvore e marca-d'água em azul) ou a original (verde). |
| **Resumo rápido** | Disponibilidade agora, hosts indisponíveis, problemas ativos (e os sem reconhecimento), alta e desastre, chamados em aberto, SLA vencido, vence em até 1 h e solucionados em 7 dias. Gráficos de estado dos hosts, problemas por severidade e abertos × solucionados (14 dias). Tabelas de problemas ativos (com atalho para o Zabbix), hosts indisponíveis e chamados em aberto ordenados pelo SLA. Atualiza sozinho a cada 2 minutos. |
| **Vulnerabilidades (Wazuh)** | Três visões das vulnerabilidades que o Wazuh encontra nos servidores: **Executiva** (ativos vulneráveis, riscos críticos, evolução, idade e tempo de correção), **Por ativo** (vulnerabilidades, severidade, pacotes afetados, responsável e chamados relacionados; filtro por entidade) e **Operacional** (prioridades de correção por pacote e ativo, filtros por entidade e alertas do Wazuh que viram chamado, sem duplicar). |
| **Segurança (Wazuh)** | Dashboard só de segurança com tudo o que o Wazuh oferece: eventos e alertas por criticidade, regras, agentes, MITRE ATT&CK, conformidade (PCI DSS, GDPR, HIPAA, NIST 800-53, TSC), integridade de arquivos (FIM), avaliação de configuração (SCA, com como corrigir), rootcheck, autenticação e acesso (SSH, sudo, contas), agentes e inventário (pacotes, portas, serviços, usuários, processos, hardware). Clicar em qualquer gráfico abre os eventos por trás. |
| **Hosts do Zabbix** | Lista dos hosts dos grupos liberados para o perfil, com interface, grupos, templates, situação e problemas; busca e filtro (ativos, desativados, indisponíveis, com problema). |
| **Incluir e editar** | Nome técnico e visível, descrição, grupos (só os do perfil), interface principal (agente, SNMP v1/v2c/v3, IPMI, JMX; IP ou DNS; porta), proxy, templates (lista aprovada na configuração), tags, macros de texto e vínculo com um computador do GLPI (tag `glpi_computer`). Na edição, grupos e templates que o perfil não alcança são **mantidos**, e macros secretas ou de cofre não são tocadas. |
| **Desativar e excluir** | Desativar para a coleta sem perder o histórico. Excluir pede para digitar o nome técnico. Os dois exigem que **todos** os grupos do host sejam do perfil, para não afetar outros departamentos. |
| **Auditoria** | Quem, quando, de qual IP, o quê, o antes e o depois, inclusive as tentativas recusadas pelo Zabbix. |
| **Alertas** | Sinalizador no topo das telas do plugin, sem aparecer em outros plugins (contador do que pede atenção agora, piscando quando chega algo novo), **som** (5 sons gerados no navegador), **voz** em português e **pop-up no rodapé**. Cada tipo liga ou desliga cada canal: severidades do Zabbix, problema resolvido, chamado novo na entidade, SLA vence em até 1 h, SLA vencido e host alterado por outra pessoa. |
| **Configurações** | Abas *Conexão com o Zabbix* (com teste), *Departamento*, *Cadastro de hosts*, *Alertas* (matriz com botão Testar) e *Notificações* (intervalo, duração e posição do pop-up, volume, velocidade da voz, horário de silêncio, tempo do "Silenciar"). |

## Acesso

- **Conta de serviço do Zabbix**: o GLPI fala com o Zabbix por um único token, guardado criptografado (GLPIKey) e nunca enviado ao navegador. A conta deve ter escrita **só** nos hostgroups do departamento: mesmo um erro no GLPI não alcança outros departamentos, porque o Zabbix recusa.
- **Direitos por perfil** (Administração → Perfis → aba *Sistemas Internos 360*): *Painel 360 e alertas* (ver); *Hosts do Zabbix* (ver, incluir, editar/ativar/desativar, excluir definitivamente); *Configurações*. Na instalação, perfis da interface padrão recebem *ver*; quem administra a configuração do GLPI recebe tudo.
- **Grupos por perfil**: na mesma aba, os hostgroups que o perfil pode gerenciar. Sem grupos marcados, usa os grupos do departamento.

## Conta de serviço no Zabbix

1. **Usuários → Grupos de usuários → Criar** `glpi-sistemas-internos`, sem acesso ao frontend, com *Leitura e escrita* nos hostgroups do departamento e *Leitura* nos grupos de templates.
2. **Usuários → Papéis**: papel do tipo **Admin** com acesso à API (pode limitar a `host.*`, `hostgroup.get`, `template.get`, `usermacro.*`, `problem.get`, `trigger.get`, `item.get`, `proxy.get`, `user.checkAuthentication`).
3. **Usuários → Usuários → Criar** `glpi-si360`, no grupo e com o papel acima.
4. **Usuários → Tokens de API → Criar** para esse usuário e cole o token em *Sistemas Internos → Configurações*.

## Instalação

```bash
sudo cp -r sistemasinternos /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/sistemasinternos
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi sistemasinternos
sudo -u www-data php /var/www/glpi/bin/console plugin:activate sistemasinternos
```

Ou o pacote gerado por `tools/empacotar-sistemasinternos.sh` (`sudo bash instalar.sh`).

## Como os alertas funcionam

- Cada navegador consulta o GLPI a cada N segundos (padrão 30). O GLPI consulta o Zabbix com cache de 20 s, compartilhado por todos.
- Cada usuário só recebe o que é novo desde a última consulta dele. Na primeira vez, nada dispara: o plugin só marca o ponto de partida.
- SLA: chamados em aberto da entidade (e filhas) com prazo de solução vencendo em até 1 h ou vencido, sem os prestadores de serviço configurados. Cada chamado alerta uma vez por situação.
- O navegador só toca som depois do primeiro clique na página. Até lá, o sinalizador balança.
- *Silenciar* (no sinalizador) e o horário de silêncio desligam só o som e a voz. O pop-up e o sinalizador continuam.

## Dados e desinstalação

Tabelas `glpi_plugin_sistemasinternos_profilegroups`, `_logs` (auditoria) e `_seens` (o que cada usuário já recebeu). Desinstalar apaga as três e a configuração. Os hosts no Zabbix não são tocados.

## Visão 360 dentro do GLPI

- **Zabbix**: só os hostgroups do departamento (*Configurações → Departamento*). Disponibilidade e tempo fora vêm dos eventos dos triggers de indisponibilidade (ICMP, agente, SNMP), como no painel original. Pedidos de outros grupos, hosts ou eventos respondem 404.
- **GLPI**: chamados, interações e inventário de softwares lidos direto do banco, da entidade do departamento e das filhas.
- **Cache**: no cache do GLPI, compartilhado por todos. Vencido, a tela recebe o anterior na hora e o novo é calculado depois da resposta. A primeira carga depois de instalar pode levar até ~40 s (topologia e bancos de dados); depois fica em poucos segundos.
- **Resumo operacional**: os números do book ficam na tabela `glpi_plugin_sistemasinternos_books` (cada gravação é uma linha, a mais recente vale). Na instalação entram os meses que já estavam no SI 360. Editar pede o direito *Configurações*.
- **Acionamento**: em *Configurações → Departamento*, nome e telefone de quem atende. Alertas Alta e Desastre nos grupos Sistemas Internos, ha-datacenter e Applications pedem para acionar essa pessoa.
- **Voz**: a do navegador (português). A voz neural e a OAK IA rodavam fora do GLPI e não fazem parte do plugin.


## Wazuh: Vulnerabilidades e Segurança

O plugin lê o **Indexer** do Wazuh (OpenSearch) pelo endereço do **Dashboard** (porta 443, que repassa as buscas) ou direto (porta 9200). Só o servidor do GLPI fala com o Wazuh; o navegador nunca. Configure em *Configurações → Wazuh* (endereço, usuário, senha, nível mínimo dos alertas que viram chamado) e use *Testar a conexão*.

- **Usuário**: crie no Wazuh um usuário **somente leitura** para o GLPI (papel com leitura nos índices `wazuh-*`) em vez do `admin`. A senha fica criptografada (GLPIKey).
- **Ativo → GLPI**: cada agente do Wazuh é ligado ao computador do GLPI pelo nome (ou pelo IP). Daí vêm a **entidade**, o **responsável** (técnico, grupo técnico ou usuário) e os **chamados relacionados** (itens do chamado). Agente sem computador no GLPI aparece como "Sem cadastro no GLPI".
- **Tempo de correção e evolução**: o Wazuh só guarda as vulnerabilidades atuais. O GLPI sincroniza a cada hora (ação automática *wazuhsync*, ou ao abrir a tela se passou de 2 h) e guarda quando cada uma apareceu e quando sumiu (corrigida) e uma foto diária dos totais. O tempo de correção é medido a partir da primeira correção observada.
- **Chamados sem duplicar**: o botão *Abrir chamado* cria um chamado (incidente) com a descrição, a ação sugerida, o computador e o grupo técnico. Cada chamado leva uma chave `[WZ:…]` no título e fica registrado: se já existe um em aberto para o mesmo pacote no mesmo ativo (ou a mesma regra no mesmo ativo), o plugin mostra o existente em vez de abrir outro. Exige o direito de criar chamados.
- **Prioridade (P1–P4)**: pela maior severidade do pacote no ativo (crítica, alta, média, baixa), ordenada por CVSS, quantidade e idade.
- **Gráficos e detalhes**: gráficos em degradê (medidor de exposição crítica, severidade, CVSS, evolução, idade, ativos, pacotes, detecções por dia e tempo de correção). Clicar abre, numa gaveta pela direita, a lista filtrada de vulnerabilidades e, ao clicar numa linha, **todos os detalhes da CVE** (pontuação, descrição, datas, ação sugerida, ativos e pacotes afetados com chamado, referências). *Por ativo* mostra cartões com responsável e chamados; *Operacional* tem filtros P1–P4, gráficos da fila e abertura de chamados em lote (sem duplicar).
- **Relatório PDF executivo**: o botão *Relatório PDF* (em Vulnerabilidades) gera, para a diretoria, um PDF completo: situação geral, indicadores, mensagens-chave e recomendações, panorama (severidade, CVSS, idade, evolução, tempo de correção), riscos críticos, ativos mais expostos com responsável e chamados, plano de correção P1/P2 com a versão que corrige, detalhe dos ativos mais expostos e metodologia. Respeita a entidade escolhida nos filtros.
- **EPSS × CVE**: a aba *EPSS* mostra, para um ativo (ou todos), as CVEs da mais à menos provável de serem exploradas nos próximos 30 dias (ranking, matriz EPSS × CVSS, curva e faixas de probabilidade). O EPSS vem da API pública do FIRST.org (`api.first.org`), consultada pelo servidor do GLPI e guardada em cache por 12 horas: o servidor precisa de acesso HTTPS a esse endereço (ou ao proxy do GLPI). Analisa até as 3.000 CVEs de maior CVSS do recorte.
- **Alertas**: cada vulnerabilidade nova P1 (crítica) ou P2 (alta) sobe **só o pop-up** no canto da tela (sem som, voz ou sinalizador; ajustável em *Configurações → Alertas → Wazuh*).
- Tabelas: `glpi_plugin_sistemasinternos_wzvulns`, `_wzsnaps` e `_wztickets`.

## Versões

- **2.0.0 (Sistemas Internos 360/2)**: segunda geração do plugin, com o **Wazuh** integrado: *Vulnerabilidades* (executiva, por ativo, operacional e **EPSS**), *Segurança* (eventos, MITRE ATT&CK, conformidade, FIM, SCA, acesso, agentes e inventário), **relatório executivo em PDF**, pop-up de vulnerabilidade nova P1/P2, gráficos interativos com gaveta de detalhes da CVE, tema NOC 360 nos cartões e o logotipo do Wazuh nas telas. A release é montada pelo GitHub (workflow `release-sistemasinternos`), sem depender de nenhum computador pessoal.
- **1.4.0**: integração com o **Wazuh**: telas *Vulnerabilidades* (executiva, por ativo e operacional, com abertura de chamado sem duplicar) e *Segurança* (eventos, MITRE ATT&CK, conformidade, FIM, SCA, acesso, agentes e inventário), aba *Wazuh* nas configurações e sincronização horária do histórico.
- **1.2.4**: a lista do clique nos gráficos abre numa gaveta pela direita, na altura toda da tela.
- **1.3.0**: os alertas (sinalizador, som, voz e pop-up) passam a aparecer só nas telas do Sistemas Internos 360, sem vazar para outras telas do GLPI nem para outros plugins; a opção "Alertar em todas as telas" foi removida.
- **1.2.3**: no Resumo rápido, clicar numa fatia, barra ou ponto dos gráficos abre a lista dos alertas (com o link de cada alerta no Zabbix), dos hosts ou dos chamados do dia, com filtros de busca (host, problema, chamado), período, severidade, reconhecimento, estado e aberto/solucionado. O link da tabela de problemas abre o próprio alerta.
- **1.2.2**: cartões, tabelas e formulários das telas do plugin em azul escuro (tom do #3769B2) com texto claro, legíveis sobre o fundo; gráficos com letras claras.
- **1.2.1**: o menu *Topologia* volta a abrir o mapa direto.
- **1.2.0**: a Visão 360 passa a rodar inteira dentro do GLPI, sem o servidor do SI 360 e sem os painéis das portas 8090 e 8091 (sai o campo *Servidor do SI 360*). Resumo operacional guardado no banco do GLPI, acionamento configurável, teste de conexão compatível com o Zabbix 7.2.
- **1.1.0**: Visão 360 e Topologia com o dashboard completo do SI 360 (mapa, APs do Wi-Fi, vulnerabilidades, resumo operacional, voz, book), opção de cor (azul do departamento ou original) e correção da atualização do plugin (direitos duplicados).
- **1.0.0**: primeira versão.
