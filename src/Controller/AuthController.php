<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\TermoRepository;
use ProLink\Service\AutenticacaoService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * Cadastro, login, logout e recuperação de senha (RF01).
 *
 * Controller não tem regra de negócio e não toca no banco: recebe o POST, entrega ao serviço e
 * decide o que renderizar. Em erro de validação devolve o próprio formulário com os valores
 * digitados e os erros por campo — nunca redireciona, senão o usuário perde o que escreveu.
 *
 * Senha nunca volta para o formulário, mesmo em erro.
 */
final class AuthController
{
    public function __construct(
        private readonly AutenticacaoService $autenticacao = new AutenticacaoService(),
        private readonly TermoRepository $termos = new TermoRepository(),
    ) {
    }

    // ---------------------------------------------------------------- cadastro

    public function formularioCadastro(): string
    {
        return $this->renderizarCadastro();
    }

    public function cadastrar(): string
    {
        try {
            $this->autenticacao->cadastrar($_POST);
        } catch (ValidacaoException $e) {
            return $this->renderizarCadastro($e->erros(), $e->getMessage());
        }

        Flash::sucesso('Conta criada. Entre com seu e-mail e senha.');
        View::redirecionar('/login');
    }

    // ---------------------------------------------------------------- login e logout

    public function formularioLogin(): string
    {
        if (Sessao::autenticado()) {
            View::redirecionar('/');
        }

        return View::render('auth/login.html.twig', ['valores' => [], 'erros' => []]);
    }

    public function entrar(): string
    {
        try {
            $usuario = $this->autenticacao->autenticar(
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['senha'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            return View::render('auth/login.html.twig', [
                'valores' => ['email' => (string) ($_POST['email'] ?? '')],
                'erros'   => $e->erros(),
                'aviso'   => $e->getMessage(),
            ]);
        }

        Flash::sucesso('Bem-vindo, ' . $usuario['nome'] . '.');
        View::redirecionar('/');
    }

    /** POST, não GET: logout por link seria escrita sem proteção de CSRF (edital 8.5e). */
    public function sair(): string
    {
        $this->autenticacao->encerrarSessao();

        session_start();
        Flash::info('Você saiu da sua conta.');
        View::redirecionar('/');
    }

    // ---------------------------------------------------------------- recuperação de senha

    public function formularioRecuperacao(): string
    {
        return View::render('auth/recuperar.html.twig', ['valores' => [], 'erros' => []]);
    }

    /**
     * Sempre responde igual, exista o e-mail ou não. Confirmar que um endereço tem conta é
     * vazamento de informação por si só (OWASP A07).
     */
    public function solicitarRecuperacao(): string
    {
        $this->autenticacao->solicitarRecuperacao((string) ($_POST['email'] ?? ''));

        Flash::info('Se existir uma conta com este e-mail, enviamos um link de redefinição. '
            . 'O link vale por ' . RECUPERACAO_VALIDADE_MINUTOS . ' minutos.');
        View::redirecionar('/login');
    }

    public function formularioRedefinicao(string $token): string
    {
        return View::render('auth/redefinir.html.twig', ['token' => $token, 'erros' => []]);
    }

    public function redefinir(string $token): string
    {
        try {
            $this->autenticacao->redefinirSenha(
                $token,
                (string) ($_POST['senha'] ?? ''),
                (string) ($_POST['senha_confirmacao'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            return View::render('auth/redefinir.html.twig', [
                'token' => $token,
                'erros' => $e->erros(),
                'aviso' => $e->getMessage(),
            ]);
        }

        Flash::sucesso('Senha redefinida. Entre com a senha nova.');
        View::redirecionar('/login');
    }

    // ---------------------------------------------------------------- apoio

    /** @param array<string, string> $erros */
    private function renderizarCadastro(array $erros = [], ?string $aviso = null): string
    {
        $valores = $_POST;
        unset($valores['senha'], $valores['senha_confirmacao'], $valores['_csrf']);

        return View::render('auth/cadastro.html.twig', [
            'valores' => $valores,
            'erros'   => $erros,
            'aviso'   => $aviso,
            'termos'  => $this->termos->vigentes(),
        ]);
    }
}
