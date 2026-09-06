<?php

declare(strict_types=1);

namespace ProLink\Service;

use RuntimeException;

/**
 * HTTP 404: o identificador não existe na base do CREA.
 *
 * Diferente de resposta vazia, que significa "existe, mas não casou" e é tratada com retorno
 * null pelos métodos de validação. A interface precisa distinguir os dois: um é "esse RNP não
 * existe", o outro é "essa ART não é sua".
 */
final class NaoEncontradoException extends RuntimeException
{
}
