<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Os números do início de sessão de cada perfil, como o mockup `prolink-profissional.html` e o
 * `prolink-empresa-1.html` desenharam.
 *
 * ## Uma métrica do desenho não existe, e não foi inventada
 *
 * O mockup do profissional traz "Visualizações do perfil". **Não há registro de quem viu o perfil
 * de quem**, e criar um exigiria rastrear visita por titular, que é dado novo de comportamento,
 * com implicação de privacidade que a Política não declara. A regra do projeto é não preencher
 * lacuna com suposição, então o tile foi trocado por um número que existe e diz mais: quantos
 * demandantes **registraram interesse** naquele perfil, que é ato, não passagem de olho.
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
     * Os quatro números do início do profissional.
     *
     * @return array{compativeis: int, manifestacoes: int, arts: int, interesses: int}
     */
    public function doProfissional(int $usuarioId): array
    {
        return [
            'compativeis'   => $this->demandasEmQueApareceComoCandidato($usuarioId),
            'manifestacoes' => $this->manifestacoesEnviadas($usuarioId),
            'arts'          => $this->artsDoAcervo($usuarioId),
            'interesses'    => $this->interessesRecebidos($usuarioId),
        ];
    }

    /**
     * Os quatro números do início da empresa.
     *
     * A empresa é demandante e candidata ao mesmo tempo (Anexo I, item 3), e o painel reflete os
     * dois papéis: o que ela publicou e o que o acervo dela sustenta.
     *
     * @return array{demandas: int, publicadas: int, interessados: int, acervo: int, quadro: int}
     */
    public function daEmpresa(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN dem_dt_publicacao IS NOT NULL THEN 1 ELSE 0 END) AS publicadas
               FROM pro_demandas
              WHERE dem_usu_id = :usuario AND dem_status = :ativo'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();
        $demandas = $stmt->fetch() ?: [];

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM pro_manifestacoes m
               JOIN pro_demandas d ON d.dem_id = m.man_dem_id
              WHERE d.dem_usu_id = :usuario
                AND m.man_status = :ativo
                AND m.man_origem = :origem'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        // Só quem se candidatou: o interesse que a própria empresa registrou não é "interessado
        // que chegou", e somar os dois faria o painel contar o próprio ato como resultado.
        $stmt->bindValue(':origem', 'C');
        $stmt->execute();
        $interessados = (int) $stmt->fetchColumn();

        return [
            'demandas'     => (int) ($demandas['total'] ?? 0),
            'publicadas'   => (int) ($demandas['publicadas'] ?? 0),
            'interessados' => $interessados,
            'acervo'       => $this->acervoDaEmpresa($usuarioId),
            'quadro'       => $this->quadroVigente($usuarioId),
        ];
    }

    /**
     * Em quantas demandas distintas este profissional entrou no pool do motor.
     *
     * Lê `mat_sessao_pool`, que é o que o motor gravou, e não recalcula: o número tem de ser o
     * mesmo que a auditoria de sessões mostra, senão a plataforma diz duas coisas diferentes sobre
     * o mesmo fato.
     */
    private function demandasEmQueApareceComoCandidato(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT s.mts_dem_id)
               FROM mat_sessao_pool sp
               JOIN mat_sessoes s ON s.mts_id = sp.msp_mts_id
               JOIN pro_profissionais p ON p.prf_id = sp.msp_candidato_id
              WHERE sp.msp_candidato_tipo = :tipo
                AND p.prf_usu_id = :usuario
                AND s.mts_status = :ativo'
        );
        $stmt->bindValue(':tipo', 'P');
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function manifestacoesEnviadas(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pro_manifestacoes
              WHERE man_usu_id = :usuario AND man_status = :ativo AND man_origem = :origem'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':origem', 'C');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** O que um demandante registrou de interesse neste perfil: o substituto de "visualizações". */
    private function interessesRecebidos(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pro_manifestacoes
              WHERE man_usu_id = :usuario AND man_status = :ativo AND man_origem = :origem'
        );
        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':origem', 'D');
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
