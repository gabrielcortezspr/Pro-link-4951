<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\LixeiraRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;

/**
 * A lixeira administrativa do item 8.6j, e o limite que ela não pode ultrapassar.
 *
 * ## O que o edital pede
 *
 * "Nada é apagado fisicamente. `xxx_status = 'X'` marca excluído, some das consultas operacionais
 * e continua acessível **só por mecanismo administrativo de lixeira**." A primeira metade já valia
 * em todo o código; a segunda não existia, e registro que some de todo lugar é, na prática,
 * registro apagado.
 *
 * ## O limite: restaurar não é desfazer o direito de alguém
 *
 * Exclusão de conta a pedido do titular é exercício do art. 18 da LGPD, e o edital cobra o mesmo
 * no item 11.3. Um administrador que reativa essa conta não está restaurando um registro: está
 * trazendo de volta dado pessoal que o dono mandou apagar, sem que ele peça e sem que ele saiba.
 * Por isso conta excluída pelo próprio titular **aparece** na lixeira, para a plataforma poder
 * provar que atendeu o pedido, e **não volta** por aqui.
 *
 * A alternativa recusada foi esconder essas contas da lixeira. Esconder resolveria o risco e
 * quebraria o 8.6j, que manda o excluído continuar acessível ao mecanismo administrativo. Ver D62.
 *
 * ## Toda restauração exige motivo
 *
 * É ato administrativo sobre registro de outra pessoa. O motivo entra na trilha junto com o quê,
 * o quando e o quem, porque `RESTAURAR` sem motivo responde "o quê" e deixa "por quê" com quem
 * fez, que é justamente o que a auditoria existe para não depender.
 */
final class LixeiraService
{
    public const POR_PAGINA = 20;

    private const MOTIVO_MINIMO = 10;

    private const MOTIVO_MAXIMO = 500;

    public function __construct(
        private readonly LixeiraRepository $lixeira = new LixeiraRepository(),
    ) {
    }

    /**
     * Uma página da lixeira, com as contagens de todas as entidades para a navegação.
     *
     * @return array{
     *     entidade: string,
     *     contagens: array<string, int>,
     *     itens: list<array<string, mixed>>,
     *     pagina: int,
     *     paginas: int,
     *     total: int
     * }
     */
    public function visao(string $entidade, int $pagina): array
    {
        $entidade = in_array($entidade, LixeiraRepository::ENTIDADES, true)
            ? $entidade
            : LixeiraRepository::ENTIDADES[0];

        $contagens = $this->lixeira->contagens();
        $total     = $contagens[$entidade] ?? 0;
        $paginas   = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina    = max(1, min($pagina, $paginas));

        $itens = $this->lixeira->listar(
            $entidade,
            self::POR_PAGINA,
            ($pagina - 1) * self::POR_PAGINA,
        );

        foreach ($itens as $i => $item) {
            $restauravel = $this->restauravel($item);

            $itens[$i]['restauravel'] = $restauravel;
            $itens[$i]['impedimento'] = $restauravel ? null : self::IMPEDIMENTO;
        }

        return [
            'entidade'  => $entidade,
            'contagens' => $contagens,
            'itens'     => $itens,
            'pagina'    => $pagina,
            'paginas'   => $paginas,
            'total'     => $total,
        ];
    }

    public const IMPEDIMENTO = 'Conta excluída a pedido do próprio titular. A plataforma guarda o '
        . 'registro para provar que o pedido foi atendido, e não o reativa sem ele.';

    /**
     * Devolve um registro à operação, com motivo obrigatório na trilha.
     *
     * @return array<string, mixed> o registro restaurado
     * @throws ValidacaoException
     */
    public function restaurar(string $entidade, int $id, int $administradorId, string $motivo): array
    {
        if (!in_array($entidade, LixeiraRepository::ENTIDADES, true)) {
            throw new ValidacaoException('Este tipo de registro não tem lixeira.');
        }

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new ValidacaoException(
                'Escreva o motivo da restauração, com pelo menos ' . self::MOTIVO_MINIMO . ' caracteres.',
                ['motivo' => 'Motivo curto demais.'],
            );
        }

        if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new ValidacaoException(
                'O motivo passa de ' . self::MOTIVO_MAXIMO . ' caracteres.',
                ['motivo' => 'Motivo longo demais.'],
            );
        }

        $registro = $this->lixeira->porId($entidade, $id);

        if ($registro === null) {
            throw new ValidacaoException('Registro não encontrado na lixeira. Ele pode já ter sido restaurado.');
        }

        if (!$this->restauravel($registro)) {
            throw new ValidacaoException(self::IMPEDIMENTO);
        }

        Database::transacao(function (PDO $pdo) use ($entidade, $id, $administradorId, $motivo, $registro): void {
            if (!(new LixeiraRepository($pdo))->restaurar($entidade, $id)) {
                throw new ValidacaoException('O registro deixou a lixeira antes desta restauração.');
            }

            Auditoria::registrar(
                Auditoria::RESTAURAR,
                $entidade,
                $id,
                null,
                STATUS_EXCLUIDO,
                [
                    'status' => STATUS_ATIVO,
                    'motivo' => $motivo,
                    // Guardado junto porque a trilha precisa responder de quem era o registro sem
                    // exigir que quem lê o log vá procurar a linha na tabela de origem, que pode
                    // ter mudado desde então.
                    'titular' => $registro['dono_id'],
                ],
                $administradorId,
                $pdo,
            );
        });

        return $registro;
    }

    /**
     * Conta que o próprio titular excluiu não volta por ato administrativo.
     *
     * A regra vale só para `sis_usuarios`: restaurar uma demanda ou uma experiência devolve um
     * registro à operação, e restaurar uma conta devolve uma identidade inteira, com documento
     * cifrado, consentimentos e histórico. São coisas de tamanho diferente.
     *
     * @param array<string, mixed> $registro
     */
    private function restauravel(array $registro): bool
    {
        return !(
            ($registro['entidade'] ?? '') === 'sis_usuarios'
            && ($registro['pelo_titular'] ?? false) === true
        );
    }
}
