<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\DemandaRepository;
use ProLink\Support\MinhasDemandas;
use ProLink\Support\Vitrine;

/**
 * Minhas demandas com situação, busca e filtros na URL (D93). As demandas de uma conta são
 * poucas, e por isso tudo é carregado e filtrado aqui, sem paginação: o que custa é o painel de
 * cada uma, e ele já era calculado para a lista inteira antes desta mudança.
 */
final class MinhasDemandasService
{
    public function __construct(
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly PainelDemandaService $paineis = new PainelDemandaService(),
    ) {
    }

    /**
     * @param array<string, mixed> $get os parâmetros da URL
     * @return array{demandas: list<array<string, mixed>>, situacoes: array<string, int>,
     *               areas: list<array{nivel1: int, grupo: string, quantidade: int}>,
     *               filtros: array<string, mixed>, total: int, com_pendencia: int}
     */
    public function listar(int $usuarioId, array $get): array
    {
        $f     = MinhasDemandas::lerFiltros($get);
        $todas = $this->demandas->comAtividades($this->demandas->doUsuario($usuarioId));

        foreach ($todas as $i => $d) {
            $todas[$i]['painel']        = $this->paineis->painel($usuarioId, (int) $d['dem_id']);
            $todas[$i]['situacao_aba'] = MinhasDemandas::situacao($d);
        }

        $situacoes = MinhasDemandas::contarSituacoes($todas);

        $lista = array_values(array_filter($todas, static function (array $d) use ($f): bool {
            if ($f['situacao'] !== 'todas' && $d['situacao_aba'] !== $f['situacao']) {
                return false;
            }

            if ($f['texto'] !== null && !MinhasDemandas::bate($d, $f['texto'])) {
                return false;
            }

            return !$f['pendentes'] || MinhasDemandas::temPendencia($d);
        }));

        // Quantas da aba têm algo esperando, antes do próprio filtro de pendência: é o número do
        // botão "Com algo esperando você", e ele não pode zerar ao ser ligado.
        $comPendencia = count(array_filter(
            array_filter($todas, static fn (array $d): bool =>
                $f['situacao'] === 'todas' || $d['situacao_aba'] === $f['situacao']),
            [MinhasDemandas::class, 'temPendencia'],
        ));

        // Os chips contam com todos os filtros menos o de área, como na vitrine.
        $areas = Vitrine::contarAreas($lista);

        if ($f['areas'] !== []) {
            $escolhidas = array_fill_keys($f['areas'], true);
            $lista = array_values(array_filter($lista, static function (array $d) use ($escolhidas): bool {
                foreach ($d['tos'] as $t) {
                    if (isset($escolhidas[(int) $t['tos_nivel1']])) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $lista = MinhasDemandas::ordenar($lista, $f['ordem']);

        return [
            'demandas'      => $lista,
            'situacoes'     => $situacoes,
            'areas'         => $areas,
            'filtros'       => $f,
            'total'         => count($lista),
            'com_pendencia' => $comPendencia,
        ];
    }
}
