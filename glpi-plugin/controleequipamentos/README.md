# Controle de Equipamentos para GLPI 11 e 12

Uma seção **Controle de Equipamentos** no menu lateral, com duas abas:

- **Entrega de equipamento**: ciclo de 8 etapas para novos colaboradores, iniciado dentro do plugin ("Iniciar ciclo"). Cada etapa tem um chamado no GLPI para o grupo da sua área, com prazo sugerido (SLO). Concluir a etapa resolve o chamado dela e abre o da próxima.
- **Devolução de equipamento**: ciclo de 6 etapas para colaboradores desligados, iniciado com o formulário de solicitação de transporte (Nº 60.13.11, rev. 02). Cada etapa tem chamado e prazo, do mesmo jeito da entrega.

Cada aba tem um painel de indicadores em texto, com tabelas acessíveis no lugar dos gráficos.

## Fluxo de entrega

| Etapa | Área | O que faz |
|---|---|---|
| 1 | RH | Abre o chamado com os dados do colaborador |
| 2 | Compras | Cotação da transportadora |
| 3 | Gestão de Custos | Aprovação e definição da transportadora. "Não" devolve para a etapa 2 |
| 4 | Faturamento | Emissão da nota fiscal |
| 5 | Compras | Solicitação do transporte e envio da documentação |
| 6 | Logística | Recebimento e envio ao escritório |
| 7 | RH | Recebimento, conferência e formulário |
| 8 | Sistemas Internos | Conferência final, baixa e configuração. Resolve o chamado |

A linha do tempo mostra cada passagem: início, conclusão, tempo contra o prazo, chamado e quem concluiu. Uma devolução (etapa 3 com "não") abre nova passagem pela etapa 2 e mantém a anterior no histórico. O motivo vai para o chamado.

## Etapas da devolução

| Etapa | Área | O que faz |
|---|---|---|
| 1 | RH | Formulário de solicitação, com materiais (é o cadastro do ciclo) |
| 2 | Compras | Transportadora e valor do frete |
| 3 | Gestão de Custos | Aprovação do custo. "Não" devolve para a etapa 2 |
| 4 | Faturamento | Nota fiscal do transporte |
| 5 | Logística | Coleta dos equipamentos |
| 6 | Sistemas Internos | Recebimento e baixa no patrimônio. Encerra o ciclo |

Estas etapas são uma proposta: ajuste em `src/FluxoDevolucao.php`.

## Prazos

Cada etapa tem um prazo sugerido em dias corridos (SLO), definido em `src/Fluxo.php`. O painel mostra as fases atrasadas agora. Os prazos são sugestão inicial: ajuste-os com o uso.

## Permissões

Um direito, `plugin_controleequipamentos`, na aba **Controle de Equipamentos** dos perfis:

- **Ler**: vê os painéis e as listas.
- **Criar**: abre fluxos de entrega e devoluções.
- **Atualizar**: avança etapas de qualquer fluxo, conclui ou cancela devoluções e configura os grupos.

Sem Atualizar, quem é do grupo da etapa atual avança essa etapa.

## Configuração

Em **Configurar → Plugins → Controle de Equipamentos**, escolha o grupo de cada área (RH, Compras, Gestão de Custos, Faturamento, Logística, Sistemas Internos).

## Instalação

```bash
sudo cp -r controleequipamentos /var/www/glpi/plugins/
sudo chown -R www-data:www-data /var/www/glpi/plugins/controleequipamentos
sudo -u www-data php /var/www/glpi/bin/console plugin:install --username=glpi controleequipamentos
sudo -u www-data php /var/www/glpi/bin/console plugin:activate controleequipamentos
```

Depois, configure os grupos em **Configurar → Plugins**.

## Dados e desinstalação

Tabelas `glpi_plugin_controleequipamentos_entregas`, `_entrega_etapas`, `_devolucoes` e `_devolucao_itens`. Ao atualizar de versões anteriores, a instalação acrescenta as colunas de visitas (`date_start`, `tickets_id`), a etapa atual e os dados da devolução, e cria a tabela `_devolucao_etapas`. **Desinstalar apaga essas tabelas.** Os chamados criados pelo fluxo ficam no GLPI.

A devolução guarda RG e CPF do colaborador, que são dados pessoais. Restrinja o direito de leitura aos perfis que precisam deles.

## Compatibilidade

Declara GLPI 11.0.0 a 12.0.99. As classes não estendem `CommonDBTM`, para evitar diferenças de tipagem de propriedades entre as duas versões.

## Licença

GPLv3+, a mesma do GLPI.
