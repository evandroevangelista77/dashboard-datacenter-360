# Gestão de Eventos para GLPI 11 e 12

Leva cada evento (feira, congresso, evento de parceiro ou fabricante, iniciativa interna, participação e agenda comercial) **do briefing ao encerramento**, com aprovações por alçada, SLO em cada etapa, avisos, abas para cada parte do processo, painel e relatórios. Fica numa seção **Gestão de Eventos** do menu lateral, com o mesmo layout, os mesmos gráficos, os mesmos painéis laterais e as mesmas dicas ao passar o mouse do plugin **Ciclo Financeiro**.

Base: *Especificação de Requisitos ER-GE-GLPI-001 v1.1 (com o aditivo da API Uber)*.

## O fluxo (8 etapas)

| # | Etapa | Área sugerida | SLO sugerido | Para concluir |
|---|---|---|---|---|
| 1 | Solicitação e briefing | Marketing | 3 | briefing completo, triagem aceita, antecedência (ou exceção aprovada) |
| 2 | Avaliação e aprovação | Diretoria | 5 | centro de custo e aprovação formal de entrada |
| 3 | Abertura e planejamento | PMO | 7 | GP, projeto GLPI, kickoff, marcos, RACI e orçamento base aprovado |
| 4 | Cotações e contratações | Compras | 15 | nenhuma compra pendente de cotação, aprovação ou contrato |
| 5 | Produção e logística | Marketing / Logística | 20 | checklists críticos, entregas e peças resolvidos |
| 6 | Preparação final | PMO + Marketing | 3 | aceite do GP e do Marketing, contingência, sem item crítico |
| 7 | Execução | Marketing | 3 (conta a partir do início do evento) | evento realizado, sem ocorrência crítica aberta |
| 8 | Pós-evento e encerramento | PMO / Financeiro | 30 | Definition of Done e aceite formal do dono |

Os **SLOs são uma sugestão inicial**: meça os primeiros eventos e ajuste em **Etapas e SLOs**.

Estado do evento, etapa, status do projeto e status de aprovação são campos separados (RN-07): situação *Em andamento / Concluído / Cancelado*; aprovação *Rascunho, Em triagem, Em aprovação, Aprovado, Rejeitado, Postergado*; projeto *Não iniciado, Em planejamento, Em execução, Em risco, Concluído, Cancelado* (o "em risco" vem do semáforo: etapa atrasada, risco alto aberto, marco crítico ou aprovação vencida).

## As abas do evento

Cada aba mostra no alto a **etapa a que pertence** (situação, SLO, o que falta e o botão *Concluir etapa*), de modo que dá para andar o fluxo de qualquer uma. O que se cadastra numa aba alimenta as exigências das etapas seguintes.

| Aba | O que faz |
|---|---|
| **Evento** | briefing (28 campos do template), cartão da etapa atual (concluir, devolver, pular, cancelar, triagem, aceites), rascunho e envio à triagem, duplicar briefing |
| **Aprovações** | pedidos por alçada (sequencial/paralelo), decisão com justificativa, delegação com validade, exceção de antecedência, histórico |
| **Planejamento** | projeto no GLPI (vincular ou criar) e suas tarefas, cronograma de marcos (modelo T-60 a T+15), kickoff, **RACI** com revisões, riscos, mudanças, pendências, checkpoints |
| **Checklists** | itens por etapa, críticos bloqueiam o avanço, modelo pronto, andamento por etapa |
| **Marketing** | plano de comunicação e peças, brindes, patrocinadores (receita), programação, convidados e presença, objetivos e KPIs |
| **Compras** | requisição → cotação → negociação → aprovação → contrato → pedido → recebimento → pago; propostas, contratos, aditivos, recebimentos, NF/ND; **bloqueio de contratação** no servidor |
| **Financeiro** | Budget, EAC, comprometido, realizado, saldo, desvio, receita e resultado, por categoria; aprovação do orçamento base; demonstrativo CSV |
| **Viagens** | simulador por viajante (Uber, veículo próprio, Uber + aéreo), CEP, aeroportos, estadia, parâmetros versionados, cotação Uber por trecho |
| **Logística** | materiais (envio/entrega/devolução), passagens, hospedagens, vouchers, contingência e contatos, aceites de prontidão |
| **Execução** | atividades, ocorrências com severidade, custo emergencial por exceção |
| **Encerramento** | Definition of Done, conciliação, resultados, leads, lições, satisfação, relatório final |
| **Documentos** | anexos por etapa (PDF e imagens abrem no painel lateral), pastas SharePoint 01 a 08 |
| **Linha do tempo** | Gantt por etapa com o SLO, detalhe de cada passagem e trilha de auditoria não editável |

## Regras que valem no servidor

- **Nada é contratado sem** aprovação formal de entrada, confirmação/contrato de participação, centro de custo, Budget aprovado, saldo e aprovação da alçada da compra. Tentativas ficam no histórico como *Operação bloqueada*.
- **Antecedência mínima** de 60 dias (configurável): menor exige exceção justificada e aprovada, bloqueia ou só avisa.
- **Ninguém aprova o próprio pedido**; só quem está na alçada (grupo/usuário/delegado válido, ou o direito *Aprovar* quando a alçada não tem grupo) decide.
- **Tolerância**: EAC acima do Budget aprovado (padrão 5%) ou mudança de prazo/escopo depois da aprovação abre uma *revisão* (nova versão, a anterior fica preservada).
- **Marketing** aprova peças de identidade visual e brindes; **Financeiro** (direito próprio) vê e edita valores, viagens e relatórios financeiros.
- Visibilidade por **entidade** do GLPI em listas, painel, relatórios, exportações e documentos.

## Definições financeiras

- **Budget** = orçamento aprovado (soma das linhas de despesa na aprovação); **EAC** = estimativa vigente do custo final, nunca abaixo do comprometido nem do realizado (inclui mudanças aprovadas); **Realizado** = o que foi liquidado; **Comprometido aberto** = comprometido − realizado, sem dupla contagem; **Saldo** = Budget − realizado − comprometido aberto; **Desvio** = EAC − Budget (positivo é estouro).
- Custo é sempre positivo, com natureza *despesa*. Receita realizada só conta com recebimento confirmado. Patrocinadores da aba Marketing entram como receita.
- Compras ligadas a uma linha do Budget alimentam o comprometido e o pago dela; sem linha, nasce uma linha "fora do Budget" para o estouro aparecer.

## SLOs e avisos

- **Etapas e SLOs**: grupo responsável, SLO em dias corridos, **alerta** (% do SLO), **relógio** (da ativação ou do início do evento), vale para (todos / menos agenda comercial), ativa, mais o SLO de cada aprovação.
- **Chamado por etapa** para o grupo (liga e desliga) e, com o plugin **Avisos**, sino, pop-up e canal do Teams: nova etapa, devolução, etapa pronta para concluir, perto do prazo, atraso, aprovação pendente/decidida/vencida, marco crítico vencido, prazo crítico, evento concluído ou cancelado.
- Ação automática **eventosatrasos** (de hora em hora) avisa uma vez por ocorrência.

## Painel, relatórios e dashboards

- **Painel**: oito cartões que abrem um resumo no painel lateral (em andamento, concluídos, ciclo médio × meta, etapas no SLO, aprovações pendentes, devoluções, em risco, Budget × EAC), **tempo médio por etapa × SLO** (clique na barra), **onde os eventos estão agora** (esteira), **ciclo médio por mês × meta**, **Budget × EAC × Realizado por evento**, etapas atrasadas, o que está comigo, próximos eventos e aprovações. Filtros por tipo, GP, etapa e fabricante/parceiro valem para tudo.
- **Relatórios** (CSV com os mesmos números da tela): eventos, financeiro por evento, financeiro por categoria, SLO por etapa, aprovações e tempos, viagens. **Relatório final** do evento para imprimir ou salvar em PDF.

## Integração Uber (cotação oficial, opcional)

A API de estimativa de preço da Uber (`GET /v1.2/estimates/price`) **exige aprovação da Uber**. O plugin traz o adaptador pronto, **desligado por padrão**: em *Alçadas e regras → Integração Uber* há os modos *Desligado*, *Simulado* (só para homologar a tela; rotulado "não é cotação Uber") e *Cotação oficial* (exige marcar a autorização e informar client ID/secret). OAuth 2.0 *client_credentials* com escopo `ride_request.estimate`, só no servidor; segredo e token criptografados; limite local de chamadas, tempo limite, disjuntor após falhas seguidas, erros 401/403/422/429/5xx tratados e cotações guardadas (nunca sobrescritas). Faixa nula nunca vira zero; a tarifa dinâmica não é multiplicada de novo; a cotação é instantânea e não garante o preço da data do evento.

## O que fica para as próximas fases (integrações)

TOTVS RM (centro de custo, pedidos, pagamentos), CRM (leads), criação automática de pastas no SharePoint (Microsoft Graph), Teams por webhook próprio, importação assistida das planilhas legadas e exportação XLSX. Os pontos "validar" do documento (D-01 a D-14) viraram **configuração**: alçadas, antecedência, tolerância, categorias, tarifas e SLOs.

## Dicas: "O que fazer agora"

No alto de cada evento (e como faixa em cada aba) o assistente lista, por urgência, o que **impede concluir a etapa**, o que está **urgente** (SLO estourado, aprovação vencida, saldo negativo, marco crítico vencido, evento chegando), as **atenções** e as **sugestões** da etapa (checklist, cronograma, KPIs, propostas, contingência, presença, lições…), com **quem** deve agir e o botão para a aba certa, e mostra **a etapa seguinte** e o que ela vai exigir. Liga e desliga em *Alçadas e regras → Regras gerais*; só mostra o que o perfil enxerga.

## Controle de acesso por perfil

Em **Administração → Perfis → aba Gestão de Eventos**, além do direito geral (Ler, Criar, Atualizar, Excluir, **Aprovar**, **Financeiro**, **Só envolvidos**), há uma matriz por **módulo** com *Ver* e *Editar*: Painel, Calendário, Relatórios, Exportar CSV, Dossiê PDF, Linha do tempo, Dicas, Aprovações, Decidir aprovações, Planejamento, Checklists, Marketing, Compras, Logística, Execução, Encerramento, Documentos, Financeiro, Viagens e Avisos. A aba some para quem não vê e o servidor recusa a edição sem o direito.

- **Modelos prontos**: Solicitante, Marketing, PMO/GP, Compras, Financeiro, Diretoria, Somente leitura e Administrador (um clique preenche o perfil).
- **Só envolvidos**: o perfil vê apenas eventos em que é solicitante, dono, GP, membro do grupo de alguma etapa ou aprovador (lista, painel, calendário, relatórios, documentos e abertura por URL).
- **Quem acessa o quê**: *Alçadas e regras → Acesso por perfil* mostra perfis × módulos e aplica modelos.
- Perfis que administram a configuração do GLPI acessam tudo; na atualização os módulos são derivados do direito geral que o perfil já tinha, então ninguém perde acesso.

## Avisos de contagem regressiva (pop-up e som)

- **Pop-up para cada responsável**, em qualquer tela do GLPI: "Faltam 3 dias para o evento X", com a contagem ao vivo, a etapa, aprovações pendentes e os botões **Abrir**, **Adiar** (15 min, 1 h, 4 h, amanhã) e **Dispensar**. O servidor decide quem recebe e o que está pendente; dispensar vale só para a pessoa.
- **Som** gerado no navegador (sino suave, bip duplo, sineta ou alarme), com volume, repetição até dispensar e botão de silenciar. O navegador só libera som depois de um clique na página; antes disso o pop-up avisa.
- **Regra por evento** (aba **Avisos**) ou **padrão** (*Alçadas e regras → Avisos e contagem*): momentos (`30d,14d,7d,3d,1d,2h,0`), quem recebe (dono, GP, solicitante, área da etapa, aprovadores pendentes, Marketing e pessoas extras), som, volume, repetição, pop-up, presença no Painel e aviso no sino/Teams.
- **Painel**: botão **Próximos eventos** e um pop-up com a contagem regressiva (dias, horas, minutos e segundos) de cada evento da janela configurada, com etapa, aprovação, risco e GP; abre sozinho uma vez por dia por pessoa se estiver ligado.
- A ação automática *eventosatrasos* também manda o aviso no sino e no Teams (plugin Avisos) uma vez por momento.
- O briefing só tem a data: a contagem usa a **hora de início padrão** configurada (08:00).

## Permissões (Administração → Perfis → aba Gestão de Eventos)

**Ler**, **Criar**, **Atualizar**, **Excluir permanentemente**, **Aprovar** (alçadas sem grupo) e **Financeiro**. A instalação dá *Ler* e *Criar* a todos os perfis da interface padrão e tudo a quem administra a configuração. Sem *Atualizar*, o solicitante, o dono e o GP editam o próprio evento e quem está no grupo da etapa atual a conclui ou devolve.

## Instalação

```bash
sudo cp -r gestaoeventos /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/gestaoeventos
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi gestaoeventos
sudo -u www-data php /var/www/glpi/bin/console plugin:activate gestaoeventos
```

**Depois de instalar:** crie os grupos das áreas (Marketing, Diretoria, PMO, Compras, Logística, Financeiro), escolha o grupo de cada etapa em *Etapas e SLOs*, o grupo de cada aprovador em *Alçadas e regras → Alçadas* e revise as *Tarifas de viagem*.

## Dados e desinstalação

Tabelas `glpi_plugin_gestaoeventos_eventos`, `_etapas`, `_fases`, `_historicos`, `_aprovacoes`, `_alcadas`, `_registros`, `_viajantes`, `_parametros`, `_cotacoes` e `_documentos`. **Desinstalar apaga tudo isso**; os chamados das etapas e os documentos do GLPI ficam. Não altera tabelas do núcleo do GLPI.
