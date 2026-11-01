<?php

namespace GlpiPlugin\Satisfacao;

use NotificationTarget;

/**
 * Destinatários e dados do e-mail da pesquisa. Os destinatários são os e-mails
 * gravados na pesquisa (o solicitante do chamado ou os e-mails do Ciclo).
 */
class NotificationTargetSurvey extends NotificationTarget
{
    public const RECIPIENT = 5201;

    public function getEvents()
    {
        return [
            Notifications::EVENT      => 'Pesquisa de satisfação: convite para responder',
            Notifications::EVENT_ENPS => 'Pesquisa de clima (eNPS): convite para responder',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::RECIPIENT, 'Destinatário da pesquisa');
    }

    public function addSpecificTargets($data, $options)
    {
        if ((int) $data['items_id'] !== self::RECIPIENT) {
            return;
        }
        foreach (Survey::splitEmails((string) $this->obj->fields['recipients']) as $email) {
            $this->addToRecipientsList(['users_id' => 0, 'language' => '', 'email' => $email]);
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $f = $this->obj->fields;
        $this->data['##satisfacao.titulo##'] = (string) $f['name'];
        $this->data['##satisfacao.origem##'] = Survey::ORIGINS[$f['itemtype']] ?? (string) $f['itemtype'];
        $campaign = new Campaign();
        $this->data['##satisfacao.pergunta##'] = $f['itemtype'] === Survey::ENPS && $campaign->getFromDB((int) $f['items_id']) ? (string) $campaign->fields['question'] : '';
        $this->data['##satisfacao.url##'] = $this->obj->url();
        $showQuestions = (\Config::getConfigurationValues('plugin:satisfacao')['email_questions'] ?? '1') === '1';
        $this->data['##satisfacao.perguntas##'] = $showQuestions ? $this->questionList() : '';
    }

    public function getTags()
    {
        $tags = [
            'titulo'     => 'Chamado ou ciclo pesquisado',
            'origem'     => 'Origem (Chamado ou Ciclo financeiro)',
            'url'        => 'Link para responder a pesquisa',
            'perguntas'  => 'Perguntas da pesquisa',
            'pergunta'   => 'Pergunta da campanha de eNPS',
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "satisfacao.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    /** As perguntas ativas, em lista, para o e-mail mostrar o que será perguntado. */
    private function questionList(): string
    {
        $items = '';
        foreach (Question::active() as $q) {
            $items .= '<li>' . Menu::e($q['name']) . '</li>';
        }
        return $items ? "<ul>$items</ul>" : '';
    }
}
