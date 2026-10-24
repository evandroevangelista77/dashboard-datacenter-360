# PMO para GLPI 12

Plugin do GLPI 12.0.x para gerenciar projetos ágeis (Scrum e Kanban), waterfall e híbridos usando os **Projetos e Tarefas nativos** do GLPI. Cada tarefa do projeto vira um cartão do quadro, e o plugin guarda só o que o GLPI não tem: coluna, ordem, sprint, story points e prioridade. Nada é duplicado. Se o plugin for desinstalado, os projetos e as tarefas continuam intactos.

## O que tem

| Recurso | Descrição |
|---|---|
| **Gantt** | EAP em árvore com numeração (1, 1.1, 1.2…), fases recolhíveis, barras coloridas pela situação (concluída, em andamento, não iniciada, atrasada) com o % concluído, marcos, dependências (TI, II, TT, IT), linha de hoje e zoom por dia, semana ou mês. Arrastar a barra muda as datas, e a borda direita muda só o fim. Uma divisória ajusta a largura da lista. Aparece **logo abaixo do formulário do projeto**, na aba **Gantt** e em tela cheia. Funciona mesmo sem ativar o PMO no projeto. |
| **Quadro Kanban** (estilo Jira) | Colunas configuráveis com cor, categoria (a fazer, em andamento, concluído), limite de WIP, % e estado da tarefa ao entrar. Arrastar e soltar, criação rápida na coluna, filtros por pessoa, tipo e texto, "só os meus" e raias por fase/épico ou por responsável. Mover para "Concluído" põe a tarefa em 100% e grava a data real de fim. |
| **Backlog e sprints** | Backlog priorizado por arrastar. Sprints com meta e datas: planejar (arrastar itens), iniciar e concluir. No encerramento, as pendentes voltam ao backlog ou vão para outra sprint. No Scrum, o quadro mostra só a sprint ativa. |
| **Painéis do projeto** | Saúde (semáforo), % real × % planejado, índice de prazo (SPI), burndown da sprint, burnup com previsão de término pelo ritmo, curva S, fluxo cumulativo (CFD), velocidade, cartões por coluna, carga da equipe por semana e listas de atrasadas e das que vencem em 7 dias. |
| **Portfólio do PMO** | Todos os projetos com saúde, progresso real × planejado, prazo, tarefas atrasadas, riscos e os campos do bloco "Dados do projeto" (plugin Fields, se houver). Inclui filtros, ordenação e exportação para CSV. |
| **Meu trabalho** | As tarefas abertas do usuário em todos os projetos, agrupadas pelo prazo. |
| **Documentação** | Páginas em texto rico com modelos: Termo de Abertura (TAP), Escopo/EAP, Matriz RACI, Plano de Comunicação, Ata de Reunião, Status Report, Lições Aprendidas e Retrospectiva. Também lista os arquivos anexados ao projeto. |
| **Riscos e pendências** | Registro com probabilidade × impacto (matriz 5×5), nível, responsável, prazo e plano de resposta. |

Metodologias: **Scrum**, **Kanban**, **Waterfall** e **Híbrido** (cronograma no Gantt e execução em sprints). Cada uma cria um modelo de colunas, que pode ser mudado depois.

## Requisitos

- GLPI **12.0.x**. A instalação é bloqueada em outras versões.
- Nenhuma extensão PHP além das que o GLPI já exige. Os gráficos usam o ECharts que vem com o GLPI, sem CDN nem internet.
- Opcionais, detectados sozinhos:
  - plugin **Fields** com um bloco chamado **"Dados do projeto"**: o portfólio mostra Cliente, Classificação, Líder Técnico, Ponto de Atenção, Status Atual, Prazo EAC e Previsão Conclusão GP;
  - plugin **Cronograma (MS Project)**: um projeto importado do Project já abre como Waterfall, com Gantt e dependências.

## Instalação

### Com o pacote da release (recomendado)

Baixe `oak-pmo-completo-<versão>.zip` em [Releases](https://github.com/evandroevangelista77/dashboard-datacenter-360/releases) e, no servidor do GLPI:

```bash
unzip oak-pmo-completo-1.0.0.zip
cd oak-pmo-completo
sudo bash instalar.sh
# GLPI em outro caminho ou outro administrador:
# sudo GLPI_DIR=/caminho/do/glpi GLPI_ADMIN=usuario bash instalar.sh
```

O script confere a versão do GLPI, copia o plugin, acerta o dono dos arquivos, instala, ativa e limpa o cache. Para atualizar, rode o mesmo script com o pacote novo; os dados do PMO são mantidos.

### Manual

```bash
sudo cp -r pmo /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/pmo
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi pmo
sudo -u www-data php /var/www/glpi/bin/console plugin:activate pmo
```

Ou pela interface: **Configurar → Plugins → PMO → Instalar** e depois **Ativar**.

A pasta precisa se chamar `pmo`, porque é o nome técnico do plugin.

## Primeiros passos

1. **Permissões.** A instalação dá ao direito **PMO** a *leitura* para os perfis da interface padrão e *leitura + atualização* para os perfis que já editam projetos. Para ajustar: **Administração → Perfis → (perfil) → aba PMO**. Quem já estava logado vê o menu depois de sair e entrar de novo.
2. Abra um **Projeto** (Ferramentas → Projetos). O **Gantt das atividades** aparece abaixo do formulário.
3. Na aba **PMO** do projeto, clique em **Ativar o PMO neste projeto** e escolha a metodologia, a estimativa (story points ou horas) e a duração da sprint.
4. Use as abas do PMO: **Quadro, Backlog (e sprints), Gantt, Painéis, Documentação, Riscos e Configuração**.
5. No menu lateral, a seção **PMO** tem o **Portfólio de projetos** e o **Meu trabalho**.

### Dicas

- **Scrum:** crie a sprint no Backlog, arraste os itens para ela e clique em **Iniciar sprint**. O quadro passa a mostrar a sprint. Ao final, **Concluir sprint**.
- **Waterfall:** importe o cronograma do MS Project (plugin Cronograma) ou crie as tarefas no GLPI. As tarefas com subtarefas viram fases no Gantt e rótulos nos cartões.
- **Colunas:** em **Configuração**, mude nomes, cores, WIP, o % e o estado aplicados ao entrar na coluna. Também dá para recriar as colunas pelos modelos Scrum, Kanban ou Waterfall.
- **Cartão:** clique para editar título, tipo, prioridade, pontos, datas, horas, sprint e responsáveis. O botão **Abrir tarefa completa** leva ao formulário nativo.
- **Histórico:** o fluxo cumulativo e a curva S real dependem de uma fotografia diária. Ela é gravada a cada mudança no quadro e pela ação automática **pmosnapshot** (uma vez por dia; Configurar → Ações automáticas). Os gráficos ganham forma com alguns dias de uso.

## Como os números são calculados

- **% real**: em cronogramas com datas (waterfall), é a média do % das tarefas ponderada pela duração planejada. Em projetos ágeis sem datas, é o que foi concluído (pontos, horas ou quantidade de cartões) dividido pelo total.
- **% planejado**: quanto do trabalho já deveria estar feito hoje, pelas datas planejadas das tarefas (ou do projeto, se as tarefas não tiverem datas).
- **Saúde**:
  - **vermelho** quando o prazo do projeto venceu ou o real está mais de 15 pontos percentuais abaixo do planejado;
  - **amarelo** entre 5 e 15 pontos percentuais abaixo, ou com tarefas atrasadas;
  - **verde** no prazo.
- **Burndown e burnup** usam a data em que cada cartão foi concluído. Se ninguém estimou pontos, contam cartões.
- **Carga da equipe**: o que falta das tarefas abertas (duração planejada × % restante), dividido entre os responsáveis e pelos dias úteis do período. Sem duração planejada, conta 8 h por dia útil. A referência é 40 h por semana.

## Dados e desinstalação

Tabelas criadas: `glpi_plugin_pmo_projects`, `_columns`, `_items`, `_sprints`, `_snapshots`, `_pages` e `_risks`. Também são criados o direito `plugin_pmo`, a ação automática `pmosnapshot` e, se faltarem, os tipos de tarefa *História*, *Tarefa*, *Bug* e *Melhoria*.

**Desinstalar** apaga só esses dados do PMO. Projetos, tarefas, dependências e equipes do GLPI ficam. Para tirar o PMO de um único projeto, use **Configuração → Desativar PMO no projeto**.

## Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| O menu PMO não aparece | O perfil não tem o direito PMO (Administração → Perfis → aba PMO), ou a sessão é anterior à instalação: saia e entre de novo. |
| "Versão do GLPI incompatível" | O plugin é para GLPI 12.0.x. |
| Gráficos ou quadro em branco depois de atualizar | Cache do navegador: recarregue com Ctrl+Shift+R (Cmd+Shift+R no Mac). Os arquivos do plugin já são versionados pela data de modificação. |
| Não consigo arrastar cartões | O usuário tem só leitura: é preciso o direito PMO "Atualizar" e poder editar o projeto ou as tarefas. |
| Curva S ou CFD vazios | Ainda não há dias suficientes de fotografias. Confira se a ação automática *pmosnapshot* está ativa. |

## Licença

[GNU GPL versão 3 ou posterior](../../LICENSE) (GPLv3+), a mesma do GLPI.
