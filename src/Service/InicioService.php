<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\AtividadeRecenteRepository;
use ProLink\Repository\DashboardRepository;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\MensagemRepository;
use ProLink\Support\Inicio;

/**
 * O Início de quem está logado (D90), em três blocos que respondem a três perguntas:
 *
 * 1. **O que eu preciso fazer agora?** Convites, candidaturas e mensagens esperando resposta,
 *    rascunhos, prazos e o que falta no perfil. Cada item leva a quem o resolve.
 * 2. **O que está acontecendo no meu trabalho?** Para quem tem acervo, as demandas abertas na área
 *    dele, pela mesma regra da vitrine (D89); para quem publica, as próprias demandas com o que
 *    cada uma espera.
 * 3. **O que aconteceu?** A linha do tempo da conta, sem aviso de leitura (D88).
 *
 * Os KPIs de antes saíram: eram contagens sem ação ("ARTs no acervo: 3"), e o que delas ainda
 * orienta a pessoa virou uma linha discreta de identidade no topo.
 */
final class InicioService
{
    /** Demandas da área mostradas no Início; o resto está a um clique, na vitrine. */
    public const NA_AREA = 3;

    /** Demandas próprias listadas no Início. */
    public const PROPRIAS = 5;

    /** Acontecimentos na linha do tempo. */
    public const ATIVIDADES = 8;

    public function __construct(
        private readonly DashboardRepository $dashboard = new DashboardRepository(),
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly MensagemRepository $mensagens = new MensagemRepository(),
        private readonly AtividadeRecenteRepository $atividades = new AtividadeRecenteRepository(),
        private readonly PainelDemandaService $paineis = new PainelDemandaService(),
        private readonly VisibilidadeService $visibilidade = new VisibilidadeService(),
        private readonly AtualizacaoAcervoService $atualizacao = new AtualizacaoAcervoService(),
        private readonly VitrineService $vitrine = new VitrineService(),
    ) {
    }

    /**
     * @return array{identidade: ?array<string, mixed>, para_fazer: list<array<string, mixed>>,
     *               na_area: ?array<string, mixed>, proprias: ?array<string, mixed>,
     *               atividade: list<array<string, mixed>>}
     */
    public function montar(int $usuarioId, string $perfil, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');

        $ehProfissional = $perfil === PERFIL_PROFISSIONAL;
        $temRegistro    = $ehProfissional || $perfil === PERFIL_EMPRESA;
        $publica        = in_array($perfil, PERFIS_DEMANDANTES, true);

        $identidade = null;
        $candidato  = null;

        if ($temRegistro) {
            $identidade = $ehProfissional
                ? ['tipo' => 'P'] + $this->dashboard->resumoDoProfissional($usuarioId, $hoje)
                : ['tipo' => 'E', 'preferencias_vazias' => false] + $this->dashboard->resumoDaEmpresa($usuarioId);

            $identidade['perfil_aberto'] = $this->visibilidade->perfilAberto($usuarioId);

            $candidato = [
                'convites_novos'      => $this->dashboard->naoVistasPorPapel($usuarioId)['candidato'],
                'nao_lidas'           => $this->mensagens->naoLidasPorPapel($usuarioId)['candidato'],
                'perfil_aberto'       => $identidade['perfil_aberto'],
                'preferencias_vazias' => $identidade['preferencias_vazias'],
                'acervo_em'           => $identidade['acervo_em'],
                // Dentro da janela da última atualização (D77) o botão não funcionaria; sugerir
                // atualizar ali seria mandar a pessoa a uma tela que diz "espere".
                'pode_atualizar'      => $this->atualizacao->janela($usuarioId)['libera'] === null,
            ];
        }

        $proprias = null;
        $demandas = [];

        if ($publica) {
            $demandas = $this->demandasComPainel($usuarioId);
            $proprias = [
                'demandas' => array_slice($demandas, 0, self::PROPRIAS),
                'total'    => count($demandas),
            ];
        }

        $naArea = null;

        if ($temRegistro) {
            // A vitrine sem filtro na URL já é "na minha área" para quem tem acervo (D89); o
            // Início mostra o começo dela e leva ao resto, com a mesma contagem.
            // Mostra primeiro o que ainda não tem candidatura nem convite: o que já tem está em
            // Candidaturas e convites, e repeti-lo aqui ocuparia o bloco com o que já foi feito.
            $busca  = $this->vitrine->buscar([], $usuarioId, $perfil);
            $livres = array_values(array_filter(
                $busca['demandas'],
                static fn (array $d): bool => $d['meu_contato'] === null && !$d['propria'],
            ));
            $naArea = [
                'demandas'     => array_slice($livres, 0, self::NA_AREA),
                'total'        => $busca['total'],
                'sem_contato'  => $busca['sem_contato'],
                'tem_acervo'   => $busca['tem_acervo'],
                'areas_acervo' => $busca['areas_acervo'],
                'modalidades'  => $busca['modalidades'],
            ];
        }

        return [
            'identidade' => $identidade,
            'para_fazer' => Inicio::paraFazer($candidato, $demandas, $hoje),
            'na_area'    => $naArea,
            'proprias'   => $proprias,
            'atividade'  => $this->atividades->doUsuario($usuarioId, self::ATIVIDADES),
        ];
    }

    /**
     * As demandas da conta, as abertas primeiro, cada uma publicada e aberta com os contadores do
     * painel (D87). Rascunho e encerrada não têm contato a contar.
     *
     * @return list<array<string, mixed>>
     */
    private function demandasComPainel(int $usuarioId): array
    {
        $demandas = $this->demandas->doUsuario($usuarioId);

        foreach ($demandas as &$d) {
            $aberta = $d['dem_dt_publicacao'] !== null && $d['dem_situacao'] !== 'ENCERRADA';
            $d['painel'] = $aberta ? $this->paineis->painel($usuarioId, (int) $d['dem_id']) : null;
        }
        unset($d);

        // Encerrada por último: continua listada, mas não pede nada.
        usort($demandas, static fn (array $a, array $b): int =>
            [($a['dem_situacao'] === 'ENCERRADA'), -(int) $a['dem_id']]
            <=> [($b['dem_situacao'] === 'ENCERRADA'), -(int) $b['dem_id']]);

        return $demandas;
    }
}
