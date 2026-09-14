<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Consulta de disponibilidade, para o health check.
 *
 * Existe por causa da regra do projeto: nenhum controller toca o banco. O SaudeController
 * precisava contar as linhas da TOS para provar que a carga inicial entrou, e fazia isso com um
 * `query()` direto — a única query da aplicação fora desta camada, junto com a da auditoria.
 */
final class SaudeRepository extends Repositorio
{
    /** Linhas da Tabela de Obras e Serviços. Zero significa carga inicial não executada. */
    public function totalDeCodigosTos(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM crea_tos')->fetchColumn();
    }
}
