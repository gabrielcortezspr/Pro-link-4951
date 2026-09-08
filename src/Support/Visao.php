<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * O que um espectador específico enxerga de um titular específico, decidido em memória.
 *
 * Montada uma vez por tela, com uma consulta ao banco, e consultada quantas vezes for preciso.
 * A alternativa — perguntar ao banco por campo — transformaria a regra de privacidade num
 * problema de desempenho, e regra de privacidade que custa caro é regra que alguém contorna.
 *
 * **Só o que passa por aqui deve chegar ao template.** A tela recebe o que já foi filtrado, em
 * vez de receber tudo e esconder com `{% if %}`: assim um `if` esquecido não vaza nada, porque
 * o dado escondido nunca saiu do controlador.
 */
final readonly class Visao
{
    /** @param array<string, string> $mapa chave do alvo => nível gravado */
    public function __construct(
        private array $mapa,
        private bool $ehDono,
        private bool $autenticado,
        private bool $perfilAberto,
    ) {
    }

    public function podeVer(string $entidade, ?int $entidadeId = null, ?string $campo = null): bool
    {
        return Visibilidade::podeVer(
            $this->mapa[Visibilidade::chave($entidade, $entidadeId, $campo)] ?? null,
            $this->ehDono,
            $this->autenticado,
            $this->perfilAberto,
        );
    }

    /** O nível escolhido pelo titular, para desenhar o controle na tela do próprio dono. */
    public function nivel(string $entidade, ?int $entidadeId = null, ?string $campo = null): string
    {
        return $this->mapa[Visibilidade::chave($entidade, $entidadeId, $campo)] ?? VISIBILIDADE_PRIVADO;
    }

    public function ehDono(): bool
    {
        return $this->ehDono;
    }

    /** Falso quando o consentimento de exibição foi revogado ou o registro no CREA não está regular. */
    public function perfilAberto(): bool
    {
        return $this->perfilAberto;
    }

    /**
     * Filtra um mapa de campo => valor, devolvendo só o que o espectador pode ver.
     *
     * @param array<string, mixed> $valores campo => valor
     * @return array<string, mixed>
     */
    public function filtrarCampos(array $valores, string $entidade = Visibilidade::PERFIL, ?int $entidadeId = null): array
    {
        $visiveis = [];

        foreach ($valores as $campo => $valor) {
            if ($this->podeVer($entidade, $entidadeId, $campo)) {
                $visiveis[$campo] = $valor;
            }
        }

        return $visiveis;
    }
}
