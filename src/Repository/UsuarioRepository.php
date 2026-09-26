<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Acesso a sis_usuarios (RF01).
 *
 * Duas convenções que valem para todo método daqui:
 *
 *   · consulta operacional filtra `usu_status = 'A'` — quem está em 'X' (excluído pelo titular)
 *     ou 'I' (bloqueado pelo administrador) não autentica e não aparece;
 *   · verificação de unicidade ignora o status, porque o índice UNIQUE do banco também ignora.
 *     Checar só entre ativos faria o cadastro estourar com erro de chave duplicada ao reusar o
 *     e-mail de uma conta excluída, em vez de devolver mensagem de campo.
 */
final class UsuarioRepository extends Repositorio
{
    private const CAMPOS = 'usu_id, usu_per_id, usu_nome, usu_email, usu_senha_hash, usu_tipo_pessoa,
                            usu_documento_cif, usu_documento_hash, usu_telefone, usu_email_verificado,
                            usu_dt_ultimo_login, usu_tentativas, usu_bloqueado_ate, usu_dt_registro,
                            usu_status';

    /** O modelo de mensagem de convite salvo pela conta, ou null quando ela usa o padrão (D88). */
    public function modeloConvite(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT usu_modelo_convite FROM sis_usuarios WHERE usu_id = :id');
        $stmt->execute([':id' => $id]);
        $valor = $stmt->fetchColumn();

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** Grava o modelo de convite; null volta ao padrão da plataforma. */
    public function salvarModeloConvite(int $id, ?string $modelo): void
    {
        $stmt = $this->pdo->prepare('UPDATE sis_usuarios SET usu_modelo_convite = :modelo WHERE usu_id = :id');
        $stmt->execute([':modelo' => $modelo, ':id' => $id]);
    }

    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ', per_codigo
               FROM sis_usuarios
               JOIN sis_perfis ON per_id = usu_per_id
              WHERE usu_id = :id AND usu_status = :ativo'
        );
        $stmt->execute([':id' => $id, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetch() ?: null;
    }

    /**
     * A conta em **qualquer situação**: ativa, bloqueada ou excluída.
     *
     * `porId()` filtra por ativa, que é o certo para o caminho operacional: quem está bloqueado
     * não deve ser encontrado por engano no meio de um fluxo comum. A gestão de contas precisa do
     * contrário, e a falta disto tinha um efeito absurdo: **não dava para desbloquear ninguém**,
     * porque a conta bloqueada não era encontrada para ser desbloqueada.
     *
     * @return array<string, mixed>|null
     */
    public function porIdEmQualquerSituacao(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ', per_codigo
               FROM sis_usuarios
               JOIN sis_perfis ON per_id = usu_per_id
              WHERE usu_id = :id'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Usado no login. Traz o perfil junto para a sessão não precisar de segunda consulta. */
    public function porEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ', per_codigo
               FROM sis_usuarios
               JOIN sis_perfis ON per_id = usu_per_id
              WHERE usu_email = :email AND usu_status = :ativo'
        );
        $stmt->execute([':email' => $email, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Perfil de acesso de vários usuários ativos, de uma vez.
     *
     * Só `usu_status = 'A'`, como toda consulta operacional daqui: conta excluída ou bloqueada
     * simplesmente não volta, e quem chama lê a ausência como "fechado". É o que faz a exclusão
     * lógica (D05) e o bloqueio administrativo (E6) valerem também dentro do motor.
     *
     * @param  list<int> $ids
     * @return array<int, string> usu_id => per_codigo
     */
    /**
     * Uma página de contas para a gestão do administrador (Anexo I, item 3: "gerir perfis").
     *
     * Traz o que a tela precisa para decidir, e **nada de documento**: `usu_documento_cif` é
     * cifrado e só o titular tem motivo para vê-lo decifrado. Uma listagem administrativa que
     * mostrasse CPF de todo mundo seria o oposto do que a D03 se comprometeu a fazer.
     *
     * O filtro por situação distingue os três estados que existem de fato: ativa, bloqueada pela
     * administração (`'I'`) e excluída pelo titular (`'X'`). A última aparece aqui **e** na
     * lixeira, e é a mesma conta: a lixeira responde "o que dá para restaurar", esta tela
     * responde "quem existe".
     *
     * @param  array{termo?: string, perfil?: string, situacao?: string} $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros, int $limite, int $deslocamento): array
    {
        [$onde, $params] = $this->filtrosDeListagem($filtros);

        $stmt = $this->pdo->prepare(
            'SELECT u.usu_id, u.usu_nome, u.usu_email, u.usu_status, u.usu_tipo_pessoa,
                    u.usu_dt_registro, u.usu_dt_ultimo_login, u.usu_bloqueado_ate,
                    p.per_codigo,
                    (SELECT COUNT(*) FROM sis_sessoes s
                      WHERE s.ses_usu_id = u.usu_id
                        AND s.ses_dt_revogacao IS NULL
                        AND s.ses_dt_expiracao > NOW()
                        AND s.ses_status = :ativo_sessao) AS sessoes_abertas
               FROM sis_usuarios u
               JOIN sis_perfis p ON p.per_id = u.usu_per_id
              WHERE ' . $onde . '
              ORDER BY u.usu_id DESC
              LIMIT :limite OFFSET :deslocamento'
        );

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }

        $stmt->bindValue(':ativo_sessao', STATUS_ATIVO);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->bindValue(':deslocamento', $deslocamento, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array{termo?: string, perfil?: string, situacao?: string} $filtros */
    public function contar(array $filtros): int
    {
        [$onde, $params] = $this->filtrosDeListagem($filtros);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sis_usuarios u
               JOIN sis_perfis p ON p.per_id = u.usu_per_id
              WHERE ' . $onde
        );

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Monta o `WHERE` da listagem a partir de valores de lista fechada.
     *
     * Perfil e situação são conferidos contra as constantes antes de entrar na query, e o termo
     * vai por placeholder: nada do que vem da tela chega ao SQL como texto concatenado.
     *
     * @param  array{termo?: string, perfil?: string, situacao?: string} $filtros
     * @return array{0: string, 1: array<string, string>}
     */
    private function filtrosDeListagem(array $filtros): array
    {
        $condicoes = ['1 = 1'];
        $params    = [];

        $termo = trim((string) ($filtros['termo'] ?? ''));

        if ($termo !== '') {
            //  Dois nomes para o mesmo valor, e não `:termo` nas duas pontas do OR.
            //
            //  A conexão roda com `ATTR_EMULATE_PREPARES => false`, que é o certo: quem monta a
            //  consulta é o servidor, e não o driver costurando texto. Nesse modo o MariaDB
            //  recusa o mesmo marcador usado duas vezes, e a busca por nome ou e-mail devolvia
            //  500 antes de chegar na listagem.
            $condicoes[]            = '(u.usu_nome LIKE :termo_nome OR u.usu_email LIKE :termo_email)';
            $params[':termo_nome']  = '%' . $termo . '%';
            $params[':termo_email'] = '%' . $termo . '%';
        }

        $perfil = (string) ($filtros['perfil'] ?? '');

        if (in_array($perfil, PERFIS_AUTENTICADOS, true)) {
            $condicoes[]       = 'p.per_codigo = :perfil';
            $params[':perfil'] = $perfil;
        }

        $situacao = (string) ($filtros['situacao'] ?? '');

        if (in_array($situacao, [STATUS_ATIVO, STATUS_INATIVO, STATUS_EXCLUIDO], true)) {
            $condicoes[]         = 'u.usu_status = :situacao';
            $params[':situacao'] = $situacao;
        }

        return [implode(' AND ', $condicoes), $params];
    }

    public function perfisAtivos(array $ids): array
    {
        $linhas = $this->buscarPorIds(
            static fn (array $m): string =>
                'SELECT usu_id, per_codigo
                   FROM sis_usuarios
                   JOIN sis_perfis ON per_id = usu_per_id
                  WHERE usu_id IN (' . implode(', ', $m) . ')
                    AND usu_status = :ativo',
            $ids,
            [':ativo' => STATUS_ATIVO],
        );

        $perfis = [];

        foreach ($linhas as $linha) {
            $perfis[(int) $linha['usu_id']] = (string) $linha['per_codigo'];
        }

        return $perfis;
    }

    /** Unicidade: sem filtro de status, de propósito — ver o cabeçalho da classe. */
    public function emailEmUso(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sis_usuarios WHERE usu_email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    /** Busca por hash cego: nunca decifra a coluna para comparar documento. */
    public function documentoEmUso(string $documentoHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sis_usuarios WHERE usu_documento_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $documentoHash]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Troca o perfil do usuário. Hoje só num caminho: quem se cadastrou como Profissional mas
     * cujo CPF a API não conhece vira Terceiro PF, para não ficar com um perfil que promete um
     * registro no CREA que não existe.
     */
    public function trocarPerfil(int $usuarioId, int $perfilId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_usuarios SET usu_per_id = :perfil WHERE usu_id = :usuario'
        );
        $stmt->execute([':perfil' => $perfilId, ':usuario' => $usuarioId]);
    }

    public function perfilIdPorCodigo(string $codigo): ?int
    {
        $stmt = $this->pdo->prepare('SELECT per_id FROM sis_perfis WHERE per_codigo = :codigo AND per_status = :ativo');
        $stmt->execute([':codigo' => $codigo, ':ativo' => STATUS_ATIVO]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array{perfil_id: int, nome: string, email: string, senha_hash: string,
     *              tipo_pessoa: string, documento_cif: string, documento_hash: string,
     *              telefone: ?string} $dados
     */
    public function criar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sis_usuarios
                (usu_per_id, usu_nome, usu_email, usu_senha_hash, usu_tipo_pessoa,
                 usu_documento_cif, usu_documento_hash, usu_telefone, usu_status)
             VALUES
                (:perfil, :nome, :email, :hash, :tipo, :cif, :doc_hash, :telefone, :ativo)'
        );

        $stmt->bindValue(':perfil', $dados['perfil_id'], \PDO::PARAM_INT);
        $stmt->bindValue(':nome', $dados['nome']);
        $stmt->bindValue(':email', $dados['email']);
        $stmt->bindValue(':hash', $dados['senha_hash']);
        $stmt->bindValue(':tipo', $dados['tipo_pessoa']);
        // VARBINARY: o pacote AES-GCM tem bytes nulos, e sem PARAM_LOB o driver trunca.
        $stmt->bindValue(':cif', $dados['documento_cif'], \PDO::PARAM_LOB);
        $stmt->bindValue(':doc_hash', $dados['documento_hash']);
        $stmt->bindValue(':telefone', $dados['telefone']);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function registrarLogin(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_usuarios
                SET usu_dt_ultimo_login = NOW(), usu_tentativas = 0, usu_bloqueado_ate = NULL
              WHERE usu_id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    /** Tentativas e bloqueio numa escrita só: o contador e o prazo nunca ficam inconsistentes. */
    public function registrarTentativaFalha(int $id, int $tentativas, ?string $bloqueadoAte): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_usuarios
                SET usu_tentativas = :tentativas, usu_bloqueado_ate = :bloqueado
              WHERE usu_id = :id'
        );
        $stmt->execute([':tentativas' => $tentativas, ':bloqueado' => $bloqueadoAte, ':id' => $id]);
    }

    public function atualizarSenha(int $id, string $senhaHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_usuarios
                SET usu_senha_hash = :hash, usu_tentativas = 0, usu_bloqueado_ate = NULL
              WHERE usu_id = :id'
        );
        $stmt->execute([':hash' => $senhaHash, ':id' => $id]);
    }

    /** Exclusão lógica (edital 8.6j). Ver decisão D05 em docs/decisoes.md. */
    public function marcarExcluido(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE sis_usuarios SET usu_status = :excluido WHERE usu_id = :id');
        $stmt->execute([':excluido' => STATUS_EXCLUIDO, ':id' => $id]);
    }

    /**
     * Muda o status da conta. O bloqueio administrativo usa STATUS_INATIVO.
     *
     * Não reaproveita marcarExcluido: 'X' é exclusão a pedido do titular (D05) e 'I' é bloqueio
     * pela moderação. Misturar os dois apaga na trilha a diferença entre quem saiu e quem foi
     * barrado, que é justamente o que a auditoria precisa distinguir.
     */
    public function alterarStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE sis_usuarios SET usu_status = :status WHERE usu_id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    // A leitura da auditoria do titular saiu daqui para AuditoriaRepository::doUsuario(): query de
    // sis_auditoria pertence ao repositório de auditoria, e mantê-la nos dois lugares deixaria a
    // mesma consulta com duas donas.
}
