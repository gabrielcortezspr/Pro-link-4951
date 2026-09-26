<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Repository\DashboardRepository;
use ProLink\Repository\DispensaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\MensagemRepository;
use ProLink\Support\Auditoria;

/**
 * O que a dona de uma demanda ainda tem para fazer, em números, e a triagem do feed (D87).
 *
 * Um par demanda × perfil está em uma de quatro situações, e as telas passaram a dizê-las:
 *
 * - **a analisar**: está no conjunto de compatíveis e ninguém agiu ainda;
 * - **candidatura**: o titular se candidatou pela vitrine (`man_origem = 'C'`);
 * - **convite**: a empresa chamou o perfil a partir dos compatíveis (`man_origem = 'D'`);
 * - **dispensado**: a empresa olhou e tirou da lista (`pro_dispensas`), sem avisar ninguém.
 *
 * Candidatura e convite são os **contatos** da demanda, porque os dois viram conversa. O feed de
 * compatíveis mostra só quem está "a analisar": quem já é contato ou foi dispensado sai dele,
 * porque a empresa já decidiu sobre essa pessoa. A sessão do motor continua gravando o conjunto
 * inteiro (D55), então a auditoria do sorteio não muda.
 *
 * Os números vêm do que já foi calculado (a última sessão gravada), e não de rodar o motor a
 * cada tela: "a analisar" é nulo enquanto a demanda nunca teve sessão, e a tela diz isso em vez
 * de inventar um número.
 */
final class PainelDemandaService
{
    public function __construct(
        private readonly DemandaService $demandas = new DemandaService(),
        private readonly ManifestacaoRepository $manifestacoes = new ManifestacaoRepository(),
        private readonly MensagemRepository $mensagens = new MensagemRepository(),
        private readonly DispensaRepository $dispensas = new DispensaRepository(),
        private readonly CompatibilizacaoRepository $sessoes = new CompatibilizacaoRepository(),
        private readonly VisibilidadeService $visibilidade = new VisibilidadeService(),
        private readonly DashboardRepository $dashboard = new DashboardRepository(),
    ) {
    }

    /**
     * Os contadores de uma demanda, para as abas e para a lista de "Minhas demandas".
     *
     * @return array{a_analisar: ?int, candidaturas: int, candidaturas_novas: int, convites: int,
     *               nao_lidas: int, contatos: int, dispensados: int, pendentes: int}
     */
    public function painel(int $donoId, int $demandaId): array
    {
        $contatos   = $this->manifestacoes->daDemanda($demandaId);
        $naoLidas   = $this->mensagens->naoLidasEmLote(array_map('intval', array_column($contatos, 'man_id')), $donoId);
        $dispensados = $this->dispensas->daDemanda($demandaId);

        $candidaturas = array_filter($contatos, static fn (array $m): bool => ($m['man_origem'] ?? 'C') === 'C');
        $novas = array_filter($candidaturas, static fn (array $m): bool => ($m['man_dt_visualizacao'] ?? null) === null);

        $excluidos = array_merge(
            array_map('intval', array_column($contatos, 'man_usu_id')),
            array_column($dispensados, 'usuario_id'),
        );

        $aAnalisar = $this->aAnalisar($demandaId, $excluidos);
        $naoLidasTotal = array_sum($naoLidas);

        return [
            'a_analisar'         => $aAnalisar,
            'candidaturas'       => count($candidaturas),
            'candidaturas_novas' => count($novas),
            'convites'           => count($contatos) - count($candidaturas),
            'nao_lidas'          => $naoLidasTotal,
            'contatos'           => count($contatos),
            'dispensados'        => count($dispensados),
            // O que pede ação da empresa agora: candidatura que ela ainda não abriu e mensagem
            // que ela ainda não leu. "A analisar" fica fora de propósito: é o tamanho do
            // conjunto, e somá-lo aqui faria toda demanda parecer urgente o tempo todo.
            'pendentes'          => count($novas) + $naoLidasTotal,
        ];
    }

    /**
     * Os titulares que o feed não mostra mais: contatos e dispensados.
     *
     * @return array<int, true> usuario_id => true
     */
    public function jaAnalisados(int $demandaId): array
    {
        $ids = array_merge(
            array_map('intval', array_column($this->manifestacoes->daDemanda($demandaId), 'man_usu_id')),
            array_column($this->dispensas->daDemanda($demandaId), 'usuario_id'),
        );

        return array_fill_keys($ids, true);
    }

    /** @return list<array{usuario_id: int, nome: string, dt_registro: string}> */
    public function dispensados(int $donoId, int $demandaId): array
    {
        $this->demandas->exigirPropria($donoId, $demandaId);

        return $this->dispensas->daDemanda($demandaId);
    }

    /**
     * Tira o perfil da lista de quem falta avaliar. Não avisa o titular: a recusa silenciosa é o
     * que se espera numa triagem, e avisar exporia a pessoa a um "não" que ela não pediu.
     *
     * @throws ValidacaoException demanda de outra pessoa, ou perfil que já é contato
     */
    public function dispensar(int $donoId, int $demandaId, int $candidatoId): void
    {
        $this->demandas->exigirPropria($donoId, $demandaId);

        if ($candidatoId <= 0 || $candidatoId === $donoId) {
            throw new ValidacaoException('Perfil inválido para dispensar.');
        }

        if ($this->manifestacoes->idDoPar($demandaId, $candidatoId) !== null) {
            throw new ValidacaoException(
                'Este perfil já está nos contatos desta demanda: a conversa está aberta em Contatos.'
            );
        }

        $this->dispensas->dispensar($demandaId, $candidatoId, $donoId);

        Auditoria::registrar(
            Auditoria::DISPENSAR, 'pro_dispensas', $demandaId, 'dsp_usu_id', null, $candidatoId, $donoId,
        );
    }

    /** Devolve o perfil à lista de quem falta avaliar. */
    public function desfazerDispensa(int $donoId, int $demandaId, int $candidatoId): void
    {
        $this->demandas->exigirPropria($donoId, $demandaId);

        if ($this->dispensas->desfazer($demandaId, $candidatoId)) {
            Auditoria::registrar(
                Auditoria::RESTAURAR, 'pro_dispensas', $demandaId, 'dsp_usu_id', $candidatoId, null, $donoId,
            );
        }
    }

    /**
     * O que espera por este usuário em toda a plataforma, para o sino e o menu: candidaturas
     * que ele recebeu e não abriu, convites que recebeu e não abriu, e mensagens não lidas.
     *
     * `como_demandante` mora em Minhas demandas; `como_candidato`, em Candidaturas e convites.
     * Uma empresa pode ter os dois, e cada item do menu mostra o seu.
     *
     * @return array{nao_vistas: int, nao_lidas: int, total: int, como_demandante: int, como_candidato: int}
     */
    public function pendenciasDoUsuario(int $usuarioId): array
    {
        $vistas = $this->dashboard->naoVistasPorPapel($usuarioId);
        $lidas  = $this->mensagens->naoLidasPorPapel($usuarioId);

        $naoVistas = $vistas['demandante'] + $vistas['candidato'];
        $naoLidas  = $lidas['demandante'] + $lidas['candidato'];

        return [
            'nao_vistas'      => $naoVistas,
            'nao_lidas'       => $naoLidas,
            'total'           => $naoVistas + $naoLidas,
            'como_demandante' => $vistas['demandante'] + $lidas['demandante'],
            'como_candidato'  => $vistas['candidato'] + $lidas['candidato'],
        ];
    }

    /** @param list<int> $excluidos */
    private function aAnalisar(int $demandaId, array $excluidos): ?int
    {
        $pool = $this->sessoes->usuariosDoUltimoPool($demandaId);

        if ($pool === null) {
            return null;
        }

        // Mesmo portão do feed: quem fechou o perfil depois da sessão não aparece.
        $abertos = $this->visibilidade->perfisAbertos($pool);
        $fora    = array_fill_keys($excluidos, true);

        return count(array_filter(
            $pool,
            static fn (int $id): bool => ($abertos[$id] ?? false) === true && !isset($fora[$id]),
        ));
    }
}
