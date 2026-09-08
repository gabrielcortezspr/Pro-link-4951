<?php

declare(strict_types=1);

namespace ProLink\Service;

use RuntimeException;

/**
 * Entrada recusada por regra de negócio — não é falha de sistema.
 *
 * O controller captura e devolve o formulário com os erros por campo; o HTTP continua 200 e o
 * front controller não transforma isso em 500. A mensagem é texto escrito para o usuário ler,
 * nunca detalhe interno (OWASP A05).
 */
class ValidacaoException extends RuntimeException
{
    /** @param array<string, string> $erros campo => mensagem */
    public function __construct(string $mensagem, private readonly array $erros = [])
    {
        parent::__construct($mensagem);
    }

    /** @return array<string, string> */
    public function erros(): array
    {
        return $this->erros;
    }
}
