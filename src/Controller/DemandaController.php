<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\DemandaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\TosRepository;
use ProLink\Service\DemandaService;
use ProLink\Service\PainelDemandaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\Preferencias;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * Demandas técnicas (RF04; cenário 2 do Anexo I).
 *
 * A demanda nasce rascunho e só aparece para os outros depois de publicada — e publicar exige
 * pelo menos um código TOS, porque é por ele que o motor enxerga. Por isso a tela de detalhe é
 * onde os códigos são escolhidos: a busca precisa de uma demanda já salva para pendurar o
 * resultado, e um formulário de criação que carregasse buscas no meio perderia o que foi digitado.
 */
final class DemandaController
{
    public function __construct(
        private readonly DemandaService $demandas = new DemandaService(),
        private readonly DemandaRepository $repositorio = new DemandaRepository(),
        private readonly TosRepository $tos = new TosRepository(),
    ) {
    }

    /**
     * Painel do demandante: as demandas dele, cada uma com o que ainda falta fazer nela (D87):
     * quantos perfis faltam avaliar, candidaturas novas e mensagens não lidas.
     */
    public function index(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $demandas  = $this->repositorio->doUsuario($usuarioId);
        $painel    = new PainelDemandaService();

        foreach ($demandas as $i => $d) {
            $demandas[$i]['painel'] = $painel->painel($usuarioId, (int) $d['dem_id']);
        }

        return View::render('demanda/index.html.twig', [
            'titulo'   => 'Minhas demandas',
            'demandas' => $demandas,
        ]);
    }

    /** A vitrine: demandas publicadas e abertas, para quem procura oportunidade. */
    public function abertas(): string
    {
        return View::render('demanda/abertas.html.twig', [
            'titulo'   => 'Demandas abertas',
            'demandas' => $this->repositorio->abertas(),
        ]);
    }

    public function formulario(): string
    {
        return View::render('demanda/nova.html.twig', [
            'titulo'  => 'Nova demanda',
            'valores' => [],
            'erros'   => [],
            ...self::vocabulario(),
        ]);
    }

    public function criar(): string
    {
        try {
            $id = $this->demandas->criar((int) Sessao::usuarioId(), $_POST);
        } catch (ValidacaoException $e) {
            return View::render('demanda/nova.html.twig', [
                'titulo'  => 'Nova demanda',
                'valores' => $_POST,
                'erros'   => $e->erros(),
                'aviso'   => $e->getMessage(),
                ...self::vocabulario(),
            ]);
        }

        Flash::sucesso('Demanda criada como rascunho. Escolha os códigos da Tabela de Obras e '
            . 'Serviços e publique quando estiver pronta.');
        View::redirecionar('/demandas/' . $id);
    }

    /**
     * Detalhe da demanda. O dono edita e escolhe códigos; os outros só leem, e só se publicada.
     */
    public function ver(string $id): string
    {
        $usuarioId = Sessao::usuarioId();
        $demanda   = $this->demandas->comTos((int) $id);

        if ($demanda === null) {
            return View::erro(404, 'Demanda não encontrada.');
        }

        $ehDono = $usuarioId !== null && (int) $demanda['dem_usu_id'] === $usuarioId;

        // Rascunho é do dono e de mais ninguém: sem a data de publicação a demanda não existe
        // para o resto da plataforma.
        if (!$ehDono && $demanda['dem_dt_publicacao'] === null) {
            return View::erro(404, 'Demanda não encontrada.');
        }

        $busca = trim((string) ($_GET['q'] ?? ''));

        return View::render('demanda/ver.html.twig', [
            'titulo'    => $demanda['dem_titulo'],
            'demanda'   => $demanda,
            'eh_dono'   => $ehDono,
            // Os contadores das abas da demanda (D87), só para a dona.
            'painel'    => $ehDono ? (new PainelDemandaService())->painel((int) $usuarioId, (int) $id) : null,
            'busca'     => $busca,
            'resultados' => $ehDono && $busca !== '' ? $this->resultados($busca) : [],

            // Quem já manifestou não pode ver o botão de manifestar: a segunda tentativa é
            // recusada pelo índice único, e descobrir isso depois de preencher a confirmação é
            // o pior momento. Null quando ainda não manifestou, ou quando quem olha é o dono.
            'manifestacao_id' => $ehDono || $usuarioId === null
                ? null
                : (new ManifestacaoRepository())->idDoPar((int) $id, $usuarioId),
            'erros'     => [],
            ...self::vocabulario(),
        ]);
    }

    public function editar(string $id): string
    {
        try {
            $this->demandas->editar((int) Sessao::usuarioId(), (int) $id, $_POST);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id);
        }

        Flash::sucesso('Demanda atualizada.');
        View::redirecionar('/demandas/' . (int) $id);
    }

    /** Acrescenta, remove ou muda o peso de um código TOS. */
    public function alterarTos(string $id): string
    {
        try {
            $this->demandas->alterarTos(
                (int) Sessao::usuarioId(),
                (int) $id,
                (string) ($_POST['codigo'] ?? ''),
                (string) ($_POST['acao'] ?? 'adicionar'),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id);
        }

        View::redirecionar('/demandas/' . (int) $id);
    }

    public function publicar(string $id): string
    {
        try {
            $this->demandas->publicar((int) Sessao::usuarioId(), (int) $id);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id);
        }

        Flash::sucesso('Demanda publicada. A partir de agora ela aparece para quem pode atendê-la.');
        View::redirecionar('/demandas/' . (int) $id);
    }

    public function encerrar(string $id): string
    {
        try {
            $this->demandas->encerrar((int) Sessao::usuarioId(), (int) $id);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas');
        }

        Flash::sucesso('Demanda encerrada. Ela some da vitrine e continua no seu painel.');
        View::redirecionar('/demandas/' . (int) $id);
    }

    // ---------------------------------------------------------------- interno

    /**
     * O vocabulário fechado das duas dimensões que a demanda declara.
     *
     * Vem de `Support\Preferencias` e não de uma lista no template porque é a mesma lista que o
     * perfil oferece e que o motor compara. Enquanto os dois lados eram campo de texto, a demanda
     * dizia "obra certa", o perfil dizia "OBRA_CERTA", e a dimensão de contrato nunca casava.
     *
     * @return array<string, mixed>
     */
    private static function vocabulario(): array
    {
        return [
            'contratos_possiveis' => Preferencias::CONTRATOS,
            'ufs_possiveis'       => Preferencias::UFS,
        ];
    }

    /**
     * Resultados da busca TOS, já com a descrição montada para a tela.
     *
     * @return list<array<string, mixed>>
     */
    private function resultados(string $termo): array
    {
        return array_map(
            static fn (array $t): array => $t + ['descricao' => TosRepository::descrever($t)],
            $this->tos->buscar($termo),
        );
    }
}
