<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\DemandaRepository;
use ProLink\Repository\EmpresaRepository;
use ProLink\Repository\EvidenciaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Support\Vitrine;

/**
 * A vitrine de demandas abertas, filtrada e organizada pela Tabela de Obras e Serviços (D89).
 *
 * O banco resolve o que filtra bem em SQL (local, regime, público, prazo, busca livre); o resto
 * precisa das atividades de cada demanda e é decidido aqui, pela `Support\Vitrine`: a área, a
 * atividade, a relação com o acervo de quem olha, a contagem por área, a ordem e a página.
 */
final class VitrineService
{
    private const AFINIDADE_PADRAO = [0.00, 0.15, 0.40, 0.75, 1.00];

    public function __construct(
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly EvidenciaRepository $evidencias = new EvidenciaRepository(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
        private readonly ManifestacaoRepository $manifestacoes = new ManifestacaoRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $get os parâmetros da URL
     * @param string|null $perfil o código do perfil de quem olha (PROFISSIONAL, EMPRESA...)
     * @return array{demandas: list<array<string, mixed>>, areas: list<array{nivel1: int, grupo: string, quantidade: int}>,
     *               filtros: array<string, mixed>, total: int, pagina: int, paginas: int,
     *               tem_acervo: bool, pode_candidatar: bool, sem_contato: int,
     *               areas_acervo: list<array{nivel1: int, grupo: string, arts: int}>,
     *               modalidades: list<string>}
     */
    public function buscar(array $get, ?int $usuarioId, ?string $perfil): array
    {
        [$tipo, $candidatoId] = $this->quemOlha($usuarioId, $perfil);

        $acervo = $candidatoId === null ? [] : $this->evidencias->codigosDoCandidato($tipo, $candidatoId);
        $pode   = $candidatoId !== null;
        $f      = Vitrine::lerFiltros($get, date('Y-m-d'), $acervo !== [], $pode);

        $linhas = $this->demandas->vitrine([
            'uf'        => $f['uf'],
            'municipio' => $f['municipio'],
            'contrato'  => $f['contrato'],
            'alvos'     => $f['para_mim'] ? ['A', $tipo] : [],
            'inicio'    => $f['inicio'],
            'ate'       => $f['ate'],
            'texto'     => $f['texto'],
        ]);

        $pesos = $this->pesosAfinidade();

        // A relação com o acervo vai em toda demanda, e não só quando o filtro está ligado: o
        // cartão a mostra mesmo em "ver todas", para quem olha saber onde já tem acervo.
        // A candidatura ou o convite que já existe entre quem olha e a demanda: o cartão mostra
        // "Candidatura enviada" ou "Você foi convidado" em vez de oferecer o mesmo passo de novo.
        $contatos = [];

        if ($usuarioId !== null) {
            foreach ($this->manifestacoes->doUsuario($usuarioId) as $m) {
                $contatos[(int) $m['dem_id']] = ['man_id' => (int) $m['man_id'], 'origem' => (string) $m['man_origem']];
            }
        }

        foreach ($linhas as $i => $d) {
            $linhas[$i]['relacao_acervo'] = $acervo === []
                ? null
                : Vitrine::relacaoComAcervo(array_column($d['tos'], 'dts_tos_codigo'), $acervo, $pesos);
            $linhas[$i]['meu_contato'] = $contatos[(int) $d['dem_id']] ?? null;
            $linhas[$i]['propria']     = $usuarioId !== null && (int) $d['dem_usu_id'] === $usuarioId;
        }

        $linhas = array_values(array_filter($linhas, static function (array $d) use ($f): bool {
            if ($f['atividade'] !== null && !Vitrine::temAtividade($d['tos'], $f['atividade'])) {
                return false;
            }

            return !$f['minha_area'] || $d['relacao_acervo'] !== null;
        }));

        // Os chips contam o que sobra com todos os filtros menos o de área: é o que diz "se eu
        // clicar em Mecânica, vejo 3".
        $areas = Vitrine::contarAreas($linhas);

        if ($f['areas'] !== []) {
            $escolhidas = array_fill_keys($f['areas'], true);
            $linhas = array_values(array_filter($linhas, static function (array $d) use ($escolhidas): bool {
                foreach ($d['tos'] as $t) {
                    if (isset($escolhidas[(int) $t['tos_nivel1']])) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $linhas  = Vitrine::ordenar($linhas, $f['ordem']);
        $total   = count($linhas);
        $paginas = max(1, (int) ceil($total / Vitrine::POR_PAGINA));
        $pagina  = min($f['pagina'], $paginas);

        return [
            'demandas'        => array_slice($linhas, ($pagina - 1) * Vitrine::POR_PAGINA, Vitrine::POR_PAGINA),
            'areas'           => $areas,
            'filtros'         => $f,
            'total'           => $total,
            'pagina'          => $pagina,
            'paginas'         => $paginas,
            'tem_acervo'      => $acervo !== [],
            // De onde vem "na sua área" (D92): as áreas das atividades das ARTs de quem olha, que
            // não são a modalidade do registro e com a massa de dados nem precisam parecer com ela.
            'areas_acervo'    => $candidatoId === null ? [] : $this->evidencias->areasDoCandidato($tipo, $candidatoId),
            // A modalidade do registro, só do profissional (a empresa não tem): a tela a põe ao lado
            // das áreas do acervo para que "registro em Geografia" e "na sua área: Mecânica" não
            // pareçam se contradizer.
            'modalidades'     => $tipo === 'P' && $candidatoId !== null
                ? array_column((new ProfissionalRepository())->modalidades($candidatoId), 'mod_nome')
                : [],
            'pode_candidatar' => $pode,
            // Quantas, de todas as páginas, ainda não têm candidatura nem convite de quem olha.
            'sem_contato'     => count(array_filter(
                $linhas,
                static fn (array $d): bool => $d['meu_contato'] === null && !$d['propria'],
            )),
        ];
    }

    /** @return array{0: string, 1: ?int} tipo do candidato ('P'/'E') e o id dele, se houver */
    private function quemOlha(?int $usuarioId, ?string $perfil): array
    {
        if ($usuarioId === null) {
            return ['P', null];
        }

        if ($perfil === PERFIL_PROFISSIONAL) {
            $p = (new ProfissionalRepository())->porUsuario($usuarioId);

            return ['P', $p === null ? null : (int) $p['prf_id']];
        }

        if ($perfil === PERFIL_EMPRESA) {
            $e = (new EmpresaRepository())->porUsuario($usuarioId);

            return ['E', $e === null ? null : (int) $e['emp_id']];
        }

        return ['P', null];
    }

    /** @return list<float> */
    private function pesosAfinidade(): array
    {
        $bruto = json_decode($this->parametros->texto('match.afinidade.niveis', ''), true);

        return is_array($bruto) && count($bruto) === 5
            ? array_map('floatval', array_values($bruto))
            : self::AFINIDADE_PADRAO;
    }
}
