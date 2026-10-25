# Avisos para GLPI 12

Central de avisos dos plugins **RH**, **PMO**, **Reembolso** e **Ciclo Financeiro**. Quem precisa agir ou acompanhar recebe o aviso de três formas, além do e-mail das notificações do GLPI:

| Onde | Como |
|---|---|
| **Sino** no topo da tela | Número de avisos não lidos e os últimos 8; clique abre o item e marca como lido. "Marcar todos como lidos" e "Ver todos os avisos". |
| **Pop-up** | Aviso novo aparece no canto da tela em até 1 minuto (configurável). Informações e sucessos somem sozinhos em 15 s; pendências e alertas (aprovar, atraso, risco) ficam até fechar. |
| **Microsoft Teams** | Cartão no canal do plugin, com título, detalhes, quem precisa agir e o botão **Abrir no GLPI**. |

A página **Meus avisos** lista tudo o que o usuário recebeu, com filtro por plugin e por não lidos. Quem fez a ação não recebe aviso dela.

## O que cada plugin avisa

| Plugin | Evento | Quem recebe |
|---|---|---|
| RH | Vaga enviada · gestor aprovou · aprovada · reprovada · cancelada · reaberta · preenchida | Solicitante (todas as etapas) e, conforme o passo, gestor, diretoria e recrutador |
| RH | Vaga aguardando aprovação | Gestor da área ou diretoria |
| RH | Candidato mudou de etapa · contratado · reprovado · desistiu | Solicitante e gestor da área |
| RH | Admissão criada | Gestor e recrutador |
| RH | Férias pedidas · aprovadas/reprovadas · canceladas | Gestor · colaborador · gestor e colaborador |
| Reembolso | Enviado para aprovação | Quem aprova reembolsos |
| Reembolso | Aprovado · recusado · pago | Técnico e quem lançou |
| PMO | Tarefa atrasada | Equipe da tarefa e gerente do projeto |
| PMO | Risco crítico | Gerente do projeto e responsável pelo risco |
| PMO | Sprint iniciada · concluída | Gerente e equipe do projeto |
| Ciclo Financeiro | Nova fase · devolução · fase atrasada | Grupo da fase, GP e OTC |
| Ciclo Financeiro | Ciclo concluído · cancelado | GP, OTC e Comercial |

## Teams

Um canal por plugin (RH, PMO, Financeiro e Ciclo Financeiro). Em **Configurar → Plugins → Avisos**:

1. No canal do Teams: **⋯ → Workflows → "Postar em um canal quando uma solicitação de webhook for recebida"** e copie a URL gerada (webhooks de entrada também funcionam).
2. Cole a URL na linha do plugin, ligue e salve.
3. Clique em **Testar**: chega um cartão de teste no canal.

Cada mensagem é enviada na hora. Se o Teams não responder, a ação automática **avisosteams** (a cada 5 minutos) tenta de novo, até 5 vezes; a tela mostra o último resultado e quantas mensagens estão na fila. O servidor do GLPI precisa acessar a internet (ou o proxy configurado no GLPI).

## Instalação

```bash
sudo cp -r avisos /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/avisos
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi avisos
sudo -u www-data php /var/www/glpi/bin/console plugin:activate avisos
```

Funciona com qualquer combinação de RH (1.0.4+), PMO (1.2.0+) e Reembolso (1.1.0+). Sem o Avisos, esses plugins continuam só com o e-mail.

## Dados e desinstalação

Tabelas `glpi_plugin_avisos_avisos`, `_canals` e `_filas`. Avisos lidos são apagados depois do prazo de retenção (180 dias por padrão). Desinstalar apaga os avisos e a configuração dos canais.

## Versões

- **1.1.0**: canal do Ciclo Financeiro; corrige o link “Ver todos os avisos” do sino.
- **1.0.0**: primeira versão.

## Licença

GPLv3+, a mesma do GLPI.
