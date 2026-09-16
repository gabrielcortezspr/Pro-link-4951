<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\CandidatoRepository;
use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\EvidenciaRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Support\Compatibilidade;
use ProLink\Support\Database;
use ProLink\Support\Modalidade;
use ProLink\Support\Tos;
use ProLink\Support\Visibilidade;

/**
 * O motor de compatibilização: a **operação atômica 2** da proposta.
 *
 * Dada uma demanda, encontra quem tem capacidade comprovada de atendê-la e registra a execução
 * inteira, para que ela possa ser explicada e reproduzida depois. Cobre o item 3.2 do edital (as
 * seis dimensões), o 10.1 e o 10.2 (sem ranking, correspondência apenas indicativa) e o 12.3
 * (critérios explicáveis, supervisão humana, auditoria).
 *
 * Este serviço **orquestra e não calcula**: a aritmética mora em `Support\Compatibilidade`, a
 * afinidade em `Support\Tos`, a leitura do índice em `Repository\EvidenciaRepository`. Aqui ficam
 * a ordem dos passos, os portões de privacidade e a transação.
 *
 * Três coisas que não podem ser afrouxadas sem quebrar uma exigência do edital:
 *
 * **O score filtra, não ordena.** Quem passa do limiar entra no pool; a ordem sai do sorteio pela
 * semente. Ordenar por score em qualquer ponto, inclusive na leitura, é ranking (item 10.1).
 *
 * **Perfil fechado nunca entra.** O portão é conferido por candidato, com a mesma regra que
 * governa o perfil público, e antes de qualquer pontuação: quem revogou a exibição não deve nem
 * ser avaliado, quanto mais aparecer.
 *
 * **A sessão grava os pesos vigentes**, não só a referência a `sis_parametros`. O administrador
 * pode recalibrar amanhã, e a sessão de hoje precisa continuar explicável com os números de hoje.
 */
final class CompatibilizacaoService
{
    /** Padrões usados quando `sis_parametros` não tem a chave. Espelham a carga inicial. */
    private const PADRAO = [
        'limiar'          => 0.35,
        'competencia'     => 0.40,
        'area'            => 0.15,
        'localizacao'     => 0.15,
        'experiencia'     => 0.10,
        'contrato'        => 0.10,
        'disponibilidade' => 0.10,
    ];

    private const AFINIDADE_PADRAO = [0.00, 0.15, 0.40, 0.75, 1.00];

    public function __construct(
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly EvidenciaRepository $evidencias = new EvidenciaRepository(),
        private readonly CandidatoRepository $candidatos = new CandidatoRepository(),
        private readonly CompatibilizacaoRepository $sessoes = new CompatibilizacaoRepository(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
        private readonly VisibilidadeService $visibilidade = new VisibilidadeService(),
    ) {
    }

    /**
     * Executa o motor para uma demanda e registra a sessão.
     *
     * @return array{
     *     sessao_id: int, semente: string, limiar: float, pesos: array<string, float>,
     *     avaliados: int, pool: list<array<string, mixed>>
     * }
     * @throws ValidacaoException demanda inexistente ou sem código TOS
     */
    public function executar(int $demandaId, int $usuarioId, ?string $ip = null): array
    {
        $demanda = $this->demandas->porId($demandaId);

        if ($demanda === null) {
            throw new ValidacaoException('Demanda não encontrada.');
        }

        // Sem código TOS não há o que compatibilizar: a TOS é o elo entre necessidade e
        // capacidade, e um pool montado sem ela seria sugestão sem critério.
        $codigos = [];

        // DemandaRepository::tos() já apelida as colunas para 'codigo' e 'peso', e junta a
        // descrição da TOS. Aqui só interessa o par que pondera o cálculo.
        foreach ($this->demandas->tos($demandaId) as $linha) {
            $codigos[(string) $linha['codigo']] = (float) $linha['peso'];
        }

        if ($codigos === []) {
            throw new ValidacaoException(
                'Esta demanda ainda não tem código da Tabela de Obras e Serviços. '
                . 'Selecione ao menos um antes de buscar profissionais.'
            );
        }

        [$limiar, $pesos, $pesosAfinidade] = $this->configuracao();

        $grupos = $this->gruposDe(array_keys($codigos));
        $acervos = $this->evidencias->agrupadoPorCandidato(array_map('strval', $grupos));

        $perfis      = $this->candidatos->porChaves(array_keys($acervos));
        $modalidades = $this->candidatos->modalidadesDe(
            array_column(array_filter($perfis, static fn (array $p): bool => $p['tipo'] === 'P'), 'id')
        );

        // O portão de privacidade de todos os candidatos numa tacada. Perguntar por candidato
        // dentro do laço eram três consultas vezes o número de candidatos, a cada abertura do
        // feed — o N+1 que `CandidatoRepository` evita do lado dos perfis e que o portão
        // reintroduzia logo abaixo.
        $abertos = $this->visibilidade->perfisAbertos(array_column($perfis, 'usuario_id'));

        $pool      = [];
        $avaliados = 0;

        foreach ($acervos as $chave => $acervo) {
            $candidato = $perfis[$chave] ?? null;

            if ($candidato === null || !$this->podeEntrarNoPool($candidato, $demanda, $abertos)) {
                continue;
            }

            $avaliados++;

            $dimensoes = $this->dimensoes($candidato, $acervo, $codigos, $grupos, $demanda, $modalidades, $pesosAfinidade);
            $score     = Compatibilidade::compor($dimensoes['valores'], $pesos);

            if ($score === null || $score < $limiar) {
                continue;
            }

            $pool[] = [
                'chave'     => $chave,
                'tipo'      => $candidato['tipo'],
                'id'        => $candidato['id'],
                'nome'      => $candidato['nome'],
                'score'     => $score,
                'criterios' => [
                    'dimensoes'  => $dimensoes['valores'],
                    'evidencias' => $dimensoes['evidencias'],
                    'ausentes'   => $dimensoes['ausentes'],
                ],
            ];
        }

        // Semente por sessão, 32 caracteres, o tamanho de mts_semente. Aleatória de verdade
        // (random_bytes), porque semente previsível permitiria a alguém posicionar um perfil.
        $semente = bin2hex(random_bytes(16));
        $pool    = Compatibilidade::embaralhar($pool, $semente);

        // Grava já na ordem sorteada: a leitura devolve por msp_id, e assim a ordem exibida é a
        // mesma que ficou registrada, sem depender de reordenar na saída.
        $sessaoId = Database::transacao(
            fn (PDO $pdo): int => (new CompatibilizacaoRepository($pdo))->gravar(
                $demandaId,
                $usuarioId,
                $semente,
                $limiar,
                $pesos,
                $pool,
                $ip,
            )
        );

        return [
            'sessao_id' => $sessaoId,
            'semente'   => $semente,
            'limiar'    => $limiar,
            'pesos'     => $pesos,
            'avaliados' => $avaliados,
            'pool'      => $pool,
        ];
    }

    /**
     * O pool de uma demanda pronto para a tela, vindo da sessão gravada ou de uma execução nova.
     *
     * **Por que não chamar `executar()` a cada abertura do feed.** Ele grava sempre e gera semente
     * nova a cada chamada: usá-lo no GET reembaralharia a lista sob os pés de quem está lendo, e
     * encheria `mat_sessoes` com uma sessão por pageview, poluindo a auditoria que o item 12.3
     * exige. Então a navegação relê a última sessão, e só uma ação explícita do demandante
     * (`$novaSessao`) manda calcular de novo.
     *
     * **O portão de privacidade é reaplicado na leitura, e isso não é redundância.** O pool
     * gravado é o do instante do cálculo; quem revogou `EXIBICAO_PERFIL` depois continua lá.
     * Reexibir sem reconferir mostraria perfil que a pessoa já fechou — o oposto do que a D05 e o
     * controle de visibilidade prometem.
     *
     * **O score não vem junto.** O cálculo já filtrou quem entra; deixar a nota agregada no que
     * chega ao template seria dar ao Twig a chance de imprimir um ranking (item 10.1). As
     * dimensões individuais vêm, porque são o "critério explicável" do Anexo VI.
     *
     * @return array{
     *     sessao_id: int, semente: string, dt_registro: ?string, limiar: float,
     *     pesos: array<string, float>, total_gravado: int, candidatos: list<array<string, mixed>>
     * }
     * @throws ValidacaoException demanda inexistente ou sem código TOS
     */
    public function paraDemanda(
        int $demandaId,
        int $usuarioId,
        ?string $ip = null,
        bool $novaSessao = false,
    ): array {
        $ultima = $novaSessao ? null : ($this->sessoes->daDemanda($demandaId, 1)[0] ?? null);

        if ($ultima === null) {
            $execucao = $this->executar($demandaId, $usuarioId, $ip);
            $sessaoId = $execucao['sessao_id'];
            $cabecalho = [
                'semente'     => $execucao['semente'],
                'dt_registro' => null,
                'limiar'      => $execucao['limiar'],
                'pesos'       => $execucao['pesos'],
            ];
        } else {
            $sessaoId  = (int) $ultima['mts_id'];
            $cabecalho = [
                'semente'     => (string) $ultima['mts_semente'],
                'dt_registro' => $ultima['mts_dt_registro'],
                'limiar'      => (float) $ultima['mts_limiar'],
                'pesos'       => $this->configuracao()[1],
            ];
        }

        $linhas = $this->sessoes->pool($sessaoId);

        return $cabecalho + [
            'sessao_id'     => $sessaoId,
            'total_gravado' => count($linhas),
            'candidatos'    => $this->comporCandidatos($linhas, $usuarioId),
        ];
    }

    /**
     * Costura as linhas gravadas do pool com o perfil atual de cada candidato.
     *
     * `mat_sessao_pool` guarda tipo, id e critérios — não o nome, e nem deveria: nome é dado
     * vivo, e congelá-lo aqui faria a auditoria divergir do cadastro. O snapshot de perfil é
     * outra coisa, e pertence à manifestação (E5).
     *
     * @param  list<array<string, mixed>> $linhas
     * @return list<array<string, mixed>>
     */
    private function comporCandidatos(array $linhas, int $espectadorId): array
    {
        if ($linhas === []) {
            return [];
        }

        $chaves = array_map(
            static fn (array $l): string => $l['msp_candidato_tipo'] . ':' . $l['msp_candidato_id'],
            $linhas,
        );

        $perfis  = $this->candidatos->porChaves($chaves);
        $abertos = $this->visibilidade->perfisAbertos(array_column($perfis, 'usuario_id'));

        $saida = [];

        foreach ($linhas as $linha) {
            $chave     = $linha['msp_candidato_tipo'] . ':' . $linha['msp_candidato_id'];
            $candidato = $perfis[$chave] ?? null;

            // Sumiu do cadastro, ou fechou o perfil depois do cálculo: não reaparece.
            if ($candidato === null || ($abertos[$candidato['usuario_id']] ?? false) !== true) {
                continue;
            }

            $criterios = json_decode((string) $linha['msp_criterios'], true);
            $criterios = is_array($criterios) ? $criterios : [];

            $evidencias = $this->evidenciasVisiveis($criterios['evidencias'] ?? [], $espectadorId);

            $saida[] = [
                'chave'         => $chave,
                'tipo'          => $candidato['tipo'],
                'id'            => $candidato['id'],
                'usuario_id'    => $candidato['usuario_id'],
                'nome'          => $candidato['nome'],
                'rnp'           => $candidato['rnp'] ?? null,
                'registro_crea' => $candidato['registro_crea'] ?? null,
                'resumo'        => $candidato['resumo'] ?? null,
                'em_construcao' => (bool) ($candidato['em_construcao'] ?? false),
                'dimensoes'     => $criterios['dimensoes'] ?? [],
                'evidencias'    => $evidencias,
                'ausentes'      => $criterios['ausentes'] ?? [],
            ];
        }

        return $saida;
    }

    /**
     * Tira das evidências os números de ART que o titular não abriu (D52).
     *
     * **A ART fechada continua contando para o score, e não pode ser citada pelo número.** São
     * duas finalidades distintas: `EXIBICAO_PERFIL` — "autorizo exibir meu perfil para
     * demandantes e na busca" — é o que autoriza ser encontrado, e é o portão que a D22 chama de
     * global; a visibilidade por ART governa o que a vitrine mostra. Fundir as duas faria o
     * controle de exibição virar controle de elegibilidade, punindo com menos correspondências
     * quem usou a privacidade que a plataforma oferece.
     *
     * A evidência **não é descartada** quando fica sem ART aberta: ela guarda o código da TOS e
     * a CAT, que explicam a correspondência sem identificar documento. `arts_fechadas` diz à tela
     * quantas ficaram de fora, para ela ser honesta sobre o que existe e não está aberto em vez
     * de parecer que não há evidência nenhuma.
     *
     * @param  mixed $evidencias como saiu do JSON gravado
     * @return list<array<string, mixed>>
     */
    private function evidenciasVisiveis(mixed $evidencias, int $espectadorId): array
    {
        if (!is_array($evidencias) || $evidencias === []) {
            return [];
        }

        $numeros = [];

        foreach ($evidencias as $evidencia) {
            foreach ((array) ($evidencia['arts'] ?? []) as $numero) {
                $numeros[] = (string) $numero;
            }
        }

        $donos  = $this->evidencias->donosPorNumero($numeros);
        $visoes = $this->visibilidade->visoes(array_column($donos, 'usuario_id'), $espectadorId);

        $saida = [];

        foreach ($evidencias as $evidencia) {
            $abertas  = [];
            $fechadas = 0;

            foreach ((array) ($evidencia['arts'] ?? []) as $numero) {
                $dono = $donos[(string) $numero] ?? null;
                $visao = $dono === null ? null : ($visoes[$dono['usuario_id']] ?? null);

                // ART que não se resolve a um dono ativo não é exibida: falhar fechado é a regra
                // da D22, e aqui o caso acontece quando o profissional foi excluído depois da
                // sessão ter sido gravada.
                if ($visao !== null && $visao->podeVer(Visibilidade::ART, $dono['id'])) {
                    $abertas[] = (string) $numero;
                } else {
                    $fechadas++;
                }
            }

            $saida[] = ['arts' => $abertas, 'arts_fechadas' => $fechadas] + (array) $evidencia;
        }

        return $saida;
    }

    /**
     * Lê limiar, pesos e a escala de afinidade de `sis_parametros`.
     *
     * @return array{0: float, 1: array<string, float>, 2: list<float>}
     */
    private function configuracao(): array
    {
        $limiar = $this->parametros->numero('match.limiar', self::PADRAO['limiar']);

        $pesos = [];

        foreach (Compatibilidade::DIMENSOES as $dimensao) {
            $pesos[$dimensao] = $this->parametros->numero("match.peso.{$dimensao}", self::PADRAO[$dimensao]);
        }

        $bruto = json_decode($this->parametros->texto('match.afinidade.niveis', ''), true);

        // Parâmetro editável pelo administrador: valor malformado cai no padrão em silêncio, em
        // vez de derrubar o feed. A escala precisa ter os cinco níveis para Tos::afinidade().
        $pesosAfinidade = is_array($bruto) && count($bruto) === 5
            ? array_map('floatval', array_values($bruto))
            : self::AFINIDADE_PADRAO;

        return [$limiar, $pesos, $pesosAfinidade];
    }

    /**
     * Portões de entrada no pool, conferidos antes de qualquer pontuação.
     *
     * @param array<string, mixed> $candidato
     * @param array<string, mixed> $demanda
     * @param array<int, bool>     $abertos portão de privacidade já resolvido em lote
     */
    private function podeEntrarNoPool(array $candidato, array $demanda, array $abertos): bool
    {
        // Quem publicou não é candidato da própria demanda.
        if ((int) $candidato['usuario_id'] === (int) $demanda['dem_usu_id']) {
            return false;
        }

        // Registro suspenso ou cancelado no conselho não aparece: a plataforma estaria indicando
        // capacidade que o CREA não sustenta hoje.
        if ($candidato['registro_ativo'] !== true) {
            return false;
        }

        // A demanda escolhe a quem se dirige (Anexo I, item 3). O vocabulário é o de
        // DemandaService::ALVOS: 'P' profissional, 'E' empresa, 'A' ambos. Coincide com o tipo do
        // candidato em crea_evidencias, o que torna a comparação direta.
        $alvo = (string) ($demanda['dem_alvo'] ?? 'A');

        if ($alvo !== 'A' && $alvo !== $candidato['tipo']) {
            return false;
        }

        // O mesmo portão do perfil público: consentimento de exibição revogado, conta excluída ou
        // registro pendente fecham o perfil, e perfil fechado não é oferecido a ninguém.
        //
        // Ausência no mapa é "fechado", nunca "aberto": se um candidato escapou do lote por
        // qualquer motivo, o desfecho seguro é não oferecê-lo.
        return $abertos[(int) $candidato['usuario_id']] ?? false;
    }

    /**
     * As seis dimensões de um candidato, mais as evidências que sustentam a competência.
     *
     * @param  array<string, mixed>       $candidato
     * @param  list<array<string, mixed>> $acervo
     * @param  array<string, float>       $codigos
     * @param  list<int>                  $grupos
     * @param  array<string, mixed>       $demanda
     * @param  array<int, list<string>>   $modalidades
     * @param  list<float>                $pesosAfinidade
     * @return array{valores: array<string, ?float>, evidencias: list<array<string, mixed>>, ausentes: list<string>}
     */
    private function dimensoes(
        array $candidato,
        array $acervo,
        array $codigos,
        array $grupos,
        array $demanda,
        array $modalidades,
        array $pesosAfinidade,
    ): array {
        $competencia = Compatibilidade::competencia($codigos, $acervo, $pesosAfinidade);

        // Empresa não tem modalidade própria: quem tem atribuição é o profissional do quadro
        // técnico. A dimensão de área sai da média para ela, em vez de valer zero.
        $gruposDoCandidato = $candidato['tipo'] === 'P'
            ? Modalidade::gruposDe($modalidades[$candidato['id']] ?? [])
            : [];

        $locais = [];

        foreach ($acervo as $linha) {
            $locais[] = ['uf' => $linha['evi_art_local_uf'], 'municipio' => $linha['evi_art_local_municipio']];
        }

        // A dimensão de experiência olha o que o candidato declarou, não o que casa com a
        // demanda: casar relato livre com código TOS exigiria classificar texto, que é o caminho
        // que o item 12.3 obriga a declarar como uso de IA. Fica autodeclarado e com peso de
        // autodeclarado — e a assinatura de `Compatibilidade::experiencia()` diz isso, em vez de
        // pedir um recorte por demanda que este serviço nunca teve como produzir.
        $valores = [
            'competencia'     => $competencia['score'],
            'area'            => Compatibilidade::area($grupos, $gruposDoCandidato),
            'localizacao'     => Compatibilidade::localizacao(
                $demanda['dem_local_uf'] ?? null,
                $demanda['dem_local_municipio'] ?? null,
                $locais,
            ),
            'experiencia'     => Compatibilidade::experiencia(
                (int) $candidato['total_experiencias'],
                (int) $candidato['experiencias_com_art'],
            ),
            'contrato'        => Compatibilidade::correspondenciaDeclarada(
                $demanda['dem_tipo_contrato'] ?? null,
                $candidato['tipo_contrato'],
            ),
            // A UF da demanda contra a abrangência declarada. Antes isto passava pela mesma
            // `correspondenciaDeclarada()` do contrato, comparando "AM" com um campo de texto
            // livre: nunca casava, e devolvia 0.0 em vez de null.
            'disponibilidade' => Compatibilidade::abrangencia(
                $demanda['dem_local_uf'] ?? null,
                $candidato['disponibilidade'],
            ),
        ];

        return [
            'valores'    => $valores,
            'evidencias' => $competencia['evidencias'],
            // Declarar o que não foi medido é parte de "critérios explicáveis" (12.3): o
            // demandante precisa saber que a nota saiu de quatro dimensões e não de seis.
            'ausentes'   => array_keys(array_filter($valores, static fn (?float $v): bool => $v === null)),
        ];
    }

    /**
     * Primeiros níveis dos códigos pedidos, que é o recorte do índice e também a lista de áreas
     * que a dimensão de área compara.
     *
     * @param  list<string> $codigos
     * @return list<int>
     */
    private function gruposDe(array $codigos): array
    {
        $grupos = [];

        foreach ($codigos as $codigo) {
            $niveis = Tos::niveis($codigo);

            if ($niveis !== []) {
                $grupos[(int) $niveis[0]] = true;
            }
        }

        return array_keys($grupos);
    }
}
