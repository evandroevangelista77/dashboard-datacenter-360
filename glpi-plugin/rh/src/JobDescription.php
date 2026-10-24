<?php

namespace GlpiPlugin\Rh;

/**
 * Modelos de job description dos cargos (missão, responsabilidades, requisitos,
 * competências, indicadores, relacionamentos e condições de trabalho).
 * CBO só onde o código é conhecido; nos demais, confirmar em cbo.mte.gov.br.
 */
class JobDescription
{
    /** Cargo => [CBO, nível, missão, responsabilidades, formação/experiência, conhecimentos, competências, indicadores, reporta a, interage com, condições] */
    private const ROLES = [
        'Analista de Suporte N1' => ['3172-10', 'Júnior',
            'Ser o primeiro ponto de contato dos usuários, resolvendo incidentes e requisições de baixa complexidade com rapidez e boa comunicação, e encaminhando corretamente o que exigir outro nível.',
            ['Registrar, classificar e priorizar chamados no GLPI', 'Resolver incidentes de primeiro nível (acesso, senha, e-mail, impressão, estação de trabalho)', 'Executar procedimentos da base de conhecimento e manter os registros atualizados', 'Escalar para o N2 com diagnóstico inicial e evidências', 'Cumprir os SLAs de primeiro atendimento e de solução', 'Comunicar o usuário sobre o andamento até o encerramento'],
            ['Ensino técnico ou superior em andamento em TI', 'Até 1 ano de experiência em service desk (desejável)'],
            ['Windows e Office 365', 'Active Directory (reset de senha, grupos)', 'Noções de redes (IP, DNS, DHCP)', 'Ferramenta de chamados (GLPI) e ITIL básico'],
            ['Cordialidade e empatia com o usuário', 'Comunicação clara, oral e escrita', 'Organização e disciplina no registro', 'Vontade de aprender'],
            ['% de resolução no primeiro contato', 'Tempo médio de atendimento (TMA)', 'Cumprimento de SLA', 'Satisfação do usuário (CSAT)'],
            'Coordenador de TI', 'Usuários, Suporte N2, Infraestrutura', 'Presencial ou híbrido; escala que pode incluir sábados conforme a operação.'],
        'Analista de Suporte N2' => ['3172-10', 'Pleno',
            'Resolver incidentes e requisições de complexidade intermediária, atuar na causa dos problemas recorrentes e apoiar o N1 com conhecimento técnico e documentação.',
            ['Tratar chamados escalados pelo N1 e cumprir os SLAs de solução', 'Diagnosticar falhas em estações, servidores de arquivos, impressão e rede local', 'Administrar contas, grupos e políticas no Active Directory e no Microsoft 365', 'Criar e revisar artigos da base de conhecimento', 'Abrir e acompanhar problemas (análise de causa raiz) com a Infraestrutura', 'Atender clientes remotamente e, quando necessário, em campo'],
            ['Superior completo ou em andamento em TI', '2 a 4 anos em suporte técnico'],
            ['Windows Server, AD, GPO e Microsoft 365', 'Redes (VLAN, roteamento básico, Wi-Fi)', 'Linux básico', 'Monitoramento (Zabbix) e GLPI', 'ITIL v4 Foundation (desejável)'],
            ['Raciocínio analítico', 'Autonomia e senso de dono', 'Comunicação com usuários e equipe técnica', 'Trabalho sob pressão'],
            ['Cumprimento de SLA de solução', 'Backlog por analista', 'Reabertura de chamados', 'Artigos publicados na base'],
            'Coordenador de TI', 'Suporte N1, Infraestrutura, Redes, clientes', 'Híbrido; sobreaviso em escala.'],
        'Analista de Infraestrutura' => ['2124-20', 'Pleno',
            'Garantir a disponibilidade, o desempenho e a segurança dos servidores, da virtualização e dos serviços de datacenter, com automação e documentação.',
            ['Administrar servidores Linux e Windows, virtualização e storage', 'Manter backup e restauração testados (políticas e evidências)', 'Monitorar capacidade e disponibilidade (Zabbix) e agir proativamente', 'Aplicar atualizações e correções de segurança dentro das janelas de mudança', 'Automatizar rotinas (scripts, Ansible)', 'Documentar ambientes e procedimentos operacionais'],
            ['Superior completo em TI', '3 a 5 anos em infraestrutura'],
            ['Linux (RHEL/Ubuntu) e Windows Server', 'VMware ou Proxmox', 'Backup (Veeam ou similar)', 'Zabbix, Grafana', 'Shell, Python ou Ansible', 'Nuvem (AWS/Azure) desejável'],
            ['Visão sistêmica', 'Organização e método em mudanças', 'Proatividade', 'Colaboração com outras áreas'],
            ['Disponibilidade dos serviços (%)', 'Incidentes por causa de infraestrutura', 'Sucesso de backup e testes de restauração', 'Mudanças sem incidente'],
            'Coordenador de TI', 'Redes, Segurança, Suporte N2, fornecedores', 'Híbrido; janelas de mudança fora do horário comercial e sobreaviso.'],
        'Analista de Redes' => ['2124-10', 'Pleno / Sênior',
            'Projetar, implantar e operar a rede (LAN, WAN, Wi-Fi e segurança de perímetro) com alta disponibilidade e desempenho.',
            ['Configurar e manter switches, roteadores, firewalls e Wi-Fi', 'Implantar VPNs, SD-WAN e segmentação de rede', 'Monitorar tráfego, latência e disponibilidade dos links', 'Atuar em incidentes de rede e na análise de causa raiz', 'Elaborar projetos (HLD/LLD) e documentar topologias', 'Avaliar e homologar equipamentos e fornecedores'],
            ['Superior completo em TI ou Engenharia', '4 anos ou mais em redes'],
            ['Roteamento (OSPF, BGP) e switching (VLAN, STP, LACP)', 'Firewalls (Fortinet, Palo Alto ou similar)', 'Wi-Fi corporativo', 'Monitoramento SNMP (Zabbix)', 'Certificação CCNA/NSE (desejável)'],
            ['Precisão técnica', 'Calma em incidentes críticos', 'Planejamento', 'Comunicação com clientes e operadoras'],
            ['Disponibilidade da rede e dos links', 'Tempo de restabelecimento', 'Mudanças executadas sem impacto', 'Projetos entregues no prazo'],
            'Coordenador de TI', 'Infraestrutura, Segurança, operadoras, clientes', 'Híbrido; atividades em campo e janelas noturnas.'],
        'Analista de Segurança da Informação' => ['2123-20', 'Pleno / Sênior',
            'Proteger as informações e os sistemas da empresa e dos clientes, identificando riscos, implantando controles e respondendo a incidentes.',
            ['Gerir vulnerabilidades (varreduras, priorização e correção)', 'Monitorar eventos de segurança (SIEM) e responder a incidentes', 'Administrar controles: EDR, firewall, MFA, gestão de acessos', 'Apoiar a conformidade (LGPD, ISO 27001) e auditorias', 'Conduzir campanhas de conscientização', 'Elaborar políticas, normas e procedimentos'],
            ['Superior completo em TI', '3 anos ou mais em segurança'],
            ['SIEM (Wazuh, Graylog ou similar)', 'Gestão de vulnerabilidades', 'Hardening de Linux/Windows', 'LGPD e ISO 27001', 'Certificações Security+, CEH ou ISO 27001 (desejável)'],
            ['Ética e discrição', 'Pensamento crítico', 'Influência e comunicação', 'Atualização constante'],
            ['Vulnerabilidades críticas em aberto', 'Tempo de resposta a incidentes', 'Cobertura de MFA e EDR', 'Adesão aos treinamentos'],
            'Coordenador de TI', 'Todas as áreas, Infraestrutura, Jurídico, DPO', 'Híbrido; sobreaviso para incidentes.'],
        'Técnico de Campo' => ['', 'Júnior / Pleno',
            'Executar atendimentos presenciais em clientes: instalação, manutenção e substituição de equipamentos, com qualidade e registro completo.',
            ['Atender chamados em campo conforme a agenda e o SLA', 'Instalar e configurar estações, impressoras, ativos de rede e cabeamento', 'Realizar manutenção preventiva e corretiva', 'Registrar no chamado o atendimento, as peças e as evidências (fotos, assinatura)', 'Controlar materiais e peças sob sua responsabilidade', 'Lançar as despesas de deslocamento (reembolso)'],
            ['Ensino técnico em Informática, Redes ou Eletrônica', 'CNH categoria B (obrigatória)', '1 ano de experiência em campo (desejável)'],
            ['Hardware de estações e impressoras', 'Cabeamento estruturado e crimpagem', 'Configuração básica de switches e Wi-Fi', 'Uso de aplicativos de chamado no celular'],
            ['Pontualidade', 'Boa apresentação e atendimento ao cliente', 'Responsabilidade com equipamentos', 'Direção defensiva'],
            ['Chamados atendidos no SLA', 'Retorno ao mesmo cliente (reincidência)', 'Satisfação do cliente', 'Despesas por atendimento'],
            'Coordenador de TI', 'Suporte N2, Logística, clientes', 'Externo, com deslocamentos diários; veículo próprio ou da empresa.'],
        'Desenvolvedor' => ['2124-05', 'Pleno',
            'Desenvolver e manter sistemas e integrações (como GLPI, Zabbix e portais internos) com qualidade, segurança e entregas frequentes.',
            ['Desenvolver funcionalidades, plugins e integrações via API', 'Escrever testes e revisar código (code review)', 'Manter a documentação técnica', 'Corrigir defeitos e atuar em melhorias de desempenho', 'Participar do planejamento ágil (sprints, refinamento)', 'Publicar entregas com versionamento e pipelines'],
            ['Superior completo ou em andamento em TI', '3 anos ou mais em desenvolvimento'],
            ['PHP 8 e frameworks (Symfony/Laravel)', 'JavaScript e HTML/CSS', 'SQL (MySQL/MariaDB)', 'Git e CI/CD', 'APIs REST', 'GLPI/Zabbix (diferencial)'],
            ['Foco em qualidade', 'Colaboração', 'Resolução de problemas', 'Aprendizado contínuo'],
            ['Entregas por sprint', 'Defeitos em produção', 'Cobertura de testes', 'Tempo de ciclo das entregas'],
            'Coordenador de TI', 'Produto, Suporte N2, Infraestrutura, usuários-chave', 'Remoto ou híbrido.'],
        'Coordenador de TI' => ['', 'Gestão',
            'Coordenar as equipes de suporte, infraestrutura e projetos de TI, garantindo níveis de serviço, desenvolvimento das pessoas e alinhamento com o negócio.',
            ['Planejar e acompanhar os indicadores e SLAs da área', 'Gerir a equipe: escala, metas, avaliação e desenvolvimento', 'Priorizar demandas e projetos com as áreas de negócio', 'Controlar orçamento, contratos e fornecedores', 'Conduzir a gestão de mudanças e de incidentes críticos', 'Reportar resultados à diretoria'],
            ['Superior completo em TI; pós-graduação em gestão (desejável)', '5 anos ou mais em TI, sendo 2 em liderança'],
            ['ITIL v4 e gestão de serviços', 'Gestão de projetos (PMBOK/ágil)', 'Infraestrutura e segurança', 'Orçamento e contratos'],
            ['Liderança e desenvolvimento de pessoas', 'Negociação', 'Visão de negócio', 'Tomada de decisão'],
            ['SLA consolidado', 'Satisfação dos usuários', 'Execução orçamentária', 'Turnover e engajamento da equipe'],
            'Diretoria', 'Todas as áreas, fornecedores, clientes estratégicos', 'Híbrido; disponibilidade para incidentes críticos.'],
        'Gerente de Projetos' => ['', 'Sênior',
            'Planejar e conduzir projetos de TI do início ao encerramento, entregando escopo, prazo, custo e qualidade acordados com o cliente.',
            ['Elaborar o termo de abertura, o escopo e o cronograma', 'Gerir riscos, mudanças, custos e recursos', 'Conduzir reuniões de status com cliente e equipe', 'Acompanhar indicadores (prazo, custo, % concluído) no PMO', 'Garantir a documentação e o aceite das entregas', 'Encerrar o projeto com lições aprendidas'],
            ['Superior completo; pós em gestão de projetos (desejável)', '4 anos ou mais em gestão de projetos de TI'],
            ['PMBOK e métodos ágeis (Scrum/Kanban)', 'MS Project e ferramentas de PMO', 'Gestão de riscos e contratos', 'Certificação PMP ou PSM (desejável)'],
            ['Organização', 'Comunicação e negociação', 'Liderança sem autoridade formal', 'Resiliência'],
            ['Projetos no prazo e no orçamento', 'SPI/CPI', 'Satisfação do cliente', 'Margem do projeto'],
            'Diretoria / PMO', 'Clientes, Comercial, equipes técnicas, Financeiro', 'Híbrido; viagens a clientes.'],
        'Analista Administrativo' => ['', 'Pleno',
            'Apoiar as rotinas administrativas da empresa com organização, controle e qualidade das informações.',
            ['Controlar contratos, documentos e arquivos', 'Apoiar compras: cotações, pedidos e acompanhamento', 'Organizar agendas, viagens e reuniões', 'Atualizar planilhas e relatórios gerenciais', 'Apoiar Financeiro e RH em rotinas compartilhadas', 'Atender fornecedores e clientes internos'],
            ['Superior completo ou em andamento em Administração ou áreas afins', '2 anos em rotinas administrativas'],
            ['Excel intermediário/avançado', 'Pacote Office e ferramentas de colaboração', 'Noções de compras e contratos'],
            ['Organização', 'Atenção a detalhes', 'Comunicação', 'Proatividade'],
            ['Prazos de demandas atendidos', 'Qualidade dos controles', 'Economia em compras'],
            'Gerência Administrativa', 'Financeiro, RH, Compras, fornecedores', 'Presencial.'],
        'Analista Financeiro' => ['', 'Júnior / Pleno',
            'Executar e controlar as rotinas financeiras (contas a pagar e a receber, fluxo de caixa e reembolsos) com precisão e dentro dos prazos.',
            ['Processar contas a pagar e a receber', 'Conciliar contas bancárias', 'Elaborar o fluxo de caixa e relatórios financeiros', 'Conferir e pagar reembolsos de despesas', 'Apoiar o fechamento mensal e as auditorias', 'Controlar centros de custo e orçamento'],
            ['Superior completo ou em andamento em Administração, Economia ou Ciências Contábeis', '1 a 3 anos na área financeira'],
            ['Excel avançado', 'ERP financeiro', 'Conciliação bancária', 'Noções de contabilidade e tributos'],
            ['Atenção a detalhes', 'Ética e sigilo', 'Organização', 'Senso analítico'],
            ['Pagamentos no prazo', 'Divergências de conciliação', 'Precisão do fluxo de caixa'],
            'Gerência Financeira', 'Todas as áreas, bancos, fornecedores, contabilidade', 'Presencial ou híbrido.'],
        'Analista de RH' => ['2524-05', 'Pleno',
            'Conduzir os processos de gestão de pessoas, em especial recrutamento e seleção, admissão, desenvolvimento e relações com os colaboradores.',
            ['Conduzir recrutamento e seleção: divulgação, triagem, entrevistas e devolutivas', 'Organizar admissões e onboarding com as áreas', 'Apoiar treinamentos e avaliações de desempenho', 'Controlar férias, benefícios e indicadores de pessoas', 'Atender colaboradores e gestores em dúvidas de RH', 'Manter os cadastros e registros atualizados'],
            ['Superior completo em Psicologia, Administração ou Gestão de RH', '2 anos ou mais em RH'],
            ['Técnicas de entrevista e avaliação', 'Legislação trabalhista básica e eSocial', 'Excel e sistemas de RH', 'Indicadores de RH (turnover, absenteísmo)'],
            ['Escuta ativa e empatia', 'Sigilo', 'Organização', 'Comunicação'],
            ['Tempo médio de contratação', 'Turnover em 90 dias', 'Satisfação dos gestores com as contratações', 'Pendências de admissão'],
            'Coordenação de RH', 'Gestores, colaboradores, candidatos, contabilidade', 'Presencial ou híbrido.'],
        'Executivo de Vendas' => ['', 'Pleno / Sênior',
            'Gerar novos negócios e expandir a carteira de clientes em serviços de TI e datacenter, atingindo as metas de receita com relacionamento consultivo.',
            ['Prospectar e qualificar oportunidades', 'Conduzir reuniões, propostas e negociações', 'Manter o pipeline atualizado no CRM', 'Atingir metas mensais e trimestrais de receita', 'Atuar com a pré-venda nas soluções técnicas', 'Garantir a passagem do cliente para a implantação'],
            ['Superior completo', '3 anos ou mais em vendas B2B de tecnologia'],
            ['Venda consultiva (SPIN, Challenger)', 'CRM', 'Serviços de TI, cloud e datacenter', 'Negociação e precificação'],
            ['Orientação a resultados', 'Persuasão', 'Resiliência', 'Relacionamento'],
            ['Receita nova (MRR)', 'Taxa de conversão do pipeline', 'Ticket médio', 'Ciclo de vendas'],
            'Diretoria Comercial', 'Pré-vendas, Projetos, Financeiro, clientes', 'Externo e híbrido; viagens.'],
        'Pré-vendas' => ['', 'Sênior',
            'Desenhar soluções técnicas e apoiar a venda, traduzindo a necessidade do cliente em arquitetura, escopo e proposta viáveis.',
            ['Levantar requisitos com o cliente', 'Desenhar a arquitetura e dimensionar a solução', 'Elaborar escopo técnico, estimativas e propostas', 'Fazer demonstrações e provas de conceito', 'Apoiar o executivo na negociação técnica', 'Passar o projeto para a equipe de implantação'],
            ['Superior completo em TI ou Engenharia', '5 anos ou mais em infraestrutura ou cloud, com atuação em pré-vendas'],
            ['Cloud (AWS/Azure), virtualização e redes', 'Segurança e backup', 'Dimensionamento e custos', 'Certificações de fabricantes (desejável)'],
            ['Comunicação técnica para não técnicos', 'Criatividade', 'Visão de negócio', 'Organização'],
            ['Propostas entregues no prazo', 'Taxa de conversão técnica', 'Precisão das estimativas'],
            'Diretoria Comercial', 'Executivos de vendas, Projetos, fabricantes', 'Híbrido; visitas a clientes.'],
        'Estagiário' => ['', 'Estágio',
            'Aprender na prática as rotinas de TI, apoiando a equipe em atividades supervisionadas e desenvolvendo competências técnicas e profissionais.',
            ['Apoiar o atendimento de chamados de baixa complexidade', 'Auxiliar na preparação de equipamentos e inventário', 'Documentar procedimentos e atualizar a base de conhecimento', 'Participar de treinamentos e acompanhar atividades técnicas', 'Executar tarefas definidas no plano de estágio'],
            ['Curso superior ou técnico em TI em andamento (matrícula ativa)', 'Disponibilidade de 6 horas diárias'],
            ['Windows e Office', 'Noções de redes e hardware', 'Interesse por Linux e cloud'],
            ['Curiosidade', 'Comprometimento', 'Comunicação', 'Organização'],
            ['Evolução no plano de estágio', 'Avaliação do supervisor'],
            'Supervisor de estágio (TI)', 'Equipe de suporte e infraestrutura', 'Presencial, 30 horas semanais (Lei 11.788/2008).'],
    ];

    public static function forRole(string $name): ?array
    {
        $r = self::ROLES[$name] ?? null;
        return $r ? ['cbo' => $r[0], 'html' => self::html(...$r)] : null;
    }

    public static function names(): array
    {
        return array_keys(self::ROLES);
    }

    /** Modelo em branco para cargos novos. */
    public static function blank(): string
    {
        return self::html('', '', 'Por que o cargo existe: o principal resultado que entrega para a empresa.',
            ['Responsabilidade 1', 'Responsabilidade 2', 'Responsabilidade 3'], ['Formação', 'Experiência'],
            ['Conhecimento técnico 1', 'Ferramentas', 'Certificações desejáveis'], ['Competência 1', 'Competência 2'],
            ['Indicador 1', 'Indicador 2'], 'Cargo do gestor', 'Áreas e públicos', 'Regime, local, jornada, viagens, sobreaviso.');
    }

    private static function html(string $cbo, string $level, string $mission, array $resp, array $req, array $tech, array $soft, array $kpis, string $reports, string $relations, string $conditions): string
    {
        $e = fn ($v) => htmlspecialchars($v, ENT_QUOTES);
        $ul = fn (array $items) => '<ul>' . implode('', array_map(fn ($i) => '<li>' . $e($i) . '</li>', $items)) . '</ul>';
        return '<h3>Identificação</h3><p><strong>Nível:</strong> ' . ($level ? $e($level) : '—') . ' &nbsp;·&nbsp; <strong>CBO:</strong> ' . ($cbo ? $e($cbo) : 'a confirmar (cbo.mte.gov.br)')
            . ' &nbsp;·&nbsp; <strong>Reporta a:</strong> ' . $e($reports) . '</p>'
            . '<h3>Missão do cargo</h3><p>' . $e($mission) . '</p>'
            . '<h3>Principais responsabilidades</h3>' . $ul($resp)
            . '<h3>Requisitos</h3><p><strong>Formação e experiência</strong></p>' . $ul($req) . '<p><strong>Conhecimentos técnicos</strong></p>' . $ul($tech)
            . '<h3>Competências comportamentais</h3>' . $ul($soft)
            . '<h3>Indicadores de desempenho</h3>' . $ul($kpis)
            . '<h3>Relacionamentos</h3><p>' . $e($relations) . '</p>'
            . '<h3>Condições de trabalho</h3><p>' . $e($conditions) . '</p>';
    }
}
