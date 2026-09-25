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
    /**
     * Situação da linha que o sistema decidiu NÃO enviar (D85): aviso de relacionamento para quem
     * revogou o consentimento de notificações. Fica fora de `pendentes()` porque não é `A`, e fica
     * na tabela porque a fila é o registro do que o sistema quis mandar e do que deixou de mandar.
     */
    public const NAO_ENVIADA = 'N';

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

    /**
     * Grava o aviso sem colocá-lo na fila de envio, com o motivo em `not_erro` (D85).
     *
     * Não apagar nem deixar de gravar é de propósito: quem audita precisa ver que o evento
     * aconteceu e que o e-mail não saiu por decisão do titular, e não por falha de SMTP.
     */
    public function registrarSemEnvio(
        int $usuarioId,
        string $tipo,
        string $destinatario,
        string $assunto,
        string $corpo,
        string $motivo,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sis_notificacoes
                (not_usu_id, not_tipo, not_destinatario, not_assunto, not_corpo, not_status, not_erro)
             VALUES
                (:usuario, :tipo, :destinatario, :assunto, :corpo, :status, :motivo)'
        );
        $stmt->execute([
            ':usuario'      => $usuarioId,
            ':tipo'         => $tipo,
            ':destinatario' => $destinatario,
            ':assunto'      => $assunto,
            ':corpo'        => $corpo,
            ':status'       => self::NAO_ENVIADA,
            ':motivo'       => mb_substr($motivo, 0, 255),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * O que ainda não saiu e ainda vale tentar.
     *
     * O teto de `MAIL_MAX_TENTATIVAS` não é otimização: sem ele, a mensagem cujo destinatário não
     * existe volta em toda execução, e a fila passa a gastar o lote inteiro reencenando a mesma
     * falha permanente — empurrando para o fim o que sairia. A linha não é apagada nem marcada
     * como enviada: fica com `not_erro` preenchido, visível para quem for investigar.
     *
     * @return list<array<string, mixed>>
     */
    public function pendentes(int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT not_id, not_usu_id, not_tipo, not_destinatario, not_assunto, not_corpo, not_tentativas
               FROM sis_notificacoes
              WHERE not_dt_envio IS NULL
                AND not_status = :ativo
                AND not_tentativas < :teto
              ORDER BY not_id
              LIMIT :limite'
        );
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':teto', MAIL_MAX_TENTATIVAS, \PDO::PARAM_INT);
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
