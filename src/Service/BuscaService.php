<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\BuscaRepository;
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
        $ids         = array_map('intval', array_column($linhas, 'prf_id'));
        $acervos     = $this->busca->acervoEmLote($ids);
        $modalidades = $this->busca->modalidadesEmLote($ids);

        $resultados = [];

        foreach ($linhas as $linha) {
            $usuarioId = (int) $linha['prf_usu_id'];

            if (($abertos[$usuarioId] ?? false) !== true) {
                continue;
            }

            $visao  = $visoes[$usuarioId] ?? null;
            $acervo = $acervos[(int) $linha['prf_id']] ?? ['arts' => 0, 'cats' => 0, 'grupos' => [], 'subgrupos' => []];

            // Campo a campo, pela mesma Visao que monta o perfil. O resumo de quem só abriu para
            // autenticados não pode vazar no resultado de busca de um anônimo — seria a tela de
            // busca contornando a escolha que a tela de perfil respeita.
            $podeVer = static fn (string $campo): bool =>
                $visao !== null && $visao->podeVer(Visibilidade::PERFIL, null, $campo);

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
}
