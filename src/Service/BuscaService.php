<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\BuscaRepository;
use ProLink\Support\Tos;
use ProLink\Support\Visibilidade;

/**
 * A busca ativa de profissionais (RF04; Anexo I item 3).
 *
 * O edital dá a busca ao perfil **Público**, isto é, também a quem não tem conta. É por isso que
 * o alcance da visibilidade importa tanto aqui: `Visibilidade::alcanceDe()` já modela que anônimo
 * enxerga só o que foi marcado `PUBLICO`, e quem entrou enxerga também o de `AUTENTICADO`.
 *
 * ## Três regras, e nenhuma delas é nova
 *
 * **O portão global vale igual ao do motor.** Perfil sem `EXIBICAO_PERFIL` vigente, ou com
 * registro irregular no CREA, não aparece na busca pelo mesmo `perfisAbertos()` que decide o
 * pool. Um candidato que não entra no feed não pode ser encontrado por outro caminho.
 *
 * **Identidade não é ocultável (D23).** Quem está aberto aparece com nome e registro, mesmo que
 * tenha fechado todo o resto. Quem não quer ser encontrado revoga a exibição, que fecha o perfil
 * inteiro — não existe estado "apareço na busca mas sem nome".
 *
 * **Nada de ranking (item 10.1).** A ordem é alfabética, que é filtro e não mérito: o termo
 * decide quem entra, e dentro de quem entrou ninguém está na frente de ninguém. O motor é o
 * outro caminho, e lá a ordem sai do sorteio pela semente.
 */
final class BuscaService
{
    public function __construct(
        private readonly BuscaRepository $busca = new BuscaRepository(),
        private readonly VisibilidadeService $visibilidade = new VisibilidadeService(),
    ) {
    }

    /**
     * @return array{
     *     termo: string, em_construcao: bool, truncado: bool, total: int,
     *     resultados: list<array<string, mixed>>
     * }
     */
    public function profissionais(string $termo, bool $emConstrucao, ?int $espectadorId): array
    {
        $termo = trim($termo);

        // Uma linha a mais que o teto revela que havia mais — sem um segundo COUNT sobre a mesma
        // consulta, que com os cinco EXISTS custaria o dobro para responder "tem mais".
        $linhas   = $this->busca->profissionais($termo, $emConstrucao);
        $truncado = count($linhas) > BuscaRepository::LIMITE;
        $linhas   = array_slice($linhas, 0, BuscaRepository::LIMITE);

        if ($linhas === []) {
            return [
                'termo' => $termo, 'em_construcao' => $emConstrucao,
                'truncado' => false, 'total' => 0, 'resultados' => [],
            ];
        }

        $abertos = $this->visibilidade->perfisAbertos(array_column($linhas, 'prf_usu_id'));
        $visoes  = $this->visibilidade->visoes(array_column($linhas, 'prf_usu_id'), $espectadorId);
        $ids          = array_map('intval', array_column($linhas, 'prf_id'));
        $acervos      = $this->busca->acervoPorArtEmLote($ids);
        $modalidades  = $this->busca->modalidadesEmLote($ids);
        $experiencias = $termo === '' ? [] : $this->busca->experienciasEmLote($ids);

        $resultados = [];

        foreach ($linhas as $linha) {
            $usuarioId = (int) $linha['prf_usu_id'];

            if (($abertos[$usuarioId] ?? false) !== true) {
                continue;
            }

            $visao  = $visoes[$usuarioId] ?? null;
            $prfId  = (int) $linha['prf_id'];

            // Só o acervo que o titular abriu para quem busca (D95): a ART fechada não conta no
            // cartão, e a CAT segue a ART que certifica.
            $artsVisiveis = array_values(array_filter(
                $acervos[$prfId] ?? [],
                static fn (array $a): bool => $visao !== null && $visao->podeVer(Visibilidade::ART, $a['art_id']),
            ));
            $acervo = self::resumirAcervo($artsVisiveis);

            // Campo a campo, pela mesma Visao que monta o perfil. O resumo de quem só abriu para
            // autenticados não pode vazar no resultado de busca de um anônimo — seria a tela de
            // busca contornando a escolha que a tela de perfil respeita.
            $podeVer = static fn (string $campo): bool =>
                $visao !== null && $visao->podeVer(Visibilidade::PERFIL, null, $campo);

            // O banco achou o termo em qualquer lugar do perfil; aqui só vale se ele estiver no que
            // o titular abriu para quem busca (D95). Resumo, experiência ou ART fechada não fazem
            // ninguém ser encontrado, senão a busca revelaria o que a tela do perfil esconde.
            if ($termo !== '') {
                $textos = [(string) $linha['nome'], (string) $linha['usu_nome'], ...($modalidades[$prfId] ?? [])];

                if ($podeVer('RESUMO')) {
                    $textos[] = (string) $linha['prf_resumo'];
                }

                foreach ($experiencias[$prfId] ?? [] as $e) {
                    if ($visao !== null && $visao->podeVer(Visibilidade::EXPERIENCIA, $e['exp_id'])) {
                        $textos[] = $e['titulo'] . ' ' . $e['descricao'];
                    }
                }

                foreach ($artsVisiveis as $a) {
                    $textos[] = $a['grupo'] . ' ' . $a['subgrupo'];
                }

                if (!str_contains(Tos::normalizar(implode(' | ', $textos)), Tos::normalizar($termo))) {
                    continue;
                }
            }

            $resultados[] = [
                'usuario_id'      => $usuarioId,
                'nome'            => (string) $linha['nome'],
                'rnp'             => $linha['prf_rnp'],
                'registro_crea'   => $linha['prf_registro_crea'],
                'em_construcao'   => (bool) $linha['prf_em_construcao'],
                'arts'            => $acervo['arts'],
                'cats'            => $acervo['cats'],
                'grupos'          => $acervo['grupos'],
                'subgrupos'       => $acervo['subgrupos'],

                // A modalidade não passa pela Visao: ela é parte da identidade profissional, que
                // a D23 declarou não-ocultável. Perfil sem nome e sem registro não identifica
                // ninguém, e modalidade é o registro dizendo em que a pessoa é habilitada.
                'modalidades'     => $modalidades[(int) $linha['prf_id']] ?? [],
                'resumo'          => $podeVer('RESUMO') ? $linha['prf_resumo'] : null,
                'tipo_contrato'   => $podeVer('TIPO_CONTRATO') ? $linha['prf_tipo_contrato'] : null,
                'disponibilidade' => $podeVer('DISPONIBILIDADE') ? $linha['prf_disponibilidade'] : null,
            ];
        }

        return [
            'termo'         => $termo,
            'em_construcao' => $emConstrucao,
            'truncado'      => $truncado,
            'total'         => count($resultados),
            'resultados'    => $resultados,
        ];
    }

    /**
     * @param list<array{art_id: int, art_numero: string, cat: ?string, grupo: string, subgrupo: string}> $arts
     * @return array{arts: int, cats: int, grupos: list<string>, subgrupos: list<string>}
     */
    private static function resumirAcervo(array $arts): array
    {
        $grupos    = array_values(array_unique(array_column($arts, 'grupo')));
        $subgrupos = array_values(array_unique(array_column($arts, 'subgrupo')));
        sort($grupos);
        sort($subgrupos);

        return [
            'arts'      => count(array_unique(array_column($arts, 'art_numero'))),
            'cats'      => count(array_unique(array_filter(array_column($arts, 'cat')))),
            'grupos'    => $grupos,
            'subgrupos' => $subgrupos,
        ];
    }
}
