<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * O que o Início e o sino contam sobre a própria conta: a linha de identidade (D90) e o que chegou
 * e ainda não foi visto (D87).
 *
 * Até a D90 daqui saíam os quatro indicadores do Início, como os mockups desenharam. Saíram porque
 * não pediam ação nenhuma; o que deles ainda orienta a pessoa virou a linha de identidade.
 *
 * ## Nada aqui ordena gente
 *
 * Contagem do próprio titular sobre o próprio cadastro. Nenhum método compara um profissional com
 * outro, e nenhum devolve posição: o item 10.1 veda ranking, e um painel de "como você está" é
 * onde ele apareceria disfarçado de estímulo.
 */
final class DashboardRepository extends Repositorio
{
    /**
     * A linha de identidade do Início do profissional (D90) e o que o "Para fazer agora" precisa
     * saber do perfil: o tamanho do acervo, as CATs vigentes, quando o acervo foi consultado no
     * CREA e se as preferências de trabalho foram informadas.
     *
     * @return array{arts: int, cats: int, cats_vigentes: int, acervo_em: ?string,
     *               preferencias_vazias: bool}
     */
    public function resumoDoProfissional(int $usuarioId, string $hoje): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.prf_rnp, p.prf_dt_sincronizacao, p.prf_tipo_contrato, p.prf_disponibilidade
               FROM pro_profissionais p
              WHERE p.prf_usu_id = :usuario AND p.prf_status = :ativo'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);
        $perfil = $stmt->fetch();

        if ($perfil === false) {
            return ['arts' => 0, 'cats' => 0, 'cats_vigentes' => 0, 'acervo_em' => null, 'preferencias_vazias' => false];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cats,
                    SUM(CASE WHEN cat_dt_validade IS NULL OR cat_dt_validade >= :hoje THEN 1 ELSE 0 END) AS vigentes
               FROM crea_cats
              WHERE cat_pro_rnp = :rnp AND cat_status = :ativo'
        );
        $stmt->execute([':hoje' => substr($hoje, 0, 10), ':rnp' => (string) $perfil['prf_rnp'], ':ativo' => STATUS_ATIVO]);
        $cats = $stmt->fetch() ?: [];

        return [
            'arts'                => $this->artsDoAcervo($usuarioId),
            'cats'                => (int) ($cats['cats'] ?? 0),
            'cats_vigentes'       => (int) ($cats['vigentes'] ?? 0),
            'acervo_em'           => $perfil['prf_dt_sincronizacao'] !== null ? (string) $perfil['prf_dt_sincronizacao'] : null,
            'preferencias_vazias' => $perfil['prf_tipo_contrato'] === null && $perfil['prf_disponibilidade'] === null,
        ];
    }

    /**
     * A linha de identidade do Início da empresa (D90): o acervo herdado do quadro vigente, o
     * tamanho do quadro e quando o CREA foi consultado.
     *
     * @return array{acervo: int, quadro: int, acervo_em: ?string}
     */
    public function resumoDaEmpresa(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT emp_dt_sincronizacao FROM pro_empresas WHERE emp_usu_id = :usuario AND emp_status = :ativo'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);
        $sincronizada = $stmt->fetchColumn();

        return [
            'acervo'    => $this->acervoDaEmpresa($usuarioId),
            'quadro'    => $this->quadroVigente($usuarioId),
            'acervo_em' => $sincronizada !== false && $sincronizada !== null ? (string) $sincronizada : null,
        ];
    }

    /**
     * O que chegou para esta conta e ainda não foi visto.
     *
     * É o número do sino que os mockups desenham na topbar. **Não existe notificação dentro da
     * plataforma**: `sis_notificacoes` é fila de e-mail, com tentativa e erro de entrega, e não
     * tem estado de leitura. Em vez de criar uma caixa de entrada inteira a horas da entrega, o
     * sino conta o que já existe e é do mesmo assunto: manifestação recebida que ainda não foi
     * aberta, que é o que `man_dt_visualizacao` registra.
     *
     * O lado de cada papel é diferente e por isso a consulta é uma só, com dois caminhos: para o
     * demandante, o que chegou nas demandas dele; para o candidato, o interesse que registraram
     * no perfil dele.
     */
    /**
     * `naoVistas()` separado pelo papel (D87): candidaturas que chegaram às demandas deste
     * usuário, e convites que ele recebeu como titular de perfil.
     *
     * @return array{demandante: int, candidato: int}
     */
    public function naoVistasPorPapel(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*)
                   FROM pro_manifestacoes m
                   JOIN pro_demandas d ON d.dem_id = m.man_dem_id
                  WHERE d.dem_usu_id = :demandante AND m.man_origem = :do_candidato
                    AND m.man_dt_visualizacao IS NULL AND m.man_status = :ativo) AS demandante,
                (SELECT COUNT(*)
                   FROM pro_manifestacoes m2
                  WHERE m2.man_usu_id = :candidato AND m2.man_origem = :do_demandante
                    AND m2.man_dt_visualizacao IS NULL AND m2.man_status = :ativo2) AS candidato'
        );
        $stmt->execute([
            ':demandante' => $usuarioId, ':candidato' => $usuarioId,
            ':do_candidato' => 'C', ':do_demandante' => 'D',
            ':ativo' => STATUS_ATIVO, ':ativo2' => STATUS_ATIVO,
        ]);
        $linha = $stmt->fetch();

        return ['demandante' => (int) $linha['demandante'], 'candidato' => (int) $linha['candidato']];
    }

    public function naoVistas(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*)
                   FROM pro_manifestacoes m
                   JOIN pro_demandas d ON d.dem_id = m.man_dem_id
                  WHERE d.dem_usu_id = :demandante
                    AND m.man_origem = :do_candidato
                    AND m.man_dt_visualizacao IS NULL
                    AND m.man_status = :ativo)
              + (SELECT COUNT(*)
                   FROM pro_manifestacoes m2
                  WHERE m2.man_usu_id = :candidato
                    AND m2.man_origem = :do_demandante
                    AND m2.man_dt_visualizacao IS NULL
                    AND m2.man_status = :ativo2) AS total'
        );

        $stmt->bindValue(':demandante', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':candidato', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':do_candidato', 'C');
        $stmt->bindValue(':do_demandante', 'D');
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':ativo2', STATUS_ATIVO);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function artsDoAcervo(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM crea_arts a
               JOIN pro_profissionais p ON p.prf_rnp = a.art_pro_rnp
              WHERE p.prf_usu_id = :usuario AND a.art_status = :ativo'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * O acervo que a empresa herda do quadro técnico vigente.
     *
     * Passa por `crea_evidencias`, que é a única coisa que liga empresa a ART (D19): a ART é
     * sempre gravada sob o RNP de quem a registrou, nunca sob a empresa.
     */
    private function acervoDaEmpresa(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT e.evi_art_numero)
               FROM crea_evidencias e
               JOIN pro_empresas emp ON emp.emp_id = e.evi_candidato_id
              WHERE e.evi_candidato_tipo = :tipo AND emp.emp_usu_id = :usuario'
        );
        $stmt->bindValue(':tipo', 'E');
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function quadroVigente(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM crea_quadro_tecnico q
               JOIN pro_empresas e ON e.emp_registro_crea = q.qut_emp_registro_crea
              WHERE e.emp_usu_id = :usuario
                AND q.qut_dt_fim IS NULL
                AND q.qut_status = :ativo'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
