<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * O que um transporte devolve: status e corpo cru, sem interpretação.
 *
 * O corpo continua string de propósito. Quem decide o que `200 []` significa, o que fazer com
 * `404` e como tratar corpo não-JSON é o `CreaApiClient`, num lugar só — se o transporte já
 * devolvesse array decodificado, o transporte de fixtures desviaria dessa lógica e os testes da
 * E2 estariam verificando um caminho que a produção não percorre.
 */
final readonly class RespostaHttp
{
    public function __construct(
        public int $status,
        public string $corpo,
    ) {
    }
}
