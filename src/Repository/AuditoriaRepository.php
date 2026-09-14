<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Leitura da trilha de auditoria (edital 8.5g, Anexo VI).
 *
 * Só leitura: sis_auditoria é insert-only por trigger, e a escrita tem caminho único em
 * Support\Auditoria. Não há serviço acima daqui porque não há regra de negócio a aplicar;
 * camada que só repassa chamada é camada morta.
 */
final class AuditoriaRepository extends Repositorio
{
    private const COLUNAS = 'a.aud_id, a.aud_dt_registro, a.aud_usu_id, u.usu_nome, a.aud_ip,
                             a.aud_acao, a.aud_entidade, a.aud_entidade_id, a.aud_campo,
                             a.aud_valor_anterior, a.aud_valor_novo';

    /**
     * Monta o WHERE e os parâmetros que os dois métodos de consulta compartilham.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filtro(?int $usuarioId, ?string $acao, ?string $de, ?string $ate): array
    {
        $sql    = ' WHERE 1 = 1';
        $params = [];

        if ($usuarioId !== null) {
            $sql .= ' AND a.aud_usu_id = :usuario';
            $params[':usuario'] = $usuarioId;
        }

        if ($acao !== null) {
            $sql .= ' AND a.aud_acao = :acao';
            $params[':acao'] = $acao;
        }

        if ($de !== null) {
            $sql .= ' AND a.aud_dt_registro >= :de';
            $params[':de'] = $de;
        }

        if ($ate !== null) {
            $sql .= ' AND a.aud_dt_registro < :ate';
            $params[':ate'] = $ate;
        }

        return [$sql, $params];
    }

    /** @return list<array<string, mixed>> */
    public function listar(
        ?int $usuarioId,
        ?string $acao,
        ?string $de,
        ?string $ate,
        int $limite = 50,
        int $deslocamento = 0,
    ): array {
        [$where, $params] = $this->filtro($usuarioId, $acao, $de, $ate);

        // LEFT JOIN, não JOIN: aud_usu_id é nulo em ação anônima, e são justamente as tentativas
        // de login sem conta que somem com um JOIN.
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . '
               FROM sis_auditoria a
               LEFT JOIN sis_usuarios u ON u.usu_id = a.aud_usu_id'
            . $where
            . ' ORDER BY a.aud_id DESC LIMIT :limite OFFSET :deslocamento'
        );

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }

        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->bindValue(':deslocamento', $deslocamento, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function contar(?int $usuarioId, ?string $acao, ?string $de, ?string $ate): int
    {
        [$where, $params] = $this->filtro($usuarioId, $acao, $de, $ate);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sis_auditoria a' . $where);

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Alimenta o filtro de ação sem lista escrita à mão: ação nova passa a aparecer sozinha.
     *
     * @return list<string>
     */
    public function acoesDistintas(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT aud_acao FROM sis_auditoria ORDER BY aud_acao');

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
