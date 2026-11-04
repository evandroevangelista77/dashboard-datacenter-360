<?php

namespace GlpiPlugin\Operacional360;

use RuntimeException;

/** Erro da API com o código HTTP que volta ao navegador (403, 404, 503…). */
class HttpError extends RuntimeException
{
    private int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}
