<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * O estado da integração com a API oficial do CREA-AM, lido do que já está no banco.
 *
 * ## Por que nada aqui chama a API
 *
 * O item 10.4 veda coleta automatizada, e a organização do desafio registra cada chamada feita ao
 * ambiente fictício. Uma tela de administração que consultasse o serviço a cada carregamento
 * gastaria cota alheia para mostrar um número, e bastaria alguém deixar a página aberta para
 * virar exatamente o que o edital proíbe.
 *
 * Então o estado é **inferido do cache**: o que foi importado, quando, e por quem. As tabelas
 * `crea_*` guardam a resposta datada da API, e é dessa data que a tela fala. A conferência ao
 * vivo existe, é explícita, e está em `scripts/verificar-api.php`, fora da interface.
 */
final class IntegracaoRepository extends Repositorio
{
    /**
     * As coleções que vêm da API oficial, com o rótulo que a tela usa.
     *
     * A chave é o nome real da tabela e não aparece em tela nenhuma: identificador de sistema à
     * mostra é violação do padrão do projeto.
     *
     * @var array<string, array{rotulo: string, data: ?string}>
     */
    private const COLECOES = [
        'crea_tos'            => ['rotulo' => 'Tabela de Obras e Serviços', 'data' => null],
        'crea_arts'           => ['rotulo' => 'ARTs importadas',            'data' => 'art_dt_consulta'],
        'crea_cats'           => ['rotulo' => 'CATs importadas',            'data' => 'cat_dt_consulta'],
        'crea_quadro_tecnico' => ['rotulo' => 'Vínculos de quadro técnico', 'data' => null],
        'crea_evidencias'     => ['rotulo' => 'Evidências indexadas',       'data' => null],
    ];

    /**
     * O que a plataforma guarda de cada coleção da API.
     *
     * @return list<array{rotulo: string, total: int, ultima: ?string}>
     */
    public function colecoes(): array
    {
        $linhas = [];

        foreach (self::COLECOES as $tabela => $meta) {
            $total  = 0;
            $ultima = null;

            try {
                $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$tabela}")->fetchColumn();

                if ($meta['data'] !== null && $total > 0) {
                    $ultima = $this->pdo
                        ->query("SELECT MAX({$meta['data']}) FROM {$tabela}")
                        ->fetchColumn() ?: null;
                }
            } catch (\PDOException) {
                // Coleção que ainda não existe neste ambiente aparece zerada, e não derruba a
                // tela: a administração precisa justamente ver que ela está vazia.
                $total = 0;
            }

            $linhas[] = ['rotulo' => $meta['rotulo'], 'total' => $total, 'ultima' => $ultima];
        }

        return $linhas;
    }

    /**
     * Quantos perfis dependem da sincronização de situação cadastral, e como eles estão.
     *
     * É o número que diz se a integração está fazendo trabalho: perfil que nunca foi conferido
     * contra a API é perfil cuja situação no conselho a plataforma não sabe.
     *
     * @return array{com_situacao: int, sem_situacao: int, ultima: ?string}
     */
    public function situacaoDosPerfis(): array
    {
        $linha = $this->pdo->query(
            'SELECT
                SUM(CASE WHEN prf_status_api IS NOT NULL THEN 1 ELSE 0 END) AS com_situacao,
                SUM(CASE WHEN prf_status_api IS NULL     THEN 1 ELSE 0 END) AS sem_situacao,
                MAX(prf_dt_sincronizacao) AS ultima
               FROM pro_profissionais'
        )->fetch() ?: [];

        return [
            'com_situacao' => (int) ($linha['com_situacao'] ?? 0),
            'sem_situacao' => (int) ($linha['sem_situacao'] ?? 0),
            'ultima'       => $linha['ultima'] ?? null,
        ];
    }

    /**
     * As últimas importações registradas na trilha de auditoria.
     *
     * A trilha é insert-only por gatilho, então esta é a fonte que ninguém alterou: se a tela
     * dissesse "última sincronização" a partir de um campo comum, bastaria uma escrita para a
     * afirmação deixar de valer.
     *
     * @return list<array<string, mixed>>
     */
    public function ultimasImportacoes(int $limite = 8): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.aud_dt_registro, a.aud_acao, a.aud_entidade, a.aud_entidade_id,
                    a.aud_valor_novo, u.usu_nome
               FROM sis_auditoria a
               LEFT JOIN sis_usuarios u ON u.usu_id = a.aud_usu_id
              WHERE a.aud_entidade LIKE :prefixo
              ORDER BY a.aud_id DESC
              LIMIT ' . max(1, min(50, $limite))
        );
        $stmt->execute([':prefixo' => 'crea\_%']);

        return $stmt->fetchAll() ?: [];
    }
}
