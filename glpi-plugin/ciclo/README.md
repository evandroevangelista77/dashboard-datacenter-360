# Ciclo Financeiro para GLPI 12

Acompanha cada venda do **aviso de fechamento no CRM até o recebimento dos valores** e mede quanto tempo isso leva (o "Ciclo"), fase a fase. Fica numa seção **Ciclo Financeiro** do menu lateral. Esta é a **fase 1 (piloto)**: tudo é informado no GLPI; as integrações (CRM, TOTVS RM, Power Automate, assinatura) entram depois, trocando digitação por leitura automática.

## Como funciona

1. **Abrir o Ciclo** (Comercial ou OTC): venda/projeto, cliente, nº da oportunidade no CRM, tipo de venda, valor, data do aviso de fechamento, contrato assinado, Comercial, OTC e GP. O Ciclo começa na data do aviso.
2. O Ciclo passa pelas **fases** abaixo. Cada fase tem um **grupo responsável**, um **SLO** e o que ela **exige para concluir**. Ao começar, a fase abre um **chamado para o grupo** (com o link do Ciclo) e avisa a área, o GP e o OTC; ao terminar, o chamado é resolvido.
3. Na fase atual, quem é do grupo (ou tem o direito de editar Ciclos) **conclui**, **devolve** para uma fase anterior (com motivo, contado como **retrabalho**) ou comenta. Quem edita Ciclos também **pula** uma fase, **cancela** e **reabre**.
4. O Ciclo termina quando a última parcela é recebida.

| # | Fase | Área sugerida | Vale para | SLO sugerido | Para concluir |
|---|---|---|---|---|---|
| 1 | Aviso de fechamento | Comercial | todas | 1 | (concluída ao abrir o Ciclo) |
| 2 | Validação da documentação | OTC | todas | 2 | — (incompleta: devolve ao Comercial) |
| 3 | Centro de custo | Gestão de custos | todas | 2 | nº do centro de custo |
| 4 | Divulgação do aviso | OTC | todas | 2 | — |
| 5 | Transfermeeting e espelho do pedido | GP | todas | 5 | pedido do Power Automate |
| 6 | Requisição de compra (RM) | OTC | todas | 3 | RM de compra |
| 7 | Pedido ao fornecedor | Compras | todas | 15 | pedido ao fornecedor |
| 8 | Recebimento do material | Logística | com hardware | 2 | NF de entrada conferida OK (NOK: devolve a Compras) |
| 9 | Entrada fiscal e estoque | Compras | com hardware | 3 | — |
| 10 | Separação e transporte | Logística | com hardware | 3 | transporte definido (cotação ou motorista) |
| 11 | Faturamento do hardware | Faturamento | com hardware | 2 | NF de saída do tipo Hardware |
| 12 | Entrega ao cliente | Compras | todas | 5 | data da entrega |
| 13 | Implantação | GP | com serviço | 30 | — (projeto do PMO ligado ao Ciclo) |
| 14 | Termo de aceite | GP | todas | 5 | data do aceite |
| 15 | Faturamento de serviço e licença | Faturamento | com software ou serviço | 3 | NF de saída do tipo Serviço ou Software |
| 16 | Recebimento dos valores | Faturamento | todas | 30 | todas as parcelas recebidas |

**Tipos de venda**: Hardware · Software/licença · Serviço · Hardware + serviço · Software + serviço. O tipo decide as fases (ex.: venda só de software não passa por recebimento físico, expedição nem faturamento de hardware). Trocar o tipo acerta as fases que ainda não começaram.

**Os SLOs são uma sugestão inicial.** Meça os primeiros Ciclos e ajuste em *Configurar → Plugins → Ciclo Financeiro* (o Painel mostra o tempo real de cada fase). SLO 0 = sem prazo.

## Itens da venda

Um Ciclo tem quantos itens quiser de cada tipo, com valor e datas: **pedidos do Power Automate** (aprovação), **RMs de compra**, **pedidos a fornecedores** (previsão e entrega; hardware/software), **NFs de entrada** (conferência OK/NOK), **NFs de saída** (hardware, software ou serviço) e **parcelas a receber** (vencimento e recebimento). Os PDFs (NF, termo de aceite, contrato) vão na aba *Documentos*.

## Documentos

- **No Ciclo**, o card *Documentos do Ciclo* lista cada arquivo com ícone do tipo, nome, tamanho, fase e categoria, e o botão **Abrir**. Em *Anexar arquivos* recebe vários de uma vez (arrastar e soltar, até 20 MB cada), com **categoria**, **fase** e observação. **PDF e imagens abrem num painel lateral**, sem sair da página; os demais são baixados.
- **Biblioteca** (*Ciclo Financeiro → Documentos*): todos os documentos dos Ciclos em **grade** (miniatura das fotos e uma folha com a categoria para os PDFs) ou **lista**, com **pastas** por tipo, por Ciclo, por categoria e por fase, e busca por arquivo, Ciclo ou cliente.
- Os arquivos são documentos do GLPI ligados ao Ciclo: aparecem também na aba *Documentos* e seguem os tipos de arquivo permitidos no GLPI.

## Avisos e chamados

- **Chamado por fase** para o grupo responsável (liga e desliga na configuração): a área trabalha na sua fila e recebe os e-mails de chamado do GLPI.
- Com o plugin [Avisos](../avisos) 1.1.0+: sino, pop-up e canal do Teams "Ciclo Financeiro" para a área da fase, o GP e o OTC (nova fase, devolução, atraso, Ciclo concluído ou cancelado).
- **Atraso** (ação automática *cicloatrasos*, de hora em hora): a fase que passou do SLO avisa a área, o GP e o OTC uma vez.

## Painel do Ciclo

**Cada cartão abre um resumo num painel lateral** (Ciclos em andamento com os atrasados primeiro, concluídos, ciclo médio contra a meta, % no SLO por fase, devoluções com o motivo e parcelas a receber). Indicadores (em andamento, concluídos, **ciclo médio**, **% de fases no SLO**, devoluções e **valores a receber**), **tempo médio de cada fase × SLO**, **onde os Ciclos estão agora** (uma esteira das fases com cada Ciclo e os dias na fase contra o SLO), **ciclo médio por mês contra a meta** (a soma dos SLOs), **fases atrasadas agora** e **o que está com a minha área** (ou comigo, como GP/OTC). Filtro de 3, 6, 12 ou 24 meses.

## Permissões (Administração → Perfis → aba Ciclo Financeiro)

**Ler**: vê os Ciclos e o painel. **Criar**: abre Ciclos. **Atualizar**: edita qualquer Ciclo e move as fases. Sem *Atualizar*, quem está no grupo da fase atual conclui e devolve a fase, e o GP e o OTC do Ciclo editam os dados. A instalação dá *Ler* a todos os perfis da interface padrão e tudo a quem administra a configuração.

## Instalação

```bash
sudo cp -r ciclo /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/ciclo
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi ciclo
sudo -u www-data php /var/www/glpi/bin/console plugin:activate ciclo
```

**Depois de instalar:** crie os grupos das áreas (Comercial, OTC, Gestão de custos, GP, Compras, Logística, Faturamento) com as pessoas de cada uma e escolha o grupo de cada fase em *Configurar → Plugins → Ciclo Financeiro*.

## Próximas fases (integrações)

- **TOTVS RM** (leitura): NF de entrada lançada, estoque, NF de saída e baixa do recebimento, que fecha o Ciclo sozinho.
- **CRM**: o aviso de fechamento abre o Ciclo.
- **Power Automate**: o fluxo atualiza no GLPI o pedido e a aprovação.
- **IA**: análise semanal dos Ciclos (gargalos, previsão de recebimento e sugestões), sobre o histórico de tempos que este plugin já guarda.

## Dados e desinstalação

Tabelas `glpi_plugin_ciclo_ciclos`, `_etapas`, `_fases`, `_items` e `_eventos`. **Desinstalar apaga tudo isso.** Os chamados das fases ficam no GLPI.

## Linha do tempo

No Ciclo, um **Gantt** mostra cada passagem pelas fases no tempo (verde no prazo, laranja fora do prazo, azul em andamento, vermelho atrasada ou devolvida), com o traço do SLO, a linha de hoje e um resumo (dias de ciclo, fase mais longa, tempo além do SLO e devoluções). O detalhe de cada passagem fica numa tabela recolhível.

## Versões

- **1.0.2**: clique nas barras de *Tempo médio por fase × SLO* e no *Ciclo médio por mês* abre os Ciclos no painel lateral; documentos no Ciclo (envio de vários arquivos com categoria e fase, visualização de PDF e imagens no painel lateral) e a biblioteca de documentos de todos os Ciclos.
- **1.0.1**: “Onde os Ciclos estão agora” virou uma esteira do processo (cada Ciclo na sua fase, com dias contra o SLO); gráficos com texto maior e fases alinhadas à esquerda.
- **1.0.0**: primeira versão (fase 1, piloto sem integrações).

## Licença

GPLv3+, a mesma do GLPI.
