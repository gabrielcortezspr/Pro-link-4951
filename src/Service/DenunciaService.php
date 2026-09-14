<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\DenunciaRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Validacao;

/**
 * Denúncia e moderação (RF06; edital 8.5g).
 *
 * Os vocabulários são listas fechadas de classe, e não texto livre: é o que impede alvo ou
 * providência inventados de chegarem ao banco, e é o que a fila do moderador usa para montar
 * os filtros. Toda escrita passa por Database::transacao() com a auditoria dentro, porque
 * moderação sem trilha não é moderação.
 */
final class DenunciaService
{
    /** Alvos possíveis, iguais ao comentário de pro_denuncias em _arq/estrutura.sql. */
    public const ENTIDADES = ['USUARIO', 'DEMANDA', 'MENSAGEM', 'EXPERIENCIA'];

    /** @var array<string, string> código => rótulo exibido */
    public const TIPOS = [
        'PERFIL_FRAUDULENTO' => 'Perfil ou vaga fraudulenta',
        'DADO_ENGANOSO'      => 'Dado enganoso',
        'CONTEUDO_IMPROPRIO' => 'Conteúdo impróprio',
        'SPAM'               => 'Spam',
    ];

    public const SITUACOES    = ['PENDENTE', 'EM_ANALISE', 'RESOLVIDA'];
    public const PROVIDENCIAS = ['ADVERTIR', 'BLOQUEAR', 'REMOVER', 'IMPROCEDENTE'];

    public function __construct(
        private readonly DenunciaRepository $denuncias = new DenunciaRepository(),
    ) {
    }

    /** Código desconhecido volta como veio, para a tela nunca ficar em branco. */
    public static function rotuloDoTipo(string $tipo): string
    {
        return self::TIPOS[$tipo] ?? $tipo;
    }

    /**
     * Abre uma denúncia e devolve o id.
     *
     * A denúncia é sempre vinculada a um alvo: sem isso ela não é apurável, e o moderador não
     * teria sobre o que agir.
     */
    public function abrir(
        int $autorId,
        string $entidade,
        int $entidadeId,
        string $tipo,
        string $descricao,
        ?string $evidencia = null,
    ): int {
        (new Validacao())
            ->entre('entidade', $entidade, self::ENTIDADES, 'Alvo de denúncia desconhecido.')
            ->entre('tipo', $tipo, array_keys(self::TIPOS), 'Escolha um tipo de denúncia.')
            ->exigir('alvo', $entidadeId > 0, 'Denúncia genérica, sem alvo vinculado, não é permitida.')
            ->obrigatorio('descricao', $descricao, 'Descreva o ocorrido.')
            ->tamanhoMaximo('descricao', $descricao, 5000, 'No máximo 5000 caracteres.')
            ->lancarSeInvalido();

        if ($entidade === 'USUARIO' && $entidadeId === $autorId) {
            throw new ValidacaoException(
                'Você não pode denunciar a própria conta.',
                ['alvo' => 'Alvo inválido.']
            );
        }

        return Database::transacao(
            function (PDO $pdo) use ($autorId, $entidade, $entidadeId, $tipo, $descricao, $evidencia): int {
                $id = (new DenunciaRepository($pdo))
                    ->criar($autorId, $entidade, $entidadeId, $tipo, $descricao, $evidencia);

                // Depois do insert, porque o id só existe agora; com o $pdo da transação, para
                // a linha da trilha desfazer junto se algo abaixo falhar.
                Auditoria::registrar(
                    Auditoria::CRIAR,
                    'pro_denuncias',
                    $id,
                    null,
                    null,
                    ['entidade' => $entidade, 'entidade_id' => $entidadeId, 'tipo' => $tipo],
                    $autorId,
                    $pdo,
                );

                return $id;
            }
        );
    }

    /** @return list<array<string, mixed>> */
    public function fila(?string $situacao = null, ?string $tipo = null, int $limite = 50): array
    {
        return $this->denuncias->fila($situacao, $tipo, $limite);
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        return $this->denuncias->porId($id);
    }
}
