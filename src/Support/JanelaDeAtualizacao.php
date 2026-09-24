<?php

declare(strict_types=1);

namespace ProLink\Support;

use DateTimeImmutable;

/**
 * Quando o titular pode voltar a pedir "Atualizar meu acervo no CREA" (D77).
 *
 * Cada clique consulta a API oficial várias vezes (perfil, ARTs, lista de CATs e cada CAT), e a
 * organização registra toda chamada. Sem janela, clicar sem parar é exatamente o padrão de
 * coleta automatizada que o item 10.4 veda. Classe sem estado e sem banco: a regra mora aqui e
 * tem teste, e o serviço só busca a última tentativa e a data de agora.
 *
 * Duas janelas, e a que vale é a da **última tentativa**:
 * - tentativa concluída (acervo atualizado, ou registro que o CREA não reconhece): espera longa,
 *   porque o dado acabou de chegar e não muda de minuto em minuto;
 * - tentativa que falhou porque a API não respondeu: espera curta, para a pessoa poder tentar de
 *   novo logo, sem que uma API caída vire uma rajada de chamadas.
 */
final class JanelaDeAtualizacao
{
    public const CONCLUIDA = 'concluida';
    public const FALHOU    = 'falhou';

    /**
     * O momento em que o botão volta a funcionar, ou null se já está liberado.
     *
     * Sem tentativa anterior, está liberado. Resultado desconhecido é tratado como concluído,
     * que é a espera mais longa: na dúvida, protege a API.
     */
    public static function liberadaEm(
        ?DateTimeImmutable $ultimaTentativa,
        ?string $resultado,
        int $minutosConcluida,
        int $minutosFalha,
        DateTimeImmutable $agora,
    ): ?DateTimeImmutable {
        if ($ultimaTentativa === null) {
            return null;
        }

        $minutos = $resultado === self::FALHOU ? $minutosFalha : $minutosConcluida;
        $libera  = $ultimaTentativa->modify('+' . max(0, $minutos) . ' minutes');

        return $libera > $agora ? $libera : null;
    }
}
