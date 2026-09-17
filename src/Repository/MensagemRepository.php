<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Mensagens dentro de uma manifestação (RF05; cenário 4 do Anexo I).
 *
 * ## O canal é da manifestação, e não das pessoas
 *
 * Toda mensagem pende de `msg_man_id`. Não existe "conversa entre A e B" na plataforma: existe
 * conversa **sobre uma demanda em que A manifestou interesse**. É o que a proposta chama de
 * "canal de comunicação inicial dentro da plataforma, sem exposição imediata de dados de
 * contato" — e a consequência prática é que o canal nasce e morre com o assunto, em vez de virar
 * uma caixa de entrada que ninguém modera.
 *
 * Nem e-mail nem telefone aparecem aqui. Quem quiser trocar contato escreve, e a decisão é das
 * duas partes; a plataforma não entrega o dado por conta própria.
 */
final class MensagemRepository extends Repositorio
{
    public function criar(int $manifestacaoId, int $remetenteId, string $corpo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_mensagens (msg_man_id, msg_usu_id, msg_corpo)
             VALUES (:manifestacao, :remetente, :corpo)'
        );

        $stmt->execute([
            ':manifestacao' => $manifestacaoId,
            ':remetente'    => $remetenteId,
            ':corpo'        => $corpo,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A conversa inteira, em ordem cronológica.
     *
     * Sem paginação de propósito: é primeiro contato sobre uma demanda, não chat. Se uma delas
     * crescer a ponto de precisar de rolagem infinita, o que mudou foi o produto, e a decisão
     * volta para a mesa.
     *
     * @return list<array<string, mixed>>
     */
    public function daManifestacao(int $manifestacaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.msg_id, m.msg_usu_id, m.msg_corpo, m.msg_dt_leitura, m.msg_dt_registro,
                    u.usu_nome
               FROM pro_mensagens m
               JOIN sis_usuarios u ON u.usu_id = m.msg_usu_id
              WHERE m.msg_man_id = :manifestacao AND m.msg_status = :ativo
              ORDER BY m.msg_dt_registro, m.msg_id'
        );
        $stmt->execute([':manifestacao' => $manifestacaoId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /**
     * Marca como lidas as mensagens que **o outro** mandou.
     *
     * O filtro por remetente não é detalhe: sem ele, abrir a própria conversa carimbaria como
     * lida a mensagem que a pessoa acabou de enviar, e o "não lida" do outro lado sumiria sem
     * ninguém ter lido nada.
     */
    public function marcarLidas(int $manifestacaoId, int $leitorId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_mensagens
                SET msg_dt_leitura = NOW()
              WHERE msg_man_id = :manifestacao
                AND msg_usu_id <> :leitor
                AND msg_dt_leitura IS NULL
                AND msg_status = :ativo'
        );
        $stmt->execute([
            ':manifestacao' => $manifestacaoId, ':leitor' => $leitorId, ':ativo' => STATUS_ATIVO,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Quantas mensagens não lidas o usuário tem, por manifestação.
     *
     * Em lote, para o painel listar dezenas de manifestações sem uma consulta por linha. É o que
     * substitui a notificação por e-mail a cada mensagem: o RF07 lista os eventos que geram
     * e-mail e mensagem não está entre eles, então quem avisa é a interface.
     *
     * @param  list<int> $manifestacaoIds
     * @return array<int, int> manifestação => não lidas
     */
    public function naoLidasEmLote(array $manifestacaoIds, int $leitorId): array
    {
        $linhas = $this->buscarPorIds(
            static fn (array $m): string =>
                'SELECT msg_man_id, COUNT(*) AS nao_lidas
                   FROM pro_mensagens
                  WHERE msg_man_id IN (' . implode(', ', $m) . ')
                    AND msg_usu_id <> :leitor
                    AND msg_dt_leitura IS NULL
                    AND msg_status = :ativo
                  GROUP BY msg_man_id',
            $manifestacaoIds,
            [':leitor' => $leitorId, ':ativo' => STATUS_ATIVO],
        );

        $saida = [];

        foreach ($linhas as $linha) {
            $saida[(int) $linha['msg_man_id']] = (int) $linha['nao_lidas'];
        }

        return $saida;
    }
}
