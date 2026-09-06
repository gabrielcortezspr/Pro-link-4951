<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Support\Crypto;
use RuntimeException;

/**
 * Cliente da API oficial do desafio (RF02, edital 8.4).
 *
 * Formatos e comportamentos confirmados contra a API real em 06/09/2026; as respostas de
 * referência estão em fixtures/ e a documentação em docs/endpoints.md.
 *
 * Três regras que este cliente impõe por construção:
 *
 *   1. Identificador é string. RNP e CNPJ têm zero à esquerda; convertidos a int, a busca
 *      devolve 404.
 *   2. Nada de varredura. O item 10.4 do edital proíbe coleta automatizada e toda chamada é
 *      registrada pela organização. Este cliente não tem método de listagem em massa,
 *      de propósito.
 *   3. Distinção entre "não encontrado" e "não pertence": 200 [] significa que a chave é
 *      válida mas nada casou (uma reprovação legítima na validação de documento); 404
 *      significa que o identificador não existe.
 */
final class CreaApiClient
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

    // ---------------------------------------------------------------- profissionais

    /** Busca por CPF. Devolve null se o CPF não existe na base (200 []). */
    public function profissionalPorCpf(string $cpf): ?array
    {
        $lista = $this->obter(['p' => 'profissionais', 'cpf' => Crypto::apenasDigitos($cpf)]);

        return $lista[0] ?? null;
    }

    /** ARTs do profissional, com atividades TOS e local. Único endpoint que traz o local. */
    public function artsDoProfissional(string $rnp, int $pagina = 1, int $limite = 20): array
    {
        return $this->obter([
            'p'     => "profissionais/{$rnp}/arts",
            'page'  => $pagina,
            'limit' => $limite,
        ]);
    }

    public function catsDoProfissional(string $rnp, int $pagina = 1, int $limite = 20): array
    {
        return $this->obter([
            'p'     => "profissionais/{$rnp}/cats",
            'page'  => $pagina,
            'limit' => $limite,
        ]);
    }

    // ---------------------------------------------------------------- empresas

    public function empresaPorCnpj(string $cnpj): ?array
    {
        $lista = $this->obter(['p' => 'empresas', 'cnpj' => Crypto::apenasDigitos($cnpj)]);

        return $lista[0] ?? null;
    }

    /** Traz qut_tipo, qut_dt_inicio e qut_dt_fim, que o CAO omite. */
    public function quadroTecnico(string $registroCrea): array
    {
        return $this->obter(['p' => "empresas/{$registroCrea}/quadro-tecnico"]);
    }

    /**
     * Certidão de Acervo Operacional: a árvore inteira da empresa numa chamada.
     *
     * Estrutura real (não é a que a documentação da organização sugere):
     *   { emp_cnpj, emp_razao_social, emp_nome_fantasia, emp_registro_crea,
     *     quadro_tecnico: [ { pro_nome, pro_rnp, pro_registro_crea, qut_funcao,
     *                         arts: [ { ..., atividades: [...] } ] } ] }
     *
     * O profissional contém as ARTs, e não o contrário. Não existe chave `cao_arts`.
     */
    public function cao(string $registroCrea): array
    {
        return $this->obter(['p' => "empresas/{$registroCrea}/cao"]);
    }

    // ---------------------------------------------------------------- validação de documentos

    /**
     * Confirma que a ART pertence ao RNP informado. Devolve null quando a combinação não
     * confere — que é reprovação de validação, não erro de sistema.
     */
    public function validarArt(string $rnp, string $numeroArt): ?array
    {
        $lista = $this->obter(['p' => 'arts', 'rnp' => $rnp, 'art_numero' => $numeroArt]);

        return $lista[0] ?? null;
    }

    /**
     * Atividades TOS de uma ART. Atenção: este endpoint não pede RNP, então qualquer número
     * válido responde. Chame validarArt() ANTES, para confirmar a titularidade.
     */
    public function atividadesDaArt(string $numeroArt): array
    {
        return $this->obter(['p' => "arts/{$numeroArt}/atividades"]);
    }

    /** CAT com as ARTs que ela agrupa, cada uma com suas atividades. */
    public function validarCat(string $rnp, string $numeroCat): ?array
    {
        $lista = $this->obter(['p' => 'cats', 'rnp' => $rnp, 'cat_numero' => $numeroCat]);

        return $lista[0] ?? null;
    }

    // ---------------------------------------------------------------- TOS

    /**
     * Dicionário de obras e serviços. 2000 registros, 46 grupos, estático durante o desafio —
     * já carregado em crea_tos pela carga inicial. Use este método só para reconferir.
     */
    public function tos(?string $busca = null, int $pagina = 1, int $limite = 200): array
    {
        $params = ['p' => 'tos', 'page' => $pagina, 'limit' => min($limite, 200)];

        if ($busca !== null && $busca !== '') {
            $params['search'] = $busca;
        }

        return $this->obter($params);
    }

    // ---------------------------------------------------------------- transporte

    /**
     * @return array Corpo decodificado. Envelopes paginados voltam inteiros, com
     *               pagina_atual, total_paginas e data.
     */
    private function obter(array $params): array
    {
        $url = $this->base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
        ]);

        $corpo   = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($corpo === false) {
            throw new ApiIndisponivelException('Falha de transporte com a API oficial: ' . $erroCurl);
        }

        $dados = json_decode((string) $corpo, true);

        if (!is_array($dados)) {
            throw new ApiIndisponivelException("Resposta não-JSON da API (HTTP {$status}).");
        }

        // 404 com {"error": ...}: o identificador não existe na base do CREA.
        if ($status === 404) {
            throw new NaoEncontradoException($dados['error'] ?? 'Registro não encontrado na API.');
        }

        if ($status === 401 || $status === 403) {
            throw new ApiIndisponivelException('Token da API recusado (HTTP ' . $status . ').');
        }

        if ($status === 429) {
            throw new ApiIndisponivelException('Limite de requisições da API atingido.');
        }

        if ($status >= 400) {
            throw new ApiIndisponivelException(
                'Erro na API oficial (HTTP ' . $status . '): ' . ($dados['error'] ?? 'sem detalhe')
            );
        }

        return $dados;
    }
}
