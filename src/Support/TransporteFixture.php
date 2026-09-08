<?php

declare(strict_types=1);

namespace ProLink\Support;

use LogicException;

/**
 * Transporte que responde a partir de `fixtures/` — as respostas reais capturadas em 06/09/2026
 * (decisão D08). É o que permite escrever e testar a E2 inteira sem rede e sem token.
 *
 * Não é um espelho da massa, e o item 8.4 do edital continua respeitado: aqui não há base
 * própria simulando a API, há catorze respostas gravadas de uma consulta legítima, usadas em
 * teste. Nada disto entra no caminho da aplicação em produção — só chega aqui quem injeta esta
 * classe de propósito.
 *
 * ## O que esta classe reproduz, e por quê
 *
 * Reproduz a **semântica** da API, não só o conteúdo. A distinção que o `docs/api.md` documenta
 * — `200 []` significa "a chave é válida e nada casou", `404` significa "o identificador não
 * existe" — é a que a RF03 usa para dizer "essa ART não é sua" em vez de "erro no sistema". Um
 * fake que devolvesse sempre o arquivo certo não exercitaria nada disso.
 *
 * ## O que ela recusa fazer
 *
 * Requisição que não corresponde a nenhum comportamento observado levanta `LogicException` em
 * vez de inventar resposta. Um 404 fabricado faria um teste passar pelo motivo errado, e o
 * motivo errado é justamente o que não podemos levar para a banca. Quando o comportamento passa
 * a ser observado, a captura entra em `fixtures/` e a recusa vira resposta — foi o que aconteceu
 * com ART inexistente em `/atividades`, confirmada como 404 em 08/09/2026.
 */
final class TransporteFixture implements Transporte
{
    /** Sujeitos das capturas. Fora destes, a resposta é a que a API daria: vazio ou 404. */
    private const CPF_CAPTURADO       = '12312300109';
    private const RNP_CAPTURADO       = '0412340011';
    private const CNPJ_CAPTURADO      = '00123001000123';
    private const REGISTRO_CAPTURADO  = '61859';
    private const CAT_CAPTURADA       = '999001/2026';

    /** Registro CREA da empresa → arquivo do CAO. Duas destas têm CNPJ com DV quebrado (D07). */
    private const CAOS = [
        '61859' => 'empresa_cao',
        '66897' => 'cao_66897',
        '82940' => 'cao_82940',
    ];

    /** @var list<array<string, string|int>> tudo que foi pedido, na ordem */
    private array $chamadas = [];

    public function __construct(
        private readonly string $diretorio = __DIR__ . '/../../fixtures',
    ) {
    }

    /**
     * As requisições recebidas até agora. Serve para o teste afirmar quantas chamadas uma
     * operação custa — a regra "nada de varredura" só é verificável se dá para contar.
     *
     * @return list<array<string, string|int>>
     */
    public function chamadas(): array
    {
        return $this->chamadas;
    }

    public function get(array $params): RespostaHttp
    {
        $this->chamadas[] = $params;

        $p       = (string) ($params['p'] ?? '');
        $pagina  = max(1, (int) ($params['page'] ?? 1));
        $limite  = max(1, (int) ($params['limit'] ?? 20));
        $partes  = explode('/', $p);

        // ---------------------------------------------------------- busca por documento
        if ($p === 'profissionais') {
            return $this->lista(
                (string) ($params['cpf'] ?? '') === self::CPF_CAPTURADO ? 'profissional_cpf' : null
            );
        }

        if ($p === 'empresas') {
            return $this->lista(
                (string) ($params['cnpj'] ?? '') === self::CNPJ_CAPTURADO ? 'empresa_cnpj' : null
            );
        }

        // ---------------------------------------------------------- validação de documentos
        if ($p === 'arts') {
            return $this->lista($this->validacaoDeArt(
                (string) ($params['rnp'] ?? ''),
                (string) ($params['art_numero'] ?? ''),
            ));
        }

        if ($p === 'cats') {
            $confere = (string) ($params['rnp'] ?? '') === self::RNP_CAPTURADO
                && (string) ($params['cat_numero'] ?? '') === self::CAT_CAPTURADA;

            return $this->lista($confere ? 'cat_validacao' : null);
        }

        if ($p === 'tos') {
            // Uma linha de 2000 foi capturada, então não há como fatiar de verdade. O dicionário
            // TOS vem inteiro da carga inicial (`data/csv/tos.csv`); este endpoint é só conferência.
            return $this->ok($this->arquivo('tos_amostra'));
        }

        // ---------------------------------------------------------- sub-recursos
        if (count($partes) === 3 && $partes[0] === 'profissionais') {
            if ($partes[1] !== self::RNP_CAPTURADO) {
                return new RespostaHttp(404, $this->cru('erro_rnp_inexistente'));
            }

            return match ($partes[2]) {
                'arts'  => $this->ok($this->paginar($this->arquivo('profissional_arts'), $pagina, $limite)),
                'cats'  => $this->ok($this->paginar($this->arquivo('profissional_cats'), $pagina, $limite)),
                default => throw $this->semFixture($p),
            };
        }

        if (count($partes) === 3 && $partes[0] === 'empresas') {
            return match ($partes[2]) {
                'quadro-tecnico' => $partes[1] === self::REGISTRO_CAPTURADO
                    ? $this->ok($this->arquivo('empresa_quadro_tecnico'))
                    : new RespostaHttp(404, $this->cru('erro_rnp_inexistente')),
                'cao' => isset(self::CAOS[$partes[1]])
                    ? $this->ok($this->arquivo(self::CAOS[$partes[1]]))
                    : new RespostaHttp(404, $this->cru('erro_rnp_inexistente')),
                default => throw $this->semFixture($p),
            };
        }

        if (count($partes) === 3 && $partes[0] === 'arts' && $partes[2] === 'atividades') {
            $atividades = $this->atividadesDaArt($partes[1]);

            // ART fora da captura responde 404, e não 200 [] — observado contra a API em
            // 08/09/2026 por `scripts/verificar-api.php`, que é o que autoriza esta linha a
            // existir. Antes disso o fake se recusava a escolher entre os dois.
            if ($atividades === null) {
                return new RespostaHttp(404, $this->cru('erro_art_inexistente'));
            }

            return $this->ok($atividades);
        }

        throw $this->semFixture($p);
    }

    // ---------------------------------------------------------------- derivações verificadas

    /**
     * A resposta de `?p=arts&rnp=&art_numero=` para qualquer das ARTs capturadas, projetada da
     * lista de ARTs da profissional: mesmos campos, menos local e atividades, mais o nome.
     *
     * A projeção não é chute — `CreaApiClientTest` confere que o que sai daqui para a ART
     * AM20269999001 é igual, campo a campo, ao `art_validacao.json` capturado da API. Só por
     * isso as outras três podem ser derivadas com a mesma conta.
     *
     * @return list<array<string, mixed>>|null null quando a combinação não confere (200 [])
     */
    private function validacaoDeArt(string $rnp, string $numero): ?array
    {
        if ($rnp !== self::RNP_CAPTURADO) {
            return null;
        }

        $profissional = $this->arquivo('profissional_cpf')[0];

        foreach ($this->arquivo('profissional_arts')['data'] as $art) {
            if ($art['art_numero'] !== $numero) {
                continue;
            }

            return [[
                'pro_nome'              => $profissional['pro_nome'],
                'pro_rnp'               => $profissional['pro_rnp'],
                'art_numero'            => $art['art_numero'],
                'art_tipo'              => $art['art_tipo'],
                'art_forma_registro'    => $art['art_forma_registro'],
                'art_contratante_nome'  => $art['art_contratante_nome'],
                'art_objeto'            => $art['art_objeto'],
                'art_situacao'          => $art['art_situacao'],
            ]];
        }

        return null;
    }

    /**
     * As atividades TOS de uma ART capturada. Mesma verificação: o teste confere a derivação de
     * AM20269999001 contra `art_atividades.json`.
     *
     * @return list<array<string, mixed>>|null null quando a ART não está na captura
     */
    private function atividadesDaArt(string $numero): ?array
    {
        foreach ($this->arquivo('profissional_arts')['data'] as $art) {
            if ($art['art_numero'] === $numero) {
                return $art['atividades'];
            }
        }

        return null;
    }

    /**
     * Refatia um envelope capturado no tamanho de página pedido.
     *
     * Isto cobre a lacuna que a D08 deixou anotada: as 4 ARTs da captura, pedidas de 2 em 2,
     * dão duas páginas de verdade, e o laço de paginação do cliente passa a ter contra o que
     * ser verificado.
     *
     * A aritmética é nossa, mas não é chute: em 08/09 capturamos `&limit=2` nas duas páginas
     * (`profissional_arts_limite2_p1.json` e `_p2`), e `CreaApiClientTest` confere que o que
     * sai daqui é igual, campo a campo, ao que o servidor devolveu.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function paginar(array $envelope, int $pagina, int $limite): array
    {
        $linhas = $envelope['data'];
        $total  = count($linhas);

        return [
            'pagina_atual'    => $pagina,
            'por_pagina'      => $limite,
            'total_registros' => $total,
            'total_paginas'   => max(1, (int) ceil($total / $limite)),
            'data'            => array_values(array_slice($linhas, ($pagina - 1) * $limite, $limite)),
        ];
    }

    // ---------------------------------------------------------------- leitura e montagem

    /** Resposta de busca em lista: o arquivo, ou o `200 []` que a API devolve quando nada casa. */
    private function lista(string|array|null $conteudo): RespostaHttp
    {
        if ($conteudo === null) {
            return new RespostaHttp(200, '[]');
        }

        return $this->ok(is_string($conteudo) ? $this->arquivo($conteudo) : $conteudo);
    }

    private function ok(array $dados): RespostaHttp
    {
        return new RespostaHttp(200, (string) json_encode($dados, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<mixed> */
    private function arquivo(string $nome): array
    {
        $dados = json_decode($this->cru($nome), true);

        if (!is_array($dados)) {
            throw new LogicException("Fixture {$nome}.json não contém JSON válido.");
        }

        return $dados;
    }

    private function cru(string $nome): string
    {
        $caminho = $this->diretorio . '/' . $nome . '.json';
        $corpo   = @file_get_contents($caminho);

        if ($corpo === false) {
            throw new LogicException("Fixture ausente: {$caminho}");
        }

        return $corpo;
    }

    private function semFixture(string $p, string $motivo = 'não há captura para este recurso'): LogicException
    {
        return new LogicException(
            "TransporteFixture não sabe responder a '?p={$p}': {$motivo}. "
            . 'Capture a resposta real e grave em fixtures/ antes de escrever o teste.'
        );
    }
}
