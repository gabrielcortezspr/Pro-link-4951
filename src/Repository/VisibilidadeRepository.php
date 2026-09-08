<?php

declare(strict_types=1);

namespace ProLink\Repository;

use ProLink\Support\Visibilidade;

/**
 * `pro_visibilidade`: o que o titular abriu, e para quem.
 *
 * A tabela guarda só o que foi decidido explicitamente. Alvo sem linha é privado — ver o
 * cabeçalho de `Support\Visibilidade` para o porquê de a ausência ser a regra, e não linhas
 * escritas no cadastro.
 */
final class VisibilidadeRepository extends Repositorio
{
    /**
     * Todas as escolhas do titular, indexadas pela chave do alvo.
     *
     * Uma consulta por perfil exibido, e não uma por campo: a tela do perfil pergunta pelo nome,
     * pelo telefone, pelo resumo e por cada ART, e fazer disso vinte SELECTs seria transformar
     * uma regra de privacidade em problema de desempenho — que é como regras de privacidade
     * acabam sendo contornadas.
     *
     * @return array<string, string> chave do alvo => nível
     */
    public function mapaDoUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT vis_entidade, vis_entidade_id, vis_campo, vis_nivel
               FROM pro_visibilidade
              WHERE vis_usu_id = :usuario AND vis_status = :ativo'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        $mapa = [];

        foreach ($stmt->fetchAll() as $linha) {
            $chave = Visibilidade::chave(
                (string) $linha['vis_entidade'],
                $linha['vis_entidade_id'] === null ? null : (int) $linha['vis_entidade_id'],
                $linha['vis_campo'] === null ? null : (string) $linha['vis_campo'],
            );

            $mapa[$chave] = (string) $linha['vis_nivel'];
        }

        return $mapa;
    }

    /** @return string|null nível gravado, ou null se o titular nunca decidiu sobre este alvo */
    public function nivel(int $usuarioId, string $entidade, ?int $entidadeId, ?string $campo): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT vis_nivel FROM pro_visibilidade
              WHERE vis_usu_id = :usuario AND vis_entidade = :entidade
                AND vis_entidade_id <=> :entidade_id AND vis_campo <=> :campo
                AND vis_status = :ativo'
        );
        $stmt->execute([
            ':usuario'     => $usuarioId,
            ':entidade'    => $entidade,
            ':entidade_id' => $entidadeId,
            ':campo'       => $campo,
            ':ativo'       => STATUS_ATIVO,
        ]);

        $nivel = $stmt->fetchColumn();

        return $nivel === false ? null : (string) $nivel;
    }

    /**
     * Grava a escolha do titular.
     *
     * `<=>` no WHERE e `uq_vis_alvo` no banco tratam NULL como valor comparável: o alvo
     * "PERFIL, sem id, sem campo" é um alvo, e não uma linha que nunca casa consigo mesma. Sem
     * isso, o perfil inteiro ganharia uma linha nova a cada clique.
     */
    public function definir(int $usuarioId, string $entidade, ?int $entidadeId, ?string $campo, string $nivel): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_visibilidade
                (vis_usu_id, vis_entidade, vis_entidade_id, vis_campo, vis_nivel, vis_status)
             VALUES (:usuario, :entidade, :entidade_id, :campo, :nivel, :ativo)
             ON DUPLICATE KEY UPDATE vis_nivel = VALUES(vis_nivel), vis_status = VALUES(vis_status)'
        );

        $stmt->execute([
            ':usuario'     => $usuarioId,
            ':entidade'    => $entidade,
            ':entidade_id' => $entidadeId,
            ':campo'       => $campo,
            ':nivel'       => $nivel,
            ':ativo'       => STATUS_ATIVO,
        ]);
    }

    /**
     * Fecha tudo do titular de uma vez.
     *
     * Usado quando o registro no CREA deixa de estar regular (proposta: "visibilidade zerada
     * automaticamente") e quando o titular revoga a exibição do perfil. Fecha em vez de apagar:
     * o histórico de que um dia esteve aberto é informação, e reabrir depois é um ato novo.
     */
    public function fecharTudo(int $usuarioId): int
    {
        // Dois nomes para o mesmo valor: com ATTR_EMULATE_PREPARES desligado o PDO não aceita
        // placeholder repetido na mesma instrução.
        $stmt = $this->pdo->prepare(
            'UPDATE pro_visibilidade SET vis_nivel = :novo
              WHERE vis_usu_id = :usuario AND vis_nivel <> :atual AND vis_status = :ativo'
        );
        $stmt->execute([
            ':novo'    => VISIBILIDADE_PRIVADO,
            ':atual'   => VISIBILIDADE_PRIVADO,
            ':usuario' => $usuarioId,
            ':ativo'   => STATUS_ATIVO,
        ]);

        return $stmt->rowCount();
    }
}
