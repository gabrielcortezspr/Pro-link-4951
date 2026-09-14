<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\TermoRepository;
use ProLink\Service\AutenticacaoService;
use ProLink\Service\EmpresaCreaService;
use ProLink\Service\PerfilCreaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Crypto;
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
        private readonly PerfilCreaService $perfilCrea = new PerfilCreaService(),
        private readonly EmpresaCreaService $empresaCrea = new EmpresaCreaService(),
    ) {
    }

    // ---------------------------------------------------------------- cadastro

    public function formularioCadastro(): string
    {
        return $this->renderizarCadastro();
    }

    /**
     * Cadastro em dois passos, e a ordem importa.
     *
     * Primeiro a conta existe; só depois se consulta o CREA. Se a segunda etapa falhar por
     * qualquer motivo, a conta **permanece** e o usuário é avisado — devolver o formulário aqui
     * faria a pessoa tentar de novo com um e-mail que já está cadastrado, e ela concluiria que o
     * cadastro deu errado quando na verdade deu certo. Perder uma inscrição por causa de um
     * serviço de terceiro é o único desfecho irreversível desta tela.
     */
    public function cadastrar(): string
    {
        try {
            $usuarioId = $this->autenticacao->cadastrar($_POST);
        } catch (ValidacaoException $e) {
            return $this->renderizarCadastro($e->erros(), $e->getMessage());
        }

        Flash::sucesso('Conta criada. Entre com seu e-mail e senha.');

        $tipo      = (string) ($_POST['tipo_cadastro'] ?? '');
        $documento = Crypto::apenasDigitos((string) ($_POST['documento'] ?? ''));

        if ($tipo === CADASTRO_PROFISSIONAL) {
            $this->vincularProfissionalAoCrea($usuarioId, $documento);
        } elseif ($tipo === CADASTRO_EMPRESA) {
            $this->vincularEmpresaAoCrea($usuarioId, $documento);
        }

        View::redirecionar('/login');
    }

    /**
     * Segunda etapa do cadastro de Profissional. Nunca lança: os três desfechos da consulta ao
     * CREA viram mensagem, e qualquer falha inesperada também — a conta já existe.
     */
    private function vincularProfissionalAoCrea(int $usuarioId, string $cpf): void
    {
        try {
            $perfil = $this->perfilCrea->vincularProfissional($usuarioId, $cpf);
        } catch (\Throwable $e) {
            $this->avisarFalhaDaValidacao($usuarioId, $e);

            return;
        }

        if ($perfil['aviso'] !== null) {
            Flash::aviso($perfil['aviso']);

            return;
        }

        if ($perfil['situacao'] === PerfilCreaService::VINCULADO) {
            Flash::info(sprintf(
                'Registro validado no CREA (RNP %s). Importamos %d %s do seu acervo.',
                $perfil['rnp'],
                $perfil['arts'],
                $perfil['arts'] === 1 ? 'ART' : 'ARTs',
            ));
        }
    }

    /**
     * Segunda etapa do cadastro de Empresa, com as mesmas garantias da do profissional.
     *
     * A mensagem de sucesso fala de quadro técnico e de acervo herdado, e não de "suas ARTs":
     * a empresa não registra ART, ela responde por quem registrou, e a tela não pode sugerir o
     * contrário logo na primeira frase que a pessoa lê.
     */
    private function vincularEmpresaAoCrea(int $usuarioId, string $cnpj): void
    {
        try {
            $empresa = $this->empresaCrea->vincularEmpresa($usuarioId, $cnpj);
        } catch (\Throwable $e) {
            $this->avisarFalhaDaValidacao($usuarioId, $e);

            return;
        }

        if ($empresa['aviso'] !== null) {
            Flash::aviso($empresa['aviso']);

            return;
        }

        if ($empresa['situacao'] === EmpresaCreaService::VINCULADO) {
            Flash::info(sprintf(
                'Registro validado no CREA (registro %s). Importamos %d vínculo(s) do quadro '
                . 'técnico e %d ART(s) do acervo operacional.',
                $empresa['registro_crea'],
                $empresa['vinculos'],
                $empresa['arts'],
            ));
        }
    }

    /** A conta já existe: falha na validação é aviso, nunca perda do cadastro (D20). */
    private function avisarFalhaDaValidacao(int $usuarioId, \Throwable $e): void
    {
        error_log('Falha ao vincular o usuário ' . $usuarioId . ' ao CREA: ' . $e->getMessage());
        Flash::aviso('Sua conta foi criada, mas não conseguimos validar seu registro no CREA '
            . 'agora. Entre e tente novamente pelo seu perfil.');
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
    public function sair(): never
    {
        $this->autenticacao->encerrarSessao();

        Sessao::reiniciar();
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
    public function solicitarRecuperacao(): never
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
