<?php

declare(strict_types=1);

namespace ProLink\Tests\Duplo;

use LogicException;
use ProLink\Support\RespostaHttp;
use ProLink\Support\Transporte;

/**
 * Transporte que devolve exatamente o que o teste mandar.
 *
 * Existe separado do `TransporteFixture` porque os dois provam coisas diferentes. O de fixtures
 * só sabe responder o que a API respondeu de verdade, e é isso que o torna confiável para o
 * caminho feliz. Este aqui serve para o que a API **não** foi observada fazendo — 401, 429,
 * corpo cortado, envelope sem `data` — que o cliente precisa tratar mesmo sem captura.
 */
final class TransporteStub implements Transporte
{
    /** @var list<RespostaHttp> */
    private array $fila = [];

    private ?RespostaHttp $repetida = null;

    /** @var list<array<string, string|int>> */
    public array $chamadas = [];

    public function __construct(RespostaHttp ...$respostas)
    {
        $this->fila = array_values($respostas);
    }

    /** Uma resposta só, devolvida em toda chamada — para laço e teto de páginas. */
    public static function sempre(int $status, string $corpo): self
    {
        $stub = new self();
        $stub->repetida = new RespostaHttp($status, $corpo);

        return $stub;
    }

    public function get(array $params): RespostaHttp
    {
        $this->chamadas[] = $params;

        if ($this->repetida !== null) {
            return $this->repetida;
        }

        return array_shift($this->fila)
            ?? throw new LogicException('TransporteStub ficou sem respostas na fila.');
    }
}
