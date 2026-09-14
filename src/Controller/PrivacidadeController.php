<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\AutenticacaoService;
use ProLink\Service\PrivacidadeService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * Painel de privacidade do titular (edital 11.3): ver o que a plataforma tem, revogar
 * consentimento, exportar e excluir a conta.
 *
 * Todo método opera sobre o usuário da sessão — nunca sobre um id vindo da requisição. Não existe
 * aqui caminho para mexer na conta de outra pessoa, nem para o administrador.
 */
final class PrivacidadeController
{
    public function __construct(
        private readonly PrivacidadeService $privacidade = new PrivacidadeService(),
        private readonly AutenticacaoService $autenticacao = new AutenticacaoService(),
    ) {
    }

    public function index(): string
    {
        $usuarioId = Sessao::usuarioId();

        return View::render('privacidade/index.html.twig', [
            'painel'      => $this->privacidade->painel((int) $usuarioId),
            'finalidades' => PrivacidadeService::FINALIDADES_REVOGAVEIS,
        ]);
    }

    public function definirConsentimento(): never
    {
        $finalidade = (string) ($_POST['finalidade'] ?? '');
        $concedido  = ($_POST['acao'] ?? '') === 'conceder';

        try {
            $this->privacidade->definirConsentimento((int) Sessao::usuarioId(), $finalidade, $concedido);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/privacidade');
        }

        Flash::sucesso($concedido
            ? 'Consentimento concedido.'
            : 'Consentimento revogado. O efeito é imediato.');
        View::redirecionar('/privacidade');
    }

    /** Download do JSON de portabilidade. Não passa pelo Twig: a resposta não é HTML. */
    public function exportar(): string
    {
        $dados = $this->privacidade->exportar((int) Sessao::usuarioId());

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="prolink-meus-dados-' . date('Y-m-d') . '.json"');

        return (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Exclusão da própria conta. Exige digitar a confirmação: é irreversível pelo usuário, e
     * um clique acidental não deve encerrar a conta.
     */
    public function excluir(): never
    {
        if (trim((string) ($_POST['confirmacao'] ?? '')) !== 'EXCLUIR') {
            Flash::erro('Para confirmar, digite EXCLUIR no campo indicado.');
            View::redirecionar('/privacidade');
        }

        try {
            $this->privacidade->excluirConta((int) Sessao::usuarioId());
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/privacidade');
        }

        $this->autenticacao->encerrarSessao();

        Sessao::reiniciar();
        Flash::info('Sua conta foi excluída e suas sessões foram encerradas.');
        View::redirecionar('/');
    }
}
