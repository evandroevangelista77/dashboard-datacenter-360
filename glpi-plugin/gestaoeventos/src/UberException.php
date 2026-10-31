<?php

namespace GlpiPlugin\Gestaoeventos;

use RuntimeException;

/** Falha da integração Uber com um código curto e sem segredo (sem_autorizacao, entrada_invalida, limite, indisponivel, desativada…). */
class UberException extends RuntimeException
{
    public function __construct(public string $errCode, string $message)
    {
        parent::__construct($message);
    }
}
