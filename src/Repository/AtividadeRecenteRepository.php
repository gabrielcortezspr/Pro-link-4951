<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * A linha do tempo do Início (D90): o que aconteceu com esta conta, do mais recente para o mais
 * antigo, incluindo o que ela mesma fez.
 *
 * Uma consulta por tipo de acontecimento, juntadas e ordenadas no PHP. Cada uma é simples e usa os
 * índices que já existem; um UNION de oito ramos, com marcadores que não podem se repetir
 * (`ATTR_EMULATE_PREPARES` desligado), seria mais difícil de ler e de mudar.
 *
 * **Nenhum evento de leitura.** "Fulano abriu a sua candidatura" não entra aqui, pelo mesmo motivo
 * que saiu das outras telas (D88): aviso de leitura cria cobrança e não ajuda ninguém a decidir.
 */
final class AtividadeRecenteRepository extends Repositorio
{
    /**
     * @return list<array{tipo: string, quando: string, quem: ?string, demanda: ?string,
     *                    dem_id: ?int, man_id: ?int, detalhe: ?string}>
     */
    public function doUsuario(int $usuarioId, int $limite = 10): array
    {
        $eventos = array_merge(
            $this->contatos($usuarioId, $limite),
            $this->mensagensRecebidas($usuarioId, $limite),
            $this->demandasPublicadas($usuarioId, $limite),
            $this->acervoAtualizado($usuarioId, $limite),
        );

        usort($eventos, static fn (array $a, array $b): int => strcmp($b['quando'], $a['quando']));

        return array_slice($eventos, 0, $limite);
    }

    /** Candidaturas e convites, dos dois lados: recebidos pela demanda e enviados pela pessoa. */
    private function contatos(int $usuarioId, int $limite): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.man_id, m.man_dem_id, m.man_origem, m.man_usu_id, m.man_dt_registro,
                    d.dem_titulo, d.dem_usu_id,
                    COALESCE(pr.prf_nome_api, ce.emp_razao_social, cu.usu_nome) AS candidato,
                    COALESCE(pe.emp_razao_social, pu.usu_nome)                  AS publicante
               FROM pro_manifestacoes m
               JOIN pro_demandas d           ON d.dem_id = m.man_dem_id
               JOIN sis_usuarios cu          ON cu.usu_id = m.man_usu_id
               LEFT JOIN pro_profissionais pr ON pr.prf_usu_id = m.man_usu_id
               LEFT JOIN pro_empresas ce      ON ce.emp_usu_id = m.man_usu_id
               JOIN sis_usuarios pu          ON pu.usu_id = d.dem_usu_id
               LEFT JOIN pro_empresas pe      ON pe.emp_usu_id = d.dem_usu_id
              WHERE (m.man_usu_id = :candidato OR d.dem_usu_id = :dono)
                AND m.man_status = :ativo
              ORDER BY m.man_dt_registro DESC
              LIMIT ' . max(1, $limite)
        );
        $stmt->execute([':candidato' => $usuarioId, ':dono' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        $eventos = [];

        foreach ($stmt->fetchAll() as $m) {
            $souCandidato = (int) $m['man_usu_id'] === $usuarioId;
            $convite      = $m['man_origem'] === 'D';

            $eventos[] = [
                // Quatro leituras do mesmo fato, conforme o lado de quem olha.
                'tipo'    => match (true) {
                    $convite && $souCandidato   => 'convite_recebido',
                    $convite                    => 'convite_enviado',
                    $souCandidato               => 'candidatura_enviada',
                    default                     => 'candidatura_recebida',
                },
                'quando'  => (string) $m['man_dt_registro'],
                'quem'    => $souCandidato ? (string) $m['publicante'] : (string) $m['candidato'],
                'demanda' => (string) $m['dem_titulo'],
                'dem_id'  => (int) $m['man_dem_id'],
                'man_id'  => (int) $m['man_id'],
                'detalhe' => null,
            ];
        }

        return $eventos;
    }

    /** Mensagens que a outra parte escreveu nas conversas desta conta. */
    private function mensagensRecebidas(int $usuarioId, int $limite): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.msg_dt_registro, g.msg_man_id, d.dem_id, d.dem_titulo,
                    COALESCE(pr.prf_nome_api, e.emp_razao_social, u.usu_nome) AS autor
               FROM pro_mensagens g
               JOIN pro_manifestacoes m      ON m.man_id = g.msg_man_id AND m.man_status = :ativo_m
               JOIN pro_demandas d           ON d.dem_id = m.man_dem_id
               JOIN sis_usuarios u           ON u.usu_id = g.msg_usu_id
               LEFT JOIN pro_profissionais pr ON pr.prf_usu_id = g.msg_usu_id
               LEFT JOIN pro_empresas e       ON e.emp_usu_id = g.msg_usu_id
              WHERE (m.man_usu_id = :candidato OR d.dem_usu_id = :dono)
                AND g.msg_usu_id <> :leitor
                AND g.msg_status = :ativo_g
              ORDER BY g.msg_dt_registro DESC
              LIMIT ' . max(1, $limite)
        );
        $stmt->execute([
            ':ativo_m' => STATUS_ATIVO, ':candidato' => $usuarioId, ':dono' => $usuarioId,
            ':leitor' => $usuarioId, ':ativo_g' => STATUS_ATIVO,
        ]);

        return array_map(static fn (array $g): array => [
            'tipo'    => 'mensagem_recebida',
            'quando'  => (string) $g['msg_dt_registro'],
            'quem'    => (string) $g['autor'],
            'demanda' => (string) $g['dem_titulo'],
            'dem_id'  => (int) $g['dem_id'],
            'man_id'  => (int) $g['msg_man_id'],
            'detalhe' => null,
        ], $stmt->fetchAll());
    }

    private function demandasPublicadas(int $usuarioId, int $limite): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dem_id, dem_titulo, dem_dt_publicacao
               FROM pro_demandas
              WHERE dem_usu_id = :dono AND dem_dt_publicacao IS NOT NULL AND dem_status = :ativo
              ORDER BY dem_dt_publicacao DESC
              LIMIT ' . max(1, $limite)
        );
        $stmt->execute([':dono' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        return array_map(static fn (array $d): array => [
            'tipo'    => 'demanda_publicada',
            'quando'  => (string) $d['dem_dt_publicacao'],
            'quem'    => null,
            'demanda' => (string) $d['dem_titulo'],
            'dem_id'  => (int) $d['dem_id'],
            'man_id'  => null,
            'detalhe' => null,
        ], $stmt->fetchAll());
    }

    /**
     * Atualizações do acervo pelo titular (D77) que deram certo, e as importações de CAT (D76),
     * lidas da trilha de auditoria, que é insert-only e já guarda o resultado de cada uma.
     */
    private function acervoAtualizado(int $usuarioId, int $limite): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aud_acao, aud_valor_novo, aud_dt_registro
               FROM sis_auditoria
              WHERE aud_usu_id = :usuario AND aud_acao IN (:atualizar, :cat)
              ORDER BY aud_id DESC
              LIMIT ' . max(1, $limite)
        );
        $stmt->execute([':usuario' => $usuarioId, ':atualizar' => 'ATUALIZAR_ACERVO', ':cat' => 'VALIDAR_CAT']);

        $eventos = [];

        foreach ($stmt->fetchAll() as $a) {
            $dados = json_decode((string) ($a['aud_valor_novo'] ?? ''), true);
            $dados = is_array($dados) ? $dados : [];

            if ($a['aud_acao'] === 'ATUALIZAR_ACERVO' && ($dados['resultado'] ?? null) !== 'concluida') {
                continue;   // tentativa que falhou não é acontecimento do acervo
            }

            if ($a['aud_acao'] === 'VALIDAR_CAT' && (int) ($dados['cats'] ?? 0) === 0) {
                continue;
            }

            $eventos[] = [
                'tipo'    => $a['aud_acao'] === 'VALIDAR_CAT' ? 'cats_importadas' : 'acervo_atualizado',
                'quando'  => substr((string) $a['aud_dt_registro'], 0, 19),
                'quem'    => null,
                'demanda' => null,
                'dem_id'  => null,
                'man_id'  => null,
                'detalhe' => $a['aud_acao'] === 'VALIDAR_CAT' ? (string) (int) ($dados['cats'] ?? 0) : null,
            ];
        }

        return $eventos;
    }
}
