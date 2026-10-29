<?php

namespace GlpiPlugin\Sistemasinternos;

use RuntimeException;

/** Pedido fora do departamento ou inexistente: a rota responde 404 com {"erro": ...}. */
class NotFound extends RuntimeException
{
}
