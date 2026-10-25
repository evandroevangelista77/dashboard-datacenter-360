# Reembolso de despesas para GLPI 12

Plugin do GLPI 12.0.x para técnicos lançarem as despesas de atendimentos externos e para o gestor aprovar e registrar o pagamento. Inclui dashboard com gráficos e exportação para Excel (XLSX).

## O que tem

| Recurso | Descrição |
|---|---|
| **Solicitação de reembolso** | Formulário com técnico, centro de custo, chamado do atendimento, localização (cadastro do GLPI), local do atendimento (endereço/cidade), período e justificativa. Código automático `RB-00001`, etapas visíveis e total sempre atualizado. |
| **Despesas** | Janela de lançamento com tipo, data, descrição e valor. O tipo define os campos e mostra o limite e se exige comprovante. Alerta quando a despesa passa do limite do tipo ou cai fora do período. |
| **Quilometragem** | Para tipos "por KM", o KM rodado vem **da rota** entre origem e destino (botão *Calcular KM pela rota*, com *ida e volta*) **ou do odômetro** (KM final − KM inicial). O valor é KM × valor por KM da configuração, recalculado no servidor. Só quem aprova pode usar outro valor por KM. |
| **30 tipos de despesa** | Quilometragem, combustível, pedágio, estacionamento, café da manhã, almoço, jantar, lanche, hospedagem, lavanderia, táxi/aplicativo, transporte público, passagens rodoviária e aérea, bagagem, locação de veículo, balsa, lavagem e manutenção emergencial de veículo, material de consumo, ferramentas, peças, cabos e acessórios, frete/Correios, cópias, telefonia/internet, diária, seguro viagem, vistos e documentos, outros. Os tipos ficam numa lista editável, com limite por lançamento e exigência de comprovante. |
| **Comprovantes** | Aba *Documentos* da solicitação. Para enviar, é preciso anexar se houver despesa de tipo que exige comprovante (pode ser desligado na configuração). |
| **Fluxo** | Rascunho → **Enviar para aprovação** → **Aprovar** ou **Recusar** (com motivo; o técnico corrige e reenvia) → **Marcar como pago** (data e referência: PIX, lote, folha). O aprovador pode reabrir como rascunho. Depois de enviada, o técnico não altera mais. Todas as mudanças vão para a aba *Histórico*. |
| **Dashboard** | Filtros por período (atalhos: este mês, mês passado, 6 meses, ano), técnico, centro de custo, tipo e status. Indicadores: total, aguardando aprovação, aprovado a pagar, pago, KM rodados e média por solicitação. Gráficos: evolução mensal por status, por tipo, por técnico e por centro de custo. Detalhamento das despesas. |
| **Exportar XLSX** | Planilha com os mesmos filtros: **Resumo** (indicadores e totais por tipo, técnico, centro e mês), **Despesas** (todas, com filtro automático, moeda e total) e **Solicitações** (aprovação e pagamento). |
| **Chamado** | Aba *Reembolsos* no chamado, com os reembolsos do atendimento e o botão *Lançar reembolso deste atendimento*, que já traz o chamado, a entidade e a localização. |
| **Permissões** | *Ver os próprios*, *Criar*, *Editar*, *Excluir*, *Ver todos* (gestor, financeiro) e *Aprovar e registrar pagamento*. Quem não tem *Ver todos* só vê os próprios reembolsos, na lista e no dashboard. |

## Onde fica

Seção **Financeiro** do menu lateral, logo abaixo de PMO: *Reembolsos*, *Dashboard de reembolsos* e, para quem tem acesso, *Tipos de despesa*, *Centros de custo* e *Configuração de reembolsos*. Também há a aba **Reembolsos** em cada chamado.

## Requisitos

- GLPI **12.0.x**. O XLSX usa o PhpSpreadsheet que já vem com o GLPI, e os gráficos usam o ECharts do GLPI.
- Extensão PHP `zip`, já exigida pelo GLPI.
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

1. **Financeiro → Reembolsos → +** (ou pela aba *Reembolsos* de um chamado): preencha técnico, centro de custo, localização e período e clique em **Criar e lançar despesas**.
2. **Lançar despesa** para cada gasto. Na quilometragem, informe origem e destino e clique em **Calcular KM pela rota**, ou preencha o KM inicial e o final do odômetro.
3. Anexe os comprovantes na aba **Documentos** e clique em **Enviar para aprovação**.
4. O aprovador abre a solicitação, **aprova** ou **recusa** e, depois, **marca como pago**.
5. **Financeiro → Dashboard de reembolsos** para acompanhar e **Exportar XLSX**.

## Configuração

- **Configurar → Plugins → Reembolso de despesas**: valor por KM, rota automática, países da busca de endereços e exigência de comprovante.
- **Configurar → Listas suspensas → Tipos de despesa / Centros de custo**: tipos, limites, exigência de comprovante, centros de custo e códigos.
- **Administração → Perfis → aba Reembolsos**: permissões.

## Dados e desinstalação

Tabelas: `glpi_plugin_reembolso_reembolsos`, `_despesas`, `_tipodespesas`, `_centrocustos` e `_geocaches` (cache dos endereços já localizados). **Desinstalar apaga tudo isso**, inclusive as solicitações. Faça a exportação XLSX antes.

## Evoluções em estudo

- **Aprovador definido por centro de custo** e tela **"Minhas aprovações"**.
- **Avisos por e-mail** pelas notificações do GLPI: envio para quem aprova; aprovação, recusa e pagamento para o técnico e quem lançou.
- **Sino, pop-up e Teams** com o plugin [Avisos](../avisos): os mesmos eventos no GLPI e no canal do Teams do Financeiro.
- **Geração opcional de um chamado** para cada reembolso, para o financeiro trabalhar pela fila da Assistência.
- Pesquisa por **despesa** na lista (tipo de despesa, acima do limite).

## Versões

- **1.1.1**: os cartões do Dashboard abrem um resumo no painel lateral (solicitações por situação, aguardando há quantos dias, a pagar, pagos, KM por técnico e trajetos, média por técnico e despesas acima do limite).
- **1.1.0**: e-mails do fluxo (enviado para aprovação, aprovado, recusado e pago) em Configurar → Notificações, e avisos no sino, pop-up e Teams com o plugin Avisos.
- **1.0.1**: título e subtítulo nas telas (Reembolsos, Dashboard, Configuração e solicitação), explicando para que serve a página; atalhos para o Dashboard e Nova solicitação na lista.
- **1.0.0**: primeira versão.

## Licença

GPLv3+, a mesma do GLPI.
