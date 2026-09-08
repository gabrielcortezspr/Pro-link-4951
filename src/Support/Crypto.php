<?php

declare(strict_types=1);

namespace ProLink\Support;

use RuntimeException;

/**
 * Cifragem de CPF e CNPJ em repouso (edital 11.3; proposta, item 4 dos diferenciais).
 *
 * Por que guardar o documento em vez de descartá-lo: `pro_status` só é acessível pelo endpoint
 * de busca por CPF. Sem o CPF guardado não existe a sincronização periódica de status exigida
 * pela RF02. A decisão está registrada em docs/matching.md.
 *
 * Duas colunas por documento:
 *   *_cif   AES-256-GCM, reversível, para reconsultar a API
 *   *_hash  SHA-256 com pepper, irreversível, só para busca exata
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function chave(): string
    {
        if (APP_KEY === '' || strlen(APP_KEY) !== 32) {
            throw new RuntimeException('APP_KEY ausente ou inválida: gere 32 bytes em base64.');
        }

        return APP_KEY;
    }

    public static function cifrar(string $texto): string
    {
        $iv  = random_bytes(12);
        $tag = '';

        $cifrado = openssl_encrypt($texto, self::CIPHER, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cifrado === false) {
            throw new RuntimeException('Falha ao cifrar.');
        }

        return $iv . $tag . $cifrado;
    }

    public static function decifrar(string $pacote): string
    {
        if (strlen($pacote) < 29) {
            throw new RuntimeException('Pacote cifrado malformado.');
        }

        $iv      = substr($pacote, 0, 12);
        $tag     = substr($pacote, 12, 16);
        $cifrado = substr($pacote, 28);

        $texto = openssl_decrypt($cifrado, self::CIPHER, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($texto === false) {
            throw new RuntimeException('Falha ao decifrar: chave errada ou dado adulterado.');
        }

        return $texto;
    }

    /**
     * Hash de token de alta entropia: sessão (sis_sessoes) e recuperação de senha
     * (sis_recuperacoes). SHA-256 simples basta — diferente de documento, que é de baixa entropia
     * e precisa do pepper de hashBusca() para não ceder a dicionário.
     *
     * O banco guarda só o hash: vazamento de dump não entrega sessão nem link de redefinição.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Hash cego para busca exata por documento, sem decifrar a coluna inteira. */
    public static function hashBusca(string $documento): string
    {
        return hash_hmac('sha256', self::apenasDigitos($documento), self::chave());
    }

    /**
     * Selo de integridade da resposta da API (proposta, cenário 03A; OWASP A08).
     * Canonicaliza antes de assinar: ordena as chaves recursivamente para que a mesma resposta
     * produza sempre o mesmo selo, independente da ordem em que o JSON chegou.
     */
    public static function selo(array $resposta): string
    {
        return hash_hmac('sha256', self::canonicalizar($resposta), self::chave());
    }

    private static function canonicalizar(array $dados): string
    {
        $ordenar = static function (array $item) use (&$ordenar): array {
            ksort($item);

            foreach ($item as $chave => $valor) {
                if (is_array($valor)) {
                    $item[$chave] = $ordenar($valor);
                }
            }

            return $item;
        };

        return json_encode(
            $ordenar($dados),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    public static function apenasDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }
}
