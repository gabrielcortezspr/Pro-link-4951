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
              WHERE vis_usu_id = :usuario AND vis_status = :ativo
              ORDER BY vis_id'
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

    /**
     * @return string|null nível gravado, ou null se o titular nunca decidiu sobre este alvo
     */
    public function nivel(int $usuarioId, string $entidade, ?int $entidadeId, ?string $campo): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT vis_nivel FROM pro_visibilidade
              WHERE vis_usu_id = :usuario AND vis_entidade = :entidade
                AND vis_entidade_id <=> :entidade_id AND vis_campo <=> :campo
                AND vis_status = :ativo
              ORDER BY vis_id DESC
              LIMIT 1'
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
     * Grava a escolha do titular, procurando a linha antes em vez de confiar no índice.
     *
     * **`uq_vis_alvo` não garante o que o nome promete.** Ele cobre
     * (usuário, entidade, entidade_id, campo), e as duas últimas colunas aceitam nulo: o alvo
     * `ART:5` tem `vis_campo` nulo, o alvo `PERFIL:EMAIL` tem `vis_entidade_id` nulo. Em MariaDB
     * duas linhas com NULL na mesma coluna **não** violam UNIQUE — então o
     * `ON DUPLICATE KEY UPDATE` que estava aqui nunca casava, e cada clique do titular inseria
     * uma linha nova em vez de atualizar a dele.
     *
     * O efeito não era cosmético. `nivel()` devolvia uma das linhas duplicadas, o serviço
     * comparava a escolha nova com um valor antigo, concluía "não mudou" e **descartava a
     * alteração** — numa tela de privacidade, com a mensagem de sucesso na frente do titular.
     *
     * A mesma armadilha está documentada em `QuadroTecnicoRepository`, e a solução é a mesma:
     * procurar com `<=>` (igualdade null-safe, que funciona onde o índice não funciona) e decidir
     * entre UPDATE e INSERT. O índice fica, porque ainda cobre os alvos sem nulo e não custa nada.
     */
    public function definir(int $usuarioId, string $entidade, ?int $entidadeId, ?string $campo, string $nivel): void
    {
        $procurar = $this->pdo->prepare(
            'SELECT vis_id FROM pro_visibilidade
              WHERE vis_usu_id = :usuario AND vis_entidade = :entidade
                AND vis_entidade_id <=> :entidade_id AND vis_campo <=> :campo
              ORDER BY vis_id DESC
              LIMIT 1'
        );
        $procurar->execute([
            ':usuario'     => $usuarioId,
            ':entidade'    => $entidade,
            ':entidade_id' => $entidadeId,
            ':campo'       => $campo,
        ]);

        $id = $procurar->fetchColumn();

        if ($id !== false) {
            $this->pdo->prepare(
                'UPDATE pro_visibilidade SET vis_nivel = :nivel, vis_status = :ativo
                  WHERE vis_id = :id'
            )->execute([':nivel' => $nivel, ':ativo' => STATUS_ATIVO, ':id' => (int) $id]);

            return;
        }

        $this->pdo->prepare(
            'INSERT INTO pro_visibilidade
                (vis_usu_id, vis_entidade, vis_entidade_id, vis_campo, vis_nivel, vis_status)
             VALUES (:usuario, :entidade, :entidade_id, :campo, :nivel, :ativo)'
        )->execute([
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
