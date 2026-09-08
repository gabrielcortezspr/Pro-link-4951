<?php

declare(strict_types=1);

namespace ProLink\Service;

use Generator;
use ProLink\Support\Crypto;
use ProLink\Support\Transporte;
use ProLink\Support\TransporteCurl;
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
 *   4. O transporte é injetado (decisão D08). Toda a leitura de status e corpo continua aqui,
 *      num lugar só; o que muda por injeção é apenas de onde a resposta vem — rede em produção,
 *      `fixtures/` no teste. Quem troca o transporte não desvia de nenhuma regra acima.
 */
final class CreaApiClient
{
    /** Teto de páginas por profissional. Ver todasArtsDoProfissional(). */
    private const MAX_PAGINAS = 100;

    public function __construct(
        private readonly Transporte $transporte = new TransporteCurl(),
    ) {
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

    // ---------------------------------------------------------------- acervo completo de um candidato

    /**
     * Todas as ARTs de UM profissional, página a página.
     *
     * Isto não contradiz a regra 2 do cabeçalho. Varredura é percorrer a base do CREA atrás de
     * gente; aqui o laço é limitado ao acervo de um candidato que se cadastrou na plataforma e
     * consentiu com a consulta — o mesmo dado que ele veria na própria certidão. O que continua
     * não existindo é método que liste profissionais ou empresas.
     *
     * Generator para o serviço poder gravar enquanto lê, sem montar 290 ARTs na memória.
     *
     * @return Generator<int, array<string, mixed>>
     * @throws RuntimeException se o número de páginas passar do teto — melhor falhar alto do que
     *                          truncar o acervo de alguém em silêncio, ou martelar uma API que
     *                          registra toda chamada.
     */
    public function todasArtsDoProfissional(string $rnp, int $limite = 20): Generator
    {
        yield from $this->percorrer("profissionais/{$rnp}/arts", $limite);
    }

    /**
     * Todas as CATs de UM profissional. Mesmas ressalvas de todasArtsDoProfissional().
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function todasCatsDoProfissional(string $rnp, int $limite = 20): Generator
    {
        yield from $this->percorrer("profissionais/{$rnp}/cats", $limite);
    }

    /**
     * O laço de paginação, num lugar só.
     *
     * Três paradas, porque uma só não basta: a página voltou vazia, o contador alcançou
     * `total_paginas`, ou estourou o teto. A primeira protege contra `total_paginas` vindo
     * errado; a segunda evita uma requisição a mais por profissional — sem ela seria preciso
     * uma página vazia para descobrir que acabou.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function percorrer(string $recurso, int $limite): Generator
    {
        $pagina = 1;

        do {
            $envelope = $this->obter(['p' => $recurso, 'page' => $pagina, 'limit' => $limite]);
            $linhas   = $this->linhasDoEnvelope($envelope, $recurso);

            // `yield` item a item, e não `yield from $linhas`: `yield from` sobre uma lista
            // reinicia as chaves a cada página, e aí duas ARTs de páginas diferentes colidem
            // em `iterator_to_array()`. Assim o contador do generator segue contínuo.
            foreach ($linhas as $linha) {
                yield $linha;
            }

            $ultima = $pagina >= (int) ($envelope['total_paginas'] ?? 1);
            $pagina++;

            if ($pagina > self::MAX_PAGINAS) {
                throw new RuntimeException(
                    "Paginação de {$recurso} passou de " . self::MAX_PAGINAS
                    . ' páginas. Interrompido: ou a API mudou o envelope, ou há laço.'
                );
            }
        } while ($linhas !== [] && !$ultima);
    }

    /**
     * Extrai `data` de um envelope paginado, recusando o que não tem a forma documentada.
     *
     * Envelope sem `data` significa que a API mudou de formato debaixo de nós. Devolver lista
     * vazia nesse caso seria gravar "este profissional não tem ART nenhuma" — que é pior do que
     * falhar, porque entra no índice e some.
     *
     * @param array<string, mixed> $envelope
     * @return list<array<string, mixed>>
     */
    private function linhasDoEnvelope(array $envelope, string $recurso): array
    {
        if (!isset($envelope['data']) || !is_array($envelope['data'])) {
            throw new ApiIndisponivelException(
                "Envelope inesperado em {$recurso}: sem a chave 'data'. Formato da API mudou?"
            );
        }

        return array_values($envelope['data']);
    }

    // ---------------------------------------------------------------- transporte

    /**
     * Pede ao transporte e interpreta a resposta. Toda a semântica de status mora aqui, e é a
     * mesma independentemente de a resposta ter vindo da rede ou de uma fixture.
     *
     * @param array<string, string|int> $params
     * @return array<mixed> Corpo decodificado. Envelopes paginados voltam inteiros, com
     *                      pagina_atual, total_paginas e data.
     */
    private function obter(array $params): array
    {
        $resposta = $this->transporte->get($params);
        $dados    = json_decode($resposta->corpo, true);
        $status   = $resposta->status;

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
