<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\RecuperacaoRepository;
use ProLink\Repository\SessaoRepository;
use ProLink\Repository\TermoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Crypto;
use ProLink\Support\Database;
use ProLink\Support\Documento;
use ProLink\Support\Requisicao;
use ProLink\Support\Sessao;
use ProLink\Support\Validacao;

/**
 * Identidade: cadastro, login, logout e recuperação de senha (RF01; edital 8.5a/b, 11.3).
 *
 * Quatro cuidados que moram aqui porque não podem ficar espalhados:
 *
 *   · **Sem enumeração de usuário.** Credencial errada, e-mail inexistente e conta bloqueada
 *     devolvem a mesma mensagem. Quando o e-mail não existe o serviço ainda gasta um
 *     password_verify contra um hash descartável, para o tempo de resposta não denunciar a
 *     diferença.
 *   · **Bloqueio temporário por tentativas** (LOGIN_MAX_ATTEMPTS / LOGIN_LOCKOUT_MINUTES), contado
 *     no banco e não na sessão — trocar de navegador não zera o contador.
 *   · **Senha nunca aparece em auditoria.** A troca registra o campo com '(oculto)' nos dois
 *     lados: a trilha mostra que houve mudança, sem guardar nem o hash.
 *   · **Toda criação é transação.** Usuário, aceite de termos, consentimentos, notificação e
 *     auditoria entram juntos ou não entram — é a operação atômica da RF01.
 *
 * A conferência de que a sessão ainda tem respaldo em sis_sessoes não está aqui: é o front
 * controller que a faz, consultando SessaoRepository direto, porque não precisa de mais nada
 * deste serviço para isso.
 */
final class AutenticacaoService
{
    /** Mensagem única de falha de login. Não distingue causa, de propósito. */
    private const FALHA_LOGIN = 'E-mail ou senha incorretos, ou conta temporariamente bloqueada por tentativas repetidas.';

    /** Hash descartável para gastar tempo quando o e-mail não existe (anti-enumeração). */
    private const HASH_FALSO = '$argon2id$v=19$m=65536,t=4,p=1$YTNiY2RlZmdoaWprbG1ub3A$Zm9vYmFyYmF6cXV1eGNvcmdlZ3JhdWx0';

    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly SessaoRepository $sessoes = new SessaoRepository(),
        private readonly ConsentimentoRepository $consentimentos = new ConsentimentoRepository(),
        private readonly TermoRepository $termos = new TermoRepository(),
        private readonly RecuperacaoRepository $recuperacoes = new RecuperacaoRepository(),
        private readonly NotificacaoService $notificacoes = new NotificacaoService(),
    ) {
    }

    // ---------------------------------------------------------------- cadastro

    /**
     * Cria a conta. A validação contra a API oficial não acontece aqui: na E1 o cadastro guarda o
     * documento cifrado e cria o usuário; na E2 o cadastro de profissional e de empresa consulta
     * a API e preenche pro_profissionais / pro_empresas.
     *
     * @param array<string, mixed> $entrada campos crus do formulário
     * @return int id do usuário criado
     */
    public function cadastrar(array $entrada): int
    {
        $tipoCadastro = (string) ($entrada['tipo_cadastro'] ?? '');
        $nome         = trim((string) ($entrada['nome'] ?? ''));
        $email        = mb_strtolower(trim((string) ($entrada['email'] ?? '')));
        $senha        = (string) ($entrada['senha'] ?? '');
        $confirmacao  = (string) ($entrada['senha_confirmacao'] ?? '');
        $documento    = Crypto::apenasDigitos((string) ($entrada['documento'] ?? ''));
        $telefone     = trim((string) ($entrada['telefone'] ?? '')) ?: null;

        $tiposValidos = [CADASTRO_PROFISSIONAL, CADASTRO_EMPRESA, CADASTRO_TERCEIRO_PF, CADASTRO_TERCEIRO_PJ];

        $v = new Validacao();
        $v->entre('tipo_cadastro', $tipoCadastro, $tiposValidos, 'Escolha o tipo de cadastro.');

        $v->obrigatorio('nome', $nome, 'Informe o nome.')
          ->tamanhoMaximo('nome', $nome, 150, 'No máximo 150 caracteres.');

        $v->obrigatorio('email', $email, 'Informe o e-mail.')
          ->email('email', $email, 'E-mail inválido.')
          ->tamanhoMaximo('email', $email, 190, 'No máximo 190 caracteres.');

        $v->tamanhoMinimo('senha', $senha, SENHA_TAMANHO_MINIMO,
            sprintf('Use pelo menos %d caracteres.', SENHA_TAMANHO_MINIMO))
          ->exigir('senha_confirmacao', $senha !== '' && $senha === $confirmacao, 'As senhas não conferem.');

        // Sem tipo válido não há como saber se o documento deveria ser CPF ou CNPJ (D07), então
        // este erro interrompe aqui em vez de produzir mensagem errada no campo seguinte.
        if ($v->temErro('tipo_cadastro')) {
            $v->lancarSeInvalido();
        }

        $tipoPessoa = self::tipoPessoaDe($tipoCadastro);
        $rotulo     = $tipoPessoa === Documento::TIPO_JURIDICA ? 'CNPJ' : 'CPF';

        $v->obrigatorio('documento', $documento, "Informe o {$rotulo}.")
          ->exigir('documento', $documento === '' || Documento::valido($documento, $tipoPessoa),
              "{$rotulo} inválido. Confira os dígitos.");

        // Aceite dos dois termos: sem isso não existe conta (edital 11.3).
        $v->exigir('aceite_uso', (bool) ($entrada['aceite_uso'] ?? false),
            'É necessário aceitar os Termos de Uso.')
          ->exigir('aceite_privacidade', (bool) ($entrada['aceite_privacidade'] ?? false),
              'É necessário aceitar a Política de Privacidade.');

        // CONSULTA_API é execução do serviço para quem tem registro no CREA, não conveniência:
        // sem ela não há como validar o registro nem importar ARTs.
        $exigeConsultaApi = in_array($tipoCadastro, [CADASTRO_PROFISSIONAL, CADASTRO_EMPRESA], true);

        if ($exigeConsultaApi) {
            $v->exigir('consentimento_api', (bool) ($entrada['consentimento_api'] ?? false),
                'Para validar seu registro no CREA precisamos consultar a API oficial.');
        }

        $v->lancarSeInvalido();

        if ($this->usuarios->emailEmUso($email)) {
            $v->exigir('email', false, 'Já existe uma conta com este e-mail.');
        }

        $documentoHash = Crypto::hashBusca($documento);

        if ($this->usuarios->documentoEmUso($documentoHash)) {
            $v->exigir('documento', false, "Já existe uma conta com este {$rotulo}.");
        }

        $v->lancarSeInvalido();

        $perfil   = self::perfilDe($tipoCadastro);
        $perfilId = $this->usuarios->perfilIdPorCodigo($perfil);

        if ($perfilId === null) {
            throw new \RuntimeException("Perfil {$perfil} não encontrado. A carga inicial rodou?");
        }

        $termosVigentes = $this->termos->vigentes();
        $ip             = Requisicao::ip();

        return Database::transacao(function (PDO $pdo) use (
            $perfilId, $nome, $email, $senha, $tipoPessoa, $documento, $documentoHash,
            $telefone, $entrada, $exigeConsultaApi, $termosVigentes, $ip, $perfil
        ): int {
            $usuarios       = new UsuarioRepository($pdo);
            $consentimentos = new ConsentimentoRepository($pdo);

            $usuarioId = $usuarios->criar([
                'perfil_id'      => $perfilId,
                'nome'           => $nome,
                'email'          => $email,
                'senha_hash'     => password_hash($senha, PASSWORD_ALGO),
                'tipo_pessoa'    => $tipoPessoa,
                'documento_cif'  => Crypto::cifrar($documento),
                'documento_hash' => $documentoHash,
                'telefone'       => $telefone,
            ]);

            // Aceite de termos: uma linha por documento, apontando para a versão aceita.
            foreach (FINALIDADE_POR_TERMO as $tipoTermo => $finalidade) {
                $consentimentos->definir(
                    $usuarioId,
                    $finalidade,
                    true,
                    $ip,
                    isset($termosVigentes[$tipoTermo]) ? (int) $termosVigentes[$tipoTermo]['ter_id'] : null,
                );
            }

            // Consentimentos por finalidade. Gravamos também os negados: a ausência de registro e
            // a recusa explícita não são a mesma coisa para o item 11.3.
            $consentimentos->definir($usuarioId, FINALIDADE_CONSULTA_API,
                $exigeConsultaApi || (bool) ($entrada['consentimento_api'] ?? false), $ip);
            $consentimentos->definir($usuarioId, FINALIDADE_EXIBICAO_PERFIL,
                (bool) ($entrada['consentimento_perfil'] ?? false), $ip);
            $consentimentos->definir($usuarioId, FINALIDADE_NOTIFICACOES,
                (bool) ($entrada['consentimento_notificacoes'] ?? false), $ip);

            Auditoria::registrar(Auditoria::CRIAR, 'sis_usuarios', $usuarioId, null, null,
                ['perfil' => $perfil, 'email' => $email, 'tipo_pessoa' => $tipoPessoa],
                $usuarioId, $pdo);

            $this->notificacoes->enfileirar(
                $usuarioId,
                NotificacaoService::CADASTRO,
                $email,
                'Sua conta no Pro-Link foi criada',
                'email/cadastro.html.twig',
                ['nome' => $nome, 'perfil' => $perfil],
            );

            return $usuarioId;
        });
    }

    // ---------------------------------------------------------------- login e logout

    /**
     * Autentica e abre a sessão. Lança ValidacaoException com mensagem única em qualquer falha.
     *
     * @return array{id: int, nome: string, perfil: string}
     */
    public function autenticar(string $email, string $senha): array
    {
        $email   = mb_strtolower(trim($email));
        $usuario = $this->usuarios->porEmail($email);

        if ($usuario === null) {
            // Gasta o mesmo tempo de um login real: sem isso, a diferença de latência vira
            // oráculo de "este e-mail existe" (OWASP A07).
            password_verify($senha, self::HASH_FALSO);
            Auditoria::registrar(Auditoria::LOGIN_FALHOU, 'sis_usuarios', null, null, null,
                ['email' => $email, 'motivo' => 'inexistente']);

            throw new ValidacaoException(self::FALHA_LOGIN, ['email' => self::FALHA_LOGIN]);
        }

        $usuarioId = (int) $usuario['usu_id'];

        if ($usuario['usu_bloqueado_ate'] !== null && strtotime((string) $usuario['usu_bloqueado_ate']) > time()) {
            Auditoria::registrar(Auditoria::LOGIN_FALHOU, 'sis_usuarios', $usuarioId, null, null,
                ['motivo' => 'bloqueado'], $usuarioId);

            throw new ValidacaoException(self::FALHA_LOGIN, ['email' => self::FALHA_LOGIN]);
        }

        if (!password_verify($senha, (string) $usuario['usu_senha_hash'])) {
            $this->registrarFalha($usuario);

            throw new ValidacaoException(self::FALHA_LOGIN, ['email' => self::FALHA_LOGIN]);
        }

        // Custo do Argon2id pode ter mudado desde o cadastro; reidrata sem incomodar o usuário.
        if (password_needs_rehash((string) $usuario['usu_senha_hash'], PASSWORD_ALGO)) {
            $this->usuarios->atualizarSenha($usuarioId, password_hash($senha, PASSWORD_ALGO));
        }

        $sessao = [
            'id'     => $usuarioId,
            'nome'   => (string) $usuario['usu_nome'],
            'perfil' => (string) $usuario['per_codigo'],
        ];

        // Regenera o id da sessão ANTES de registrar em sis_sessoes: o hash gravado tem que ser
        // o do id novo, senão a conferência por requisição nunca casa.
        Sessao::autenticar($sessao);

        $tokenHash = Crypto::hashToken(session_id());
        Sessao::definirTokenServidor($tokenHash);

        $this->sessoes->registrar(
            $usuarioId,
            $tokenHash,
            Requisicao::ip(),
            Requisicao::userAgent(),
            SESSION_LIFETIME_MINUTES,
        );

        $this->usuarios->registrarLogin($usuarioId);
        Auditoria::registrar(Auditoria::LOGIN, 'sis_usuarios', $usuarioId, null, null, null, $usuarioId);

        return $sessao;
    }

    public function encerrarSessao(): void
    {
        $usuarioId = Sessao::usuarioId();
        $token     = Sessao::tokenServidor();

        if ($token !== null) {
            $this->sessoes->revogar($token);
        }

        if ($usuarioId !== null) {
            Auditoria::registrar(Auditoria::LOGOUT, 'sis_usuarios', $usuarioId, null, null, null, $usuarioId);
        }

        Sessao::encerrar();
    }

    // ---------------------------------------------------------------- recuperação de senha

    /**
     * Gera o token e enfileira o e-mail. **Não revela se o e-mail existe**: o controller mostra a
     * mesma mensagem nos dois casos, e este método simplesmente não faz nada quando não encontra.
     */
    public function solicitarRecuperacao(string $email): void
    {
        $email   = mb_strtolower(trim($email));
        $usuario = $this->usuarios->porEmail($email);

        if ($usuario === null) {
            Auditoria::registrar(Auditoria::LOGIN_FALHOU, 'sis_recuperacoes', null, null, null,
                ['email' => $email, 'motivo' => 'recuperacao_email_inexistente']);

            return;
        }

        $usuarioId = (int) $usuario['usu_id'];
        $token     = bin2hex(random_bytes(32));

        $this->recuperacoes->invalidarPendentes($usuarioId);
        $this->recuperacoes->criar(
            $usuarioId,
            Crypto::hashToken($token),
            RECUPERACAO_VALIDADE_MINUTOS,
            Requisicao::ip(),
        );

        $this->notificacoes->enfileirar(
            $usuarioId,
            NotificacaoService::RECUPERACAO_SENHA,
            (string) $usuario['usu_email'],
            'Redefinição de senha no Pro-Link',
            'email/recuperacao.html.twig',
            [
                'nome'     => $usuario['usu_nome'],
                'link'     => APP_URL . '/redefinir-senha/' . $token,
                'validade' => RECUPERACAO_VALIDADE_MINUTOS,
            ],
        );

        Auditoria::registrar(Auditoria::EDITAR, 'sis_recuperacoes', $usuarioId, null, null,
            ['acao' => 'token_emitido'], $usuarioId);
    }

    /** Redefine a senha e derruba todas as sessões do usuário. */
    public function redefinirSenha(string $token, string $senha, string $confirmacao): void
    {
        $v = new Validacao();
        $v->tamanhoMinimo('senha', $senha, SENHA_TAMANHO_MINIMO,
            sprintf('Use pelo menos %d caracteres.', SENHA_TAMANHO_MINIMO))
          ->exigir('senha_confirmacao', $senha !== '' && $senha === $confirmacao, 'As senhas não conferem.');
        $v->lancarSeInvalido();

        $recuperacao = $this->recuperacoes->utilizavel(Crypto::hashToken($token));

        if ($recuperacao === null) {
            throw new ValidacaoException(
                'Este link de redefinição expirou ou já foi usado. Peça um novo.',
                ['senha' => 'Link inválido.'],
            );
        }

        $usuarioId = (int) $recuperacao['rec_usu_id'];

        Database::transacao(function (PDO $pdo) use ($usuarioId, $senha, $recuperacao): void {
            (new UsuarioRepository($pdo))->atualizarSenha($usuarioId, password_hash($senha, PASSWORD_ALGO));
            (new RecuperacaoRepository($pdo))->marcarUsado((int) $recuperacao['rec_id']);

            // Quem trocou a senha provavelmente suspeita de acesso indevido: toda sessão cai.
            $derrubadas = (new SessaoRepository($pdo))->revogarTodasDoUsuario($usuarioId);

            // Nem o hash da senha entra na trilha — só o fato de ter mudado.
            Auditoria::registrar(Auditoria::EDITAR, 'sis_usuarios', $usuarioId, 'usu_senha_hash',
                '(oculto)', '(oculto)', $usuarioId, $pdo);
            Auditoria::registrar(Auditoria::REVOGAR, 'sis_sessoes', $usuarioId, null, null,
                ['sessoes_revogadas' => $derrubadas, 'motivo' => 'troca_de_senha'], $usuarioId, $pdo);
        });
    }

    // ---------------------------------------------------------------- mapeamentos do formulário

    /** Tipo de cadastro → perfil de acesso. TERCEIRO_PF e TERCEIRO_PJ viram o mesmo perfil. */
    public static function perfilDe(string $tipoCadastro): string
    {
        return match ($tipoCadastro) {
            CADASTRO_PROFISSIONAL => PERFIL_PROFISSIONAL,
            CADASTRO_EMPRESA      => PERFIL_EMPRESA,
            default               => PERFIL_TERCEIRO,
        };
    }

    /** Tipo de cadastro → pessoa física ou jurídica, que decide se o documento é CPF ou CNPJ. */
    public static function tipoPessoaDe(string $tipoCadastro): string
    {
        return in_array($tipoCadastro, [CADASTRO_EMPRESA, CADASTRO_TERCEIRO_PJ], true)
            ? Documento::TIPO_JURIDICA
            : Documento::TIPO_FISICA;
    }

    /** Incrementa o contador e aplica o bloqueio quando estoura o limite. */
    private function registrarFalha(array $usuario): void
    {
        $usuarioId  = (int) $usuario['usu_id'];
        $tentativas = (int) $usuario['usu_tentativas'] + 1;

        if ($tentativas >= LOGIN_MAX_ATTEMPTS) {
            $bloqueadoAte = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60);

            // Zera o contador ao bloquear: passado o prazo, o usuário recomeça com o limite
            // cheio, em vez de ser rebloqueado no primeiro erro seguinte.
            $this->usuarios->registrarTentativaFalha($usuarioId, 0, $bloqueadoAte);
            Auditoria::registrar(Auditoria::BLOQUEIO_LOGIN, 'sis_usuarios', $usuarioId, null, null,
                ['bloqueado_ate' => $bloqueadoAte, 'tentativas' => $tentativas], $usuarioId);

            return;
        }

        $this->usuarios->registrarTentativaFalha($usuarioId, $tentativas, null);
        Auditoria::registrar(Auditoria::LOGIN_FALHOU, 'sis_usuarios', $usuarioId, null, null,
            ['tentativas' => $tentativas], $usuarioId);
    }
}
