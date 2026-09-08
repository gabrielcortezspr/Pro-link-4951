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

    /** Ações do próprio usuário, para a exportação de dados do item 11.3. */
    public function auditoriaDoUsuario(int $id, int $limite = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aud_acao, aud_entidade, aud_entidade_id, aud_campo, aud_ip, aud_dt_registro
               FROM sis_auditoria
              WHERE aud_usu_id = :id
              ORDER BY aud_id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
