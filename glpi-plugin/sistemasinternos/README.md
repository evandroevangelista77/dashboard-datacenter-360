# Sistemas Internos 360 para GLPI 12

O painel do departamento de Sistemas Internos dentro do GLPI: o que o Zabbix monitora nos grupos do departamento, os chamados da entidade no GLPI, o cadastro dos hosts do Zabbix e os alertas em todas as telas. Cor do departamento **#3769B2** (Sistemas da Informação), com a árvore ao fundo e a marca-d'água.

## O que tem

| Recurso | Descrição |
|---|---|
| **Visão 360** | **O mesmo dashboard do SI 360**, com o mesmo layout e todas as funções: situação geral, ação necessária, infraestrutura, **access points do Wi-Fi**, disponibilidade e tempo fora, atendimento do GLPI, prestadores, linha do tempo, **vulnerabilidades** (patches e softwares), **resumo operacional** (book mensal), **topologia** (também no menu, direto) e voz. Roda **inteiro dentro do GLPI**, sem servidor à parte: o plugin calcula tudo em PHP (`src/Si360*.php`), com a conta de serviço do Zabbix e o banco do GLPI, e serve a página pela `front/si.php`. O navegador só fala com o GLPI, com o login e os direitos do GLPI. A cor pode ser a do departamento (#3769B2, árvore e marca-d'água em azul) ou a original (verde). |
| **Resumo rápido** | Disponibilidade agora, hosts indisponíveis, problemas ativos (e os sem reconhecimento), alta e desastre, chamados em aberto, SLA vencido, vence em até 1 h e solucionados em 7 dias. Gráficos de estado dos hosts, problemas por severidade e abertos × solucionados (14 dias). Tabelas de problemas ativos (com atalho para o Zabbix), hosts indisponíveis e chamados em aberto ordenados pelo SLA. Atualiza sozinho a cada 2 minutos. |
| **Hosts do Zabbix** | Lista dos hosts dos grupos liberados para o perfil, com interface, grupos, templates, situação e problemas; busca e filtro (ativos, desativados, indisponíveis, com problema). |
| **Incluir e editar** | Nome técnico e visível, descrição, grupos (só os do perfil), interface principal (agente, SNMP v1/v2c/v3, IPMI, JMX; IP ou DNS; porta), proxy, templates (lista aprovada na configuração), tags, macros de texto e vínculo com um computador do GLPI (tag `glpi_computer`). Na edição, grupos e templates que o perfil não alcança são **mantidos**, e macros secretas ou de cofre não são tocadas. |
| **Desativar e excluir** | Desativar para a coleta sem perder o histórico. Excluir pede para digitar o nome técnico. Os dois exigem que **todos** os grupos do host sejam do perfil, para não afetar outros departamentos. |
| **Auditoria** | Quem, quando, de qual IP, o quê, o antes e o depois, inclusive as tentativas recusadas pelo Zabbix. |
| **Alertas** | Sinalizador no topo de todas as telas (contador do que pede atenção agora, piscando quando chega algo novo), **som** (5 sons gerados no navegador), **voz** em português e **pop-up no rodapé**. Cada tipo liga ou desliga cada canal: severidades do Zabbix, problema resolvido, chamado novo na entidade, SLA vence em até 1 h, SLA vencido e host alterado por outra pessoa. |
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

## Versões

- **1.2.0**: a Visão 360 passa a rodar inteira dentro do GLPI, sem o servidor do SI 360 e sem os painéis das portas 8090 e 8091 (sai o campo *Servidor do SI 360*). Resumo operacional guardado no banco do GLPI, acionamento configurável, teste de conexão compatível com o Zabbix 7.2.
- **1.1.0**: Visão 360 e Topologia com o dashboard completo do SI 360 (mapa, APs do Wi-Fi, vulnerabilidades, resumo operacional, voz, book), opção de cor (azul do departamento ou original) e correção da atualização do plugin (direitos duplicados).
- **1.0.0**: primeira versão.
