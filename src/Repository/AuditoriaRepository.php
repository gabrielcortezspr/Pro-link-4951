<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Acesso a sis_auditoria (edital 8.5g).
 *
 * Existe para que a regra do projeto — SQL só em repositório — valha também para a auditoria.
 * Antes da E1 o INSERT morava dentro de `Support\Auditoria`, porque esta camada ainda não existia;
 * `Auditoria` segue sendo o único caminho de escrita do ponto de vista de quem chama, mas agora
 * delega a query para cá.
 *
 * Não existe método de alteração nem de exclusão, e isso não é esquecimento: a tabela é
 * insert-only por trigger no banco (decisão D04). Um método `atualizar()` aqui seria código que
 * só serve para tomar exceção.
 */
final class AuditoriaRepository extends Repositorio
{
    /** @param array{usuario: ?int, ip: ?string, acao: string, entidade: ?string,
     *               entidade_id: ?int, campo: ?string, antes: ?string, depois: ?string,
     *               agente: ?string} $registro */
    public function registrar(array $registro): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sis_auditoria
                (aud_usu_id, aud_ip, aud_acao, aud_entidade, aud_entidade_id, aud_campo,
                 aud_valor_anterior, aud_valor_novo, aud_user_agent)
             VALUES
                (:usuario, :ip, :acao, :entidade, :entidade_id, :campo, :antes, :depois, :agente)'
        );

        $stmt->execute([
            ':usuario'     => $registro['usuario'],
            ':ip'          => $registro['ip'],
            ':acao'        => $registro['acao'],
            ':entidade'    => $registro['entidade'],
            ':entidade_id' => $registro['entidade_id'],
            ':campo'       => $registro['campo'],
            ':antes'       => $registro['antes'],
            ':depois'      => $registro['depois'],
            ':agente'      => $registro['agente'],
        ]);
    }

    /**
     * Ações de um usuário, mais recentes primeiro. Usado na exportação de dados do titular
     * (item 11.3) e, na E6, no painel de auditoria do administrador.
     *
     * @return list<array<string, mixed>>
     */
    public function doUsuario(int $usuarioId, int $limite = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aud_acao, aud_entidade, aud_entidade_id, aud_campo, aud_ip, aud_dt_registro
               FROM sis_auditoria
              WHERE aud_usu_id = :usuario
              ORDER BY aud_id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
