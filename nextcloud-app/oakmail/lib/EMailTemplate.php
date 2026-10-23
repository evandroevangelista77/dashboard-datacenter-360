<?php

declare(strict_types=1);

namespace OCA\OakMail;

/**
 * Modelo de e-mail do Nextcloud (config.php: 'mail_template_class').
 *
 * Nos e-mails do LibreSign (assunto começa com "LibreSign:") troca o título por
 * "RELATÓRIO DE ATENDIMENTO TÉCNICO" e o prefixo do assunto. O logotipo e a cor
 * do topo vêm do Tema do Nextcloud. Os demais e-mails seguem o modelo padrão.
 */
class EMailTemplate extends \OC\Mail\EMailTemplate {
	public const HEADING = 'RELATÓRIO DE ATENDIMENTO TÉCNICO';
	private const LIBRESIGN_PREFIX = 'LibreSign:';

	private bool $isLibreSign = false;

	public function setSubject(string $subject): void {
		$this->isLibreSign = str_starts_with($subject, self::LIBRESIGN_PREFIX);
		if ($this->isLibreSign) {
			$subject = 'Relatório de Atendimento Técnico: ' . trim(substr($subject, strlen(self::LIBRESIGN_PREFIX)));
		}
		parent::setSubject($subject);
	}

	public function addHeading(string $title, $plainTitle = ''): void {
		if ($this->isLibreSign) {
			$title = self::HEADING;
			if ($plainTitle !== false) {
				$plainTitle = self::HEADING;
			}
		}
		parent::addHeading($title, $plainTitle);
	}
}
