# CSAT / e-NPS para GLPI 11 e 12

Duas pesquisas num só plugin, na seção **CSAT / e-NPS** do menu lateral:

- **CSAT**: pesquisa de satisfação enviada por e-mail quando um **chamado** é fechado (ou resolvido), um **projeto** é finalizado ou um **evento** ou **ciclo** é concluído.
- **e-NPS**: pesquisa de clima da equipe, em campanhas enviadas aos usuários dos grupos escolhidos, com resposta **anônima**.

## O que tem

### CSAT
- **Perguntas editáveis** (*CSAT / e-NPS → Perguntas CSAT*): texto, tipo, obrigatória ou não, ativa ou desativada e ordem. Tipos: **estrelas** (1 a 5, com carinhas), **recomendação** (0 a 10, gera o NPS), **sim ou não** e **comentário livre**.
- **Envio automático**, uma pesquisa por chamado, projeto, evento ou ciclo. Resolvido e depois fechado não gera uma segunda pesquisa.
- **Filtros de envio** (*Configuração*): entidades, categorias do chamado e tipos de solução (nada marcado = todos).
- **E-mail editável** na configuração (assunto e texto), com liga/desliga do envio e da lista de perguntas.
- **Painel CSAT**: total de respostas e % de positivas, neutras, negativas e sem resposta, nota média, NPS, tempo até responder, variação contra o período anterior, evolução por mês, origem, categoria e entidade, comentários. Cada cartão e barra abre as pesquisas num painel lateral.
- **Histórico preservado**: cada resposta guarda o texto da pergunta como foi feita.

### e-NPS
- **Campanhas** (*Campanhas e-NPS*): pergunta de 0 a 10, pergunta do comentário, grupos do GLPI e e-mails extras; rascunho, envio, lembrete para quem não respondeu e encerramento.
- **Anonimato**: a nota e o comentário são gravados sem ligação com quem respondeu; grupos com menos de 5 respostas não aparecem no painel.
- **Painel e-NPS**: eNPS, promotores, neutros e detratores, participação, distribuição das notas, evolução por campanha, eNPS por grupo e comentários anônimos.

### Relatório executivo mensal (PDF)
Pelo botão **PDF** do Painel CSAT (ou do Painel e-NPS). Sumário de uma página (situação geral, conclusões, pontos de atenção, destaques e recomendações), indicadores contra o mês anterior, evolução de 6 meses, análise por segmento, voz do cliente, e-NPS e metodologia. Usa o TCPDF que já vem com o GLPI; os gráficos são desenhados no próprio PDF.

## Como funciona

1. Um item chega ao estado final (chamado fechado, projeto finalizado, evento ou ciclo concluído) e passa pelos filtros: o plugin cria a pesquisa e envia o e-mail.
2. O destinatário do chamado é cada **solicitante** com e-mail. Para projeto, evento e ciclo, os e-mails são informados em *Configuração*.
3. O e-mail usa o modelo **Pesquisa de satisfação** do GLPI (*Configurar → Notificações*); o do e-NPS usa **Pesquisa de clima (eNPS)**.

## Instalação

Pelo pacote da release (`oak-satisfacao-completo-<versão>.zip`), no servidor do GLPI:

```bash
unzip oak-satisfacao-completo-1.1.0.zip && cd oak-satisfacao-completo
sudo bash instalar.sh
```

Ou manualmente: copie a pasta `satisfacao` para `plugins/` e, em *Configurar → Plugins*, instale e ative **CSAT / e-NPS**. Depois, em *Configurar → Notificações*, confira se os eventos estão ativos e se o SMTP está configurado.

## Permissões

- **Ler** (direito `plugin_satisfacao`): vê os painéis e baixa o relatório.
- **Atualizar**: edita as perguntas e gerencia as campanhas de e-NPS.
- Quem tem *Configuração → Atualizar* acessa a configuração.

## Pontos a confirmar

- **Acesso sem login**: a página de resposta roda dentro do GLPI. Quem não tem usuário precisa de um acesso público configurado; sem isso, cai na tela de login.
- **Destinatários do chamado**: só *solicitantes* com e-mail ativo; grupos solicitantes não recebem.
- **Desativar, não apagar**: pergunta com resposta não se apaga, só se desativa.
- **Análises do PDF**: os textos são gerados por regras a partir dos números; leia junto dos gráficos.
