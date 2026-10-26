# RH para GLPI 12

Plugin do GLPI 12.0.x com o processo de **recrutamento e seleção**: requisição de vaga com aprovação em dois níveis, banco de candidatos e funil visual. Também traz **admissão** (onboarding), **férias**, **Minhas aprovações**, **avisos por e-mail** e **dashboard de RH**. Fica numa seção **RH** do menu lateral.

## O que tem

| Recurso | Descrição |
|---|---|
| **Requisição de vaga** | Cargo, departamento, nº de posições, contratação (CLT, PJ, estágio, temporário, aprendiz), modalidade (presencial, híbrido, remoto), local, faixa salarial, motivo (aumento de quadro ou substituição), gestor, recrutador, descrição, requisitos, benefícios, prazo e prioridade. |
| **Aprovação em dois níveis** | Rascunho → **gestor da área** (o indicado na vaga ou o gestor do departamento) → **diretoria** (quem tem o direito *Aprovar como diretoria*) → aberta. Reprovar exige motivo e devolve ao solicitante, que ajusta e reenvia. Se quem pede é o próprio gestor, a 1ª etapa já fica aprovada. |
| **Processo visual** | A vaga mostra uma **linha do tempo**: requisição → enviada → gestor → diretoria → seleção → preenchida, com quem decidiu, quando e o comentário. Tudo também fica na aba *Histórico*. |
| **Minhas aprovações** | Tela com o que espera a decisão do usuário: vagas (como gestor ou diretoria) e férias da equipe. Tem aprovar e reprovar ali mesmo, e o número de pendências aparece no menu. |
| **Avisos por e-mail** | Pelas notificações do GLPI: vaga para o gestor, depois para a diretoria, resultado para o solicitante, gestor e recrutador; pedido de férias para o gestor, decisão para o colaborador. Modelos editáveis em *Configurar → Notificações*. |
| **Sino, pop-up e Teams** | Com o plugin [Avisos](../avisos), o solicitante acompanha cada etapa da vaga (envio, aprovações, candidatos mudando de etapa, contratação e encerramento), o gestor recebe o que precisa aprovar e as férias avisam gestor e colaborador, no sino, em pop-up e no canal do Teams do RH. |
| **Cargos com job description** | Cada cargo tem CBO e um job description em texto rico: identificação, missão, responsabilidades, requisitos, competências, indicadores, relacionamentos e condições de trabalho. Os 15 cargos de exemplo vêm com modelos prontos, e cargo novo abre com a estrutura em branco. Ao escolher o cargo numa vaga nova, a **descrição e os requisitos já vêm preenchidos** com o job description, para editar e acrescentar o que for da vaga; o job description completo também aparece na requisição para quem aprova e para o recrutador. |
| **Banco de Currículo** | Filtros no topo da lista: busca por nome, e-mail ou telefone, cargo pretendido, cidade, origem, faixa de pretensão salarial e situação (em processo seletivo, sem processo em andamento, já contratado). Nome, foto (JPG, PNG ou WebP, recortada em quadrado), contato, LinkedIn, cidade, origem, **cargo pretendido** e pretensão salarial; **currículo** enviado no próprio cadastro (PDF, Word, ODT ou RTF, guardado como documento do GLPI e listado com download); processos de que o candidato participou. |
| **Funil de seleção (Kanban)** | Por vaga: triagem → entrevista RH → teste técnico → entrevista com gestor → proposta → contratado. Os candidatos são **arrastados** entre etapas. Cada um tem nota (estrelas), parecer, entrevista agendada, proposta (salário e início), dias na etapa e histórico de cada passo. Dá para reprovar ou registrar desistência com motivo, e adicionar candidatos do banco ou novos. |
| **Contratação** | Levar o candidato para *Contratado* cria a **admissão**. Quando todas as posições são preenchidas, a vaga é encerrada sozinha. |
| **Admissão (onboarding)** | Checklist por área (RH, TI, gestor, facilities) com 15 itens padrão e itens extras, com progresso, quem marcou e quando. O botão **Abrir chamado de TI** cria a requisição no GLPI com os itens de TI. |
| **Férias** | Pedido com período, dias corridos e abono (até 10 dias). O gestor padrão é o *Responsável* do usuário no GLPI. Regras: mínimo de 5 dias, até 30 com o abono, sem sobreposição. O gestor vê quem da equipe estará fora no mesmo período e aprova ou reprova. |
| **Calendário de férias** | Visão **mensal** (um dia por coluna, fins de semana destacados) e **anual** (os 12 meses numa tela, resumo de pessoas fora e dias por mês, mês mais cheio e linha de hoje). Barras de férias aprovadas e aguardando, com o período ao passar o mouse; clique abre o pedido. |
| **Documentos** | Cartão de documentos na lateral da **vaga** (requisição assinada, organograma, anúncio…) e da **admissão** (RG, CPF, ASO, contrato…): cada arquivo com ícone do tipo, nome, tamanho e **Abrir**. PDF e imagens abrem num painel lateral, sem sair da página. O envio fica em *Anexar arquivos* (vários de uma vez, até 20 MB cada, com categoria e observação). A página *RH → Documentos* reúne os documentos de vagas, candidatos (currículos) e admissões em **grade** ou **lista**, com pastas por tipo, origem, item e categoria, e busca. |
| **Dashboard RH** | Indicadores: vagas abertas, aguardando aprovação, candidatos em processo, contratações, tempo médio de contratação, admissões em andamento e férias. Gráficos: funil de conversão (vagas abertas, todas ou uma vaga), ranking da origem dos candidatos, contratações e novos candidatos por mês, vagas por situação e vagas abertas por departamento, com detalhes ao passar o mouse (quem foi contratado, quais vagas). |

## Permissões (Administração → Perfis → aba RH)

| Perfil típico | Recrutamento | Férias |
|---|---|---|
| Colaborador ou gestor | *Ver as próprias* + *Solicitar vaga* | *Ver as próprias e da equipe* + *Pedir férias* |
| RH | + *Conduzir seleção* + *Ver todas e o banco de candidatos* | + *Ver todas* + *Aprovar de qualquer colaborador* |
| Diretoria | + *Aprovar como diretoria* | — |

A instalação dá o perfil de colaborador a todos os perfis da interface padrão, e tudo a quem administra a configuração. O gestor da área aprova a 1ª etapa das vagas do seu departamento, e o *Responsável* do usuário aprova as férias dele, sem precisar de direito extra.

## Requisitos

GLPI 12.0.x com a extensão PHP **gd** (fotos dos candidatos; o GLPI já a exige). A **exif** é opcional e corrige fotos de celular deitadas.

## Instalação

```bash
sudo cp -r rh /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/rh
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi rh
sudo -u www-data php /var/www/glpi/bin/console plugin:activate rh
```

A instalação cria 8 departamentos e 15 cargos de exemplo, com job description e CBO (nos códigos confirmados; os demais ficam "a confirmar" em cbo.mte.gov.br), os direitos e as 6 notificações.

**Depois de instalar:**
1. Em *Configurar → Listas suspensas → Departamentos*, informe o **gestor** de cada departamento.
2. No cadastro dos usuários, preencha o **Responsável**, que é quem aprova as férias.
3. Para os e-mails, ative as notificações do GLPI e configure o SMTP.

## Uso

1. **RH → Vagas abertas → +**: preencha a requisição, salve e clique em **Enviar para aprovação**.
2. O gestor e depois a diretoria aprovam em **RH → Minhas aprovações** (e recebem o e-mail).
3. Com a vaga aberta, **Abrir funil de seleção**: adicione candidatos e arraste-os pelas etapas até *Contratado*.
4. **RH → Admissões**: acompanhe o checklist e abra o chamado de TI.
5. **RH → Banco de Currículo**: cadastre candidatos com foto, cargo pretendido e currículo.
6. **RH → Férias → +** para pedir; **RH → Calendário de férias** para ver a equipe por mês ou no ano.

## Dados e desinstalação

Tabelas: `glpi_plugin_rh_vagas`, `_candidatos`, `_candidaturas`, `_admissaos`, `_ferias`, `_departamentos`, `_cargos`, `_aprovacoes`, `_historicos` e `_checklists`. **Desinstalar apaga tudo isso**, incluindo as notificações do plugin. Os chamados de TI abertos pelas admissões ficam no GLPI.

## Versões

- **1.0.7**: filtros no Banco de Currículo (nome, e-mail ou telefone, cargo pretendido, cidade, origem, faixa de pretensão salarial e situação: em processo seletivo, sem processo em andamento ou já contratado), com atalhos e o botão para limpar.
- **1.0.6**: gráficos de barras no modelo comum (clique abre as vagas no painel lateral), esteira *Onde as vagas estão agora* (requisição, aprovações e seleção, com os dias em cada etapa contra o prazo de referência); documentos na vaga e na admissão (lista compacta com visualização de PDF e imagens no painel lateral e envio de vários arquivos) e a biblioteca *RH → Documentos*.
- **1.0.5**: os cartões do Dashboard RH abrem um resumo no painel lateral (vagas abertas, aprovações paradas, candidatos por etapa, contratações, tempo de contratação, admissões e férias).
- **1.0.4**: avisos de cada etapa para o solicitante (envio, aprovações, andamento dos candidatos, contratação, encerramento) e das férias, no sino, pop-up e Teams com o plugin Avisos; novos e-mails “Andamento da vaga” e “Férias canceladas”.
- **1.0.3**: cargo pretendido no banco de currículo (campo, coluna da lista e filtro de busca); envio do currículo no cadastro do candidato; vaga nova preenchida com o job description do cargo; calendário de férias com visão anual.
- **1.0.2**: foto do candidato.
- **1.0.1**: CBO e job description no cadastro de cargos, com modelos; job description exibido na vaga.
- **1.0.0**: primeira versão.

## Licença

GPLv3+, a mesma do GLPI.
