<?php

declare(strict_types=1);

namespace ProLink\Support;

use ProLink\Service\ValidacaoException;

/**
 * Acumulador de erros de validação, um por campo.
 *
 * Existe para que serviço e controller não negociem formato de erro: o serviço valida e lança
 * ValidacaoException; o controller entrega os erros direto às macros de
 * `layout/_form.html.twig`, que esperam exatamente `erros.nome_do_campo`.
 *
 * Só o primeiro erro de cada campo sobrevive — a primeira regra a falhar é a mais específica, e
 * "e-mail obrigatório" seguido de "e-mail inválido" no mesmo campo é ruído.
 */
final class Validacao
{
    /** @var array<string, string> */
    private array $erros = [];

    /** Registra o erro se a condição for falsa. Encadeável. */
    public function exigir(string $campo, bool $condicao, string $mensagem): self
    {
        if (!$condicao && !isset($this->erros[$campo])) {
            $this->erros[$campo] = $mensagem;
        }

        return $this;
    }

    public function obrigatorio(string $campo, ?string $valor, string $mensagem): self
    {
        return $this->exigir($campo, $valor !== null && trim($valor) !== '', $mensagem);
    }

    public function email(string $campo, ?string $valor, string $mensagem): self
    {
        return $this->exigir($campo, filter_var((string) $valor, FILTER_VALIDATE_EMAIL) !== false, $mensagem);
    }

    public function tamanhoMaximo(string $campo, ?string $valor, int $maximo, string $mensagem): self
    {
        return $this->exigir($campo, mb_strlen((string) $valor) <= $maximo, $mensagem);
    }

    public function tamanhoMinimo(string $campo, ?string $valor, int $minimo, string $mensagem): self
    {
        return $this->exigir($campo, mb_strlen((string) $valor) >= $minimo, $mensagem);
    }

    /** @param list<string> $permitidos */
    public function entre(string $campo, ?string $valor, array $permitidos, string $mensagem): self
    {
        return $this->exigir($campo, in_array($valor, $permitidos, true), $mensagem);
    }

    public function valido(): bool
    {
        return $this->erros === [];
    }

    /** @return array<string, string> */
    public function erros(): array
    {
        return $this->erros;
    }

    /** Saída única: o serviço chama isto e não monta a exceção à mão. */
    public function lancarSeInvalido(string $mensagem = 'Confira os campos destacados.'): void
    {
        if (!$this->valido()) {
            throw new ValidacaoException($mensagem, $this->erros);
        }
    }
}
