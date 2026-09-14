<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Os três desfechos possíveis de uma consulta de registro no CREA, num vocabulário só.
 *
 * Vale igual para o profissional (busca por CPF) e para a empresa (busca por CNPJ), porque a
 * distinção que importa não é de quem se consulta, e sim do que a API respondeu:
 *
 *   · **VINCULADO** — a API devolveu o registro. Perfil montado e acervo importado.
 *   · **SEM_REGISTRO** — `200 []`: o documento é válido e não tem registro no CREA. A conta
 *     continua, como Terceiro, porque manter o perfil prometeria um registro que não existe.
 *   · **API_INDISPONIVEL** — a API não respondeu. A conta é criada e fica pendente de validação.
 *     Não é erro do usuário e não pode custar a inscrição dele (D20).
 *
 * Nenhum dos três é erro, e é justamente por isso que eles precisam de nome: o desfecho vira
 * mensagem na tela, e "não achei" e "não respondi" pedem mensagens opostas.
 */
final class DesfechoCrea
{
    public const VINCULADO        = 'VINCULADO';
    public const SEM_REGISTRO     = 'SEM_REGISTRO';
    public const API_INDISPONIVEL = 'API_INDISPONIVEL';

    private function __construct()
    {
    }
}
