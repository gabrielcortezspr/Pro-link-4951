<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Fila de e-mails (sis_notificacoes; RF07).
 *
 * É fila, não envio direto, por dois motivos. O cadastro não pode falhar porque o SMTP caiu — a
 * linha entra na transação e o envio acontece depois. E a banca consegue ver o que o sistema
 * mandou sem depender de caixa de e-mail: a tabela é o registro.
 *
 * O envio em si é do NotificacaoService; aqui é só persistência.
 */
final class NotificacaoRepository extends Repositorio
{
    public function enfileirar(
        int $usuarioId,
        string $tipo,
        string $destinatario,
        string $assunto,
        string $corpo,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sis_notificacoes
                (not_usu_id, not_tipo, not_destinatario, not_assunto, not_corpo, not_status)
             VALUES
                (:usuario, :tipo, :destinatario, :assunto, :corpo, :ativo)'
        );
        $stmt->execute([
            ':usuario'      => $usuarioId,
            ':tipo'         => $tipo,
            ':destinatario' => $destinatario,
            ':assunto'      => $assunto,
            ':corpo'        => $corpo,
            ':ativo'        => STATUS_ATIVO,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function pendentes(int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT not_id, not_usu_id, not_tipo, not_destinatario, not_assunto, not_corpo, not_tentativas
               FROM sis_notificacoes
              WHERE not_dt_envio IS NULL AND not_status = :ativo
              ORDER BY not_id
              LIMIT :limite'
        );
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function marcarEnviada(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_notificacoes SET not_dt_envio = NOW(), not_erro = NULL WHERE not_id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function marcarFalha(int $id, string $erro): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_notificacoes
                SET not_tentativas = not_tentativas + 1, not_erro = :erro
              WHERE not_id = :id'
        );
        $stmt->execute([':erro' => mb_substr($erro, 0, 255), ':id' => $id]);
    }
}
