<?php

declare(strict_types=1);

namespace ProLink\Support;

use ProLink\Service\ApiIndisponivelException;
use RuntimeException;

/**
 * O transporte de verdade: cURL contra a API oficial do CREA.
 *
 * O token vive aqui, e não no `CreaApiClient`, por uma consequência prática: cliente construído
 * com transporte de fixtures não precisa de credencial nenhuma. É o que permite `composer test`
 * rodar a E2 inteira numa máquina sem token e sem rede.
 */
final class TransporteCurl implements Transporte
{
    public function __construct(
        private readonly string $base = API_BASE,
        private readonly string $token = API_TOKEN,
        private readonly int $timeout = API_TIMEOUT,
    ) {
        if ($this->token === '') {
            throw new RuntimeException('PROLINK_API_TOKEN não configurado no .env.');
        }
    }

    /**
     * Monta a URL. Público e estático para poder ser conferido sem rede — é aqui que moram as
     * duas regras de formato do `docs/api.md`: identificador é string, e a barra do número da
     * CAT precisa virar `%2F`. `PHP_QUERY_RFC3986` cuida das duas, inclusive das barras dentro
     * do próprio `p`, que a API decodifica de volta sem reclamar (confirmado em 06/09/2026).
     *
     * @param array<string, string|int> $params
     */
    public static function url(string $base, array $params): string
    {
        return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function get(array $params): RespostaHttp
    {
        $ch = curl_init(self::url($this->base, $params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
        ]);

        $corpo    = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($corpo === false) {
            throw new ApiIndisponivelException('Falha de transporte com a API oficial: ' . $erroCurl);
        }

        return new RespostaHttp($status, (string) $corpo);
    }
}
