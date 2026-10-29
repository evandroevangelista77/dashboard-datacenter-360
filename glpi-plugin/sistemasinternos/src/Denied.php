<?php

namespace GlpiPlugin\Sistemasinternos;

use RuntimeException;

/** Sem o direito para esta ação: a rota responde 403 com {"erro": ...}. */
class Denied extends RuntimeException
{
}
