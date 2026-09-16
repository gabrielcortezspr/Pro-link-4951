<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Manifestações de interesse (RF05; cenário 4 do Anexo I).
 *
 * ## O snapshot é o registro, não um cache
 *
 * `man_snapshot` guarda o perfil como o demandante podia vê-lo no instante do envio, e
 * `man_snapshot_hash` o sha256 do mesmo JSON. Não é otimização de leitura: é o que sustenta a
 * promessa da proposta de que *"alterações posteriores não afetam o que a empresa já viu"*.
 * Reconstruir o perfil na leitura seria mostrar o de hoje, que é justamente o que o snapshot
 * existe para não fazer.
 *
 * O hash existe para o caso desagradável: alguém alegar que o que a empresa viu não era isso.
 * Guardado ao lado do conteúdo, ele não prova contra quem tem acesso ao banco — prova contra
 * alteração acidental, que é o risco real aqui.
 *
 * ## Uma por par, e o banco é quem garante
 *
 * `uq_man_dem_usu UNIQUE (man_dem_id, man_usu_id)` impede a segunda manifestação do mesmo
 * candidato na mesma demanda. O serviço confere antes para dar mensagem decente, mas quem
 * decide é o índice: conferir-e-inserir sem unicidade no banco é corrida esperando acontecer.
 */
final class ManifestacaoRepository extends Repositorio
{
    private const CAMPOS = 'm.man_id, m.man_dem_id, m.man_usu_id, m.man_candidato_tipo,
                            m.man_mensagem, m.man_situacao, m.man_dt_visualizacao,
                            m.man_dt_registro, m.man_snapshot_hash';

    /** @param array<string, mixed> $dados */
    public function criar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_manifestacoes
                (man_dem_id, man_usu_id, man_candidato_tipo, man_mensagem,
                 man_snapshot, man_snapshot_hash)
             VALUES
                (:demanda, :usuario, :tipo, :mensagem, :snapshot, :hash)'
        );

        $stmt->execute([
            ':demanda'  => $dados['demanda_id'],
            ':usuario'  => $dados['usuario_id'],
            ':tipo'     => $dados['candidato_tipo'],
            ':mensagem' => $dados['mensagem'],
            ':snapshot' => $dados['snapshot'],
            ':hash'     => $dados['hash'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Já manifestou nesta demanda? Pergunta do serviço, para a mensagem; a garantia é do índice. */
    public function jaManifestou(int $demandaId, int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM pro_manifestacoes
              WHERE man_dem_id = :demanda AND man_usu_id = :usuario AND man_status = :ativo
              LIMIT 1'
        );
        $stmt->execute([':demanda' => $demandaId, ':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Quantas manifestações este usuário enviou desde um instante.
     *
     * Alimenta o limite por hora (proposta, A04). Conta por janela deslizante e não por hora
     * cheia: com hora cheia, quem manifesta às 10h59 recebe cota nova um minuto depois.
     */
    public function quantasDesde(int $usuarioId, string $desde): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pro_manifestacoes
              WHERE man_usu_id = :usuario AND man_dt_registro >= :desde'
        );
        $stmt->execute([':usuario' => $usuarioId, ':desde' => $desde]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Os interessados de uma demanda, para o painel do demandante.
     *
     * Traz o nome da conta, e **não** o snapshot: a lista mostra quem manifestou e quando, e o
     * perfil congelado só é lido quando alguém abre um interessado. Carregar um JSON de perfil
     * por linha para desenhar uma lista seria pagar o custo do detalhe em toda listagem.
     *
     * @return list<array<string, mixed>>
     */
    public function daDemanda(int $demandaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ', u.usu_nome,
                    (SELECT COUNT(*) FROM pro_mensagens g
                      WHERE g.msg_man_id = m.man_id AND g.msg_status = :ativo_g) AS mensagens
               FROM pro_manifestacoes m
               JOIN sis_usuarios u ON u.usu_id = m.man_usu_id
              WHERE m.man_dem_id = :demanda AND m.man_status = :ativo
              ORDER BY m.man_dt_registro'
        );
        $stmt->execute([
            ':demanda' => $demandaId, ':ativo' => STATUS_ATIVO, ':ativo_g' => STATUS_ATIVO,
        ]);

        return $stmt->fetchAll();
    }

    /** Uma manifestação com o snapshot, para a tela de detalhe. */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ', m.man_snapshot, u.usu_nome,
                    d.dem_titulo, d.dem_usu_id
               FROM pro_manifestacoes m
               JOIN sis_usuarios u ON u.usu_id = m.man_usu_id
               JOIN pro_demandas d ON d.dem_id = m.man_dem_id
              WHERE m.man_id = :id AND m.man_status = :ativo'
        );
        $stmt->execute([':id' => $id, ':ativo' => STATUS_ATIVO]);

        $linha = $stmt->fetch();

        return $linha === false ? null : $linha;
    }

    /**
     * Marca como vista pelo demandante, uma vez só.
     *
     * A data da primeira visualização é informação para quem manifestou ("foi visto"), e
     * reescrevê-la a cada abertura transformaria isso em "foi visto agora", que é outra coisa.
     */
    public function marcarVisualizada(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE pro_manifestacoes
                SET man_dt_visualizacao = NOW(),
                    man_situacao = :vista
              WHERE man_id = :id AND man_dt_visualizacao IS NULL AND man_situacao = :enviada'
        )->execute([':vista' => 'VISUALIZADA', ':enviada' => 'ENVIADA', ':id' => $id]);
    }

    /** As manifestações de um candidato, para ele acompanhar o que enviou. */
    public function doUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ', d.dem_titulo, d.dem_situacao
               FROM pro_manifestacoes m
               JOIN pro_demandas d ON d.dem_id = m.man_dem_id
              WHERE m.man_usu_id = :usuario AND m.man_status = :ativo
              ORDER BY m.man_dt_registro DESC'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }
}
