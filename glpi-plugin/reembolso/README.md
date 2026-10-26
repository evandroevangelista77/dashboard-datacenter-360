# Reembolso de despesas para GLPI 12

Plugin do GLPI 12.0.x para técnicos lançarem as despesas de atendimentos externos e para o gestor aprovar e registrar o pagamento. Inclui dashboard com gráficos e exportação para Excel (XLSX).

## O que tem

| Recurso | Descrição |
|---|---|
| **Solicitação de reembolso** | Formulário com técnico, centro de custo, chamado do atendimento, localização (cadastro do GLPI), local do atendimento (endereço/cidade), período e justificativa. Código automático `RB-00001`, etapas visíveis e total sempre atualizado. |
| **Despesas** | Janela de lançamento com tipo, data, descrição e valor. O tipo define os campos e mostra o limite e se exige comprovante. Alerta quando a despesa passa do limite do tipo ou cai fora do período. |
| **Quilometragem** | Para tipos "por KM", o KM rodado vem **da rota** entre origem e destino (botão *Calcular KM pela rota*, com *ida e volta*) **ou do odômetro** (KM final − KM inicial). O valor é KM × valor por KM da configuração, recalculado no servidor. Só quem aprova pode usar outro valor por KM. |
| **30 tipos de despesa** | Quilometragem, combustível, pedágio, estacionamento, café da manhã, almoço, jantar, lanche, hospedagem, lavanderia, táxi/aplicativo, transporte público, passagens rodoviária e aérea, bagagem, locação de veículo, balsa, lavagem e manutenção emergencial de veículo, material de consumo, ferramentas, peças, cabos e acessórios, frete/Correios, cópias, telefonia/internet, diária, seguro viagem, vistos e documentos, outros. Os tipos ficam numa lista editável, com limite por lançamento e exigência de comprovante. |
| **Comprovantes** | Cartão *Comprovantes* na própria solicitação: cada arquivo com ícone do tipo, nome, tamanho e **Abrir** (PDF e fotos de cupom abrem num painel lateral) e o envio em *Anexar arquivos* (vários de uma vez, até 20 MB cada, com categoria). A página *Reembolso → Comprovantes* reúne os comprovantes de todas as solicitações em grade ou lista, com pastas por tipo, solicitação e categoria. Para enviar a solicitação, é preciso anexar se houver despesa de tipo que exige comprovante (pode ser desligado na configuração). |
| **Horário e adicional noturno** | Toda despesa tem data **e horário**. Horário na faixa configurada (padrão 22:00 às 05:00) marca a despesa como **adicional noturno**: ela aparece destacada na solicitação, no lote e no PDF, com o total noturno. O valor não muda. |
| **Horas do atendimento** | Com o início e o fim do atendimento (data e hora, inclusive retroativos), a solicitação mostra selos com as **horas comerciais** (seg. a sex., padrão 08:00 às 18:00), as **fora do horário comercial** (inclui fim de semana) e as de **adicional noturno**, recalculados enquanto se digita. A lista de reembolsos ganha as três colunas. |
| **Lote por técnico** | No fim de cada período (**diário, semanal, quinzenal ou mensal**, por técnico), as solicitações do técnico viram um **lote** com um **PDF** único. Ele recebe o PDF por e-mail e **assina no LibreSign** (plugin Assinatura); sem LibreSign, confere e aprova no GLPI. O botão *Gerar o lote agora* não espera o fim do período. Solicitação sem centro de custo, sem horário ou sem o comprovante exigido fica de fora, e o técnico é avisado. |
| **Aprovação do lote** | Técnico assina → **gestor da área** (o *Responsável* do usuário no GLPI ou o definido na configuração) → **gestor de cada centro de custo** (cadastro do centro) → **OTC** (grupo) → **financeiro** (grupo), que paga e **anexa o comprovante**. Qualquer etapa devolve ao técnico com o motivo; ele corrige e reenvia, e um novo PDF é gerado. Cada etapa aceita um **usuário e/ou um grupo do GLPI** (todos os membros podem decidir), configurados numa esteira em *Configuração de reembolsos*, com o gestor de cada centro de custo ali mesmo. Etapa sem ninguém definido fica com quem tem o direito *Aprovar*. |
| **Adiantamento** | Pedido de dinheiro **antes** do atendimento (*Reembolso → Adiantamentos*), com técnico, centro de custo, chamado, local, início e fim previstos (com as horas comerciais, fora do horário e de adicional noturno) e as **despesas previstas** (tipo, quantidade e valor; quilometragem usa o valor por KM). Segue **as mesmas regras do lote**: PDF com termo de responsabilidade assinado no LibreSign, gestor da área, gestor do centro de custo, OTC e financeiro, que paga com o comprovante; devolução com motivo e reenvio. Depois do atendimento o técnico faz a **prestação de contas** (gasto real e comprovantes, no prazo configurado) e o financeiro confere e **acerta o saldo** (técnico devolve ou a empresa complementa). Avisos e e-mails em cada etapa, com *finalizado com sucesso* e *OK final* no encerramento. |
| **PDF profissional** | PDFs do lote e do adiantamento com cabeçalho e rodapé em todas as páginas (*Página x de y*), indicadores, seções numeradas, tabela de atendimentos com chamado, data e hora, horas e valor, **fluxo de aprovação** com quem responde por cada etapa e o que já foi decidido, e o quadro reservado para a assinatura do LibreSign. |
| **Avisos do lote** | E-mail (Configurar → Notificações) e, com o plugin Avisos, sino, pop-up e Teams a cada etapa. No pagamento, todos os envolvidos recebem *Reembolso LT-xxxxx finalizado com sucesso* e o técnico recebe o **OK final**. |
| **Dashboard** | Filtros por período (atalhos: este mês, mês passado, 6 meses, ano), técnico, centro de custo, tipo e status. Indicadores: total, aguardando aprovação, aprovado a pagar, pago, KM rodados e média por solicitação. Gráficos: evolução mensal por status, por tipo, por técnico e por centro de custo. Detalhamento das despesas. |
| **Exportar XLSX** | Planilha com os mesmos filtros: **Resumo** (indicadores e totais por tipo, técnico, centro e mês), **Despesas** (todas, com filtro automático, moeda e total) e **Solicitações** (aprovação e pagamento). |
| **Chamado** | Aba *Reembolsos* no chamado, com os reembolsos do atendimento e o botão *Lançar reembolso deste atendimento*, que já traz o chamado, a entidade e a localização. |
| **Permissões** | *Ver os próprios*, *Criar*, *Editar*, *Excluir*, *Ver todos* (gestor, financeiro) e *Aprovar e registrar pagamento*. Quem não tem *Ver todos* só vê os próprios reembolsos, na lista e no dashboard. |

## Onde fica

Seção **Reembolso** do menu lateral, logo abaixo de PMO: *Reembolsos*, *Adiantamentos*, *Lotes de reembolso*, *Dashboard de reembolsos* e, para quem tem acesso, *Tipos de despesa*, *Centros de custo* e *Configuração de reembolsos*. Também há a aba **Reembolsos** em cada chamado.

## Requisitos

- GLPI **12.0.x**. O XLSX usa o PhpSpreadsheet que já vem com o GLPI, e os gráficos usam o ECharts do GLPI.
- Extensão PHP `zip`, já exigida pelo GLPI. O PDF do lote usa o TCPDF que já vem com o GLPI.
- Para a assinatura do técnico: plugin **Assinatura digital (LibreSign)** configurado e o técnico com e-mail no GLPI. Sem ele, o técnico aprova o lote no próprio GLPI.
- Para a rota automática: o servidor do GLPI precisa acessar a internet (serviços públicos do OpenStreetMap: Nominatim e OSRM; o proxy do GLPI é respeitado). Sem internet, o KM vem do odômetro.

## Instalação

```bash
sudo cp -r reembolso /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/reembolso
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi reembolso
sudo -u www-data php /var/www/glpi/bin/console plugin:activate reembolso
```

Ou pela interface: **Configurar → Plugins → Reembolso de despesas → Instalar e Ativar**.

A instalação cria os 30 tipos de despesa, 5 centros de custo de exemplo, o valor por KM (R$ 1,20) e os direitos:
- perfis da interface padrão: *ver os próprios, criar e editar*;
- perfis que administram a configuração: tudo, inclusive *ver todos* e *aprovar*.

## Uso

1. **Reembolso → Reembolsos → +** (ou pela aba *Reembolsos* de um chamado): preencha técnico, centro de custo, localização e período e clique em **Criar e lançar despesas**.
2. **Lançar despesa** para cada gasto, com **data e horário**. Na quilometragem, informe origem e destino e clique em **Calcular KM pela rota**, ou preencha o KM inicial e o final do odômetro.
3. Anexe os comprovantes no cartão **Comprovantes**. No fim do período, a solicitação entra no **lote** do técnico (ou clique em **Gerar o lote agora**).
4. O técnico confere o PDF e assina no LibreSign; depois aprovam o gestor da área, o gestor do centro de custo e o OTC, e o financeiro paga anexando o comprovante (**Reembolso → Lotes de reembolso**, com o painel *Esperando você*).
5. **Adiantamento**: em **Reembolso → Adiantamentos → Novo adiantamento**, preencha o atendimento, lance as despesas previstas e **Envie para aprovação**. Depois do pagamento, informe o gasto e anexe os comprovantes em **Enviar a prestação de contas**; o financeiro confere e encerra.
6. **Reembolso → Dashboard de reembolsos** para acompanhar e **Exportar XLSX**.

## Configuração

- **Configurar → Plugins → Reembolso de despesas**: valor por KM, rota automática, países da busca de endereços e exigência de comprovante.
- **Configurar → Listas suspensas → Tipos de despesa / Centros de custo**: tipos, limites, exigência de comprovante, centros de custo e códigos.
- **Administração → Perfis → aba Reembolsos**: permissões.

## Dados e desinstalação

Tabelas: `glpi_plugin_reembolso_reembolsos`, `_despesas`, `_tipodespesas`, `_centrocustos`, `_lotes`, `_lotes_etapas`, `_tecnicos`, `_adiantamentos`, `_adiantamentos_itens`, `_adiantamentos_etapas` e `_geocaches` (cache dos endereços já localizados). **Desinstalar apaga tudo isso**, inclusive as solicitações. Faça a exportação XLSX antes.

## Evoluções em estudo

- **Aprovador definido por centro de custo** e tela **"Minhas aprovações"**.
- **Avisos por e-mail** pelas notificações do GLPI: envio para quem aprova; aprovação, recusa e pagamento para o técnico e quem lançou.
- **Sino, pop-up e Teams** com o plugin [Avisos](../avisos): os mesmos eventos no GLPI e no canal do Teams do Reembolso.
- **Geração opcional de um chamado** para cada reembolso, para o financeiro trabalhar pela fila da Assistência.
- Pesquisa por **despesa** na lista (tipo de despesa, acima do limite).

## Versões

- **1.3.0**: **adiantamento** com as mesmas regras do reembolso (despesas previstas, PDF com termo de responsabilidade e assinatura no LibreSign, esteira de aprovação, pagamento com comprovante, prestação de contas e acerto do saldo, avisos em cada etapa); **PDF do lote redesenhado** (cabeçalho e rodapé, indicadores, seções numeradas, fluxo de aprovação e quadro da assinatura); campos de data e hora travados legíveis em qualquer tema; prazo da prestação de contas na configuração.
- **1.2.0**: horário nas despesas e **adicional noturno** (faixa configurável, despesa destacada); **lote por técnico** (diário, semanal, quinzenal ou mensal) com **PDF** para conferência e **assinatura no LibreSign**; aprovação em cadeia (técnico, gestor da área, gestor do centro de custo, OTC e financeiro com comprovante); devolução com motivo e reenvio; avisos e pop-up de reembolso finalizado. O envio avulso de cada solicitação deu lugar ao lote.
- **1.1.3**: gráficos de barras no modelo comum, sem a rosca (clique na barra ou no mês abre as despesas no painel lateral), esteira *Onde os reembolsos estão agora* (rascunho, aprovação e pagamento, com prazos de referência de 5, 3 e 5 dias); cartão de comprovantes na solicitação (lista compacta com visualização no painel lateral e envio de vários arquivos) e a página *Comprovantes*; gráficos do Dashboard no mesmo estilo dos outros plugins (degradê, total do mês e média, rosca com o total, valor e % do total nas barras, dicas detalhadas).
- **1.1.2**: a seção do menu lateral passa a se chamar **Reembolso** (antes Financeiro).
- **1.1.1**: os cartões do Dashboard abrem um resumo no painel lateral (solicitações por situação, aguardando há quantos dias, a pagar, pagos, KM por técnico e trajetos, média por técnico e despesas acima do limite).
- **1.1.0**: e-mails do fluxo (enviado para aprovação, aprovado, recusado e pago) em Configurar → Notificações, e avisos no sino, pop-up e Teams com o plugin Avisos.
- **1.0.1**: título e subtítulo nas telas (Reembolsos, Dashboard, Configuração e solicitação), explicando para que serve a página; atalhos para o Dashboard e Nova solicitação na lista.
- **1.0.0**: primeira versão.

## Licença

GPLv3+, a mesma do GLPI.
