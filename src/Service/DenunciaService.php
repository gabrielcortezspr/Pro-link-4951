<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\DenunciaRepository;
use ProLink\Repository\SessaoRepository;
use ProLink\Repository\UsuarioRepository;
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
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly SessaoRepository $sessoes = new SessaoRepository(),
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

    /**
     * Trata a denúncia e, quando a providência é BLOQUEAR, executa a operação atômica 5:
     * status da conta, sessões revogadas e as três escritas na trilha, tudo ou nada.
     *
     * @return array{bloqueado: bool, sessoes_derrubadas: int}
     */
    public function tratar(int $denunciaId, int $moderadorId, string $situacao, ?string $providencia): array
    {
        $v = new Validacao();
        $v->entre('situacao', $situacao, self::SITUACOES, 'Situação inválida.');

        if ($providencia !== null) {
            $v->entre('providencia', $providencia, self::PROVIDENCIAS, 'Providência inválida.');
        }

        $v->lancarSeInvalido();

        return Database::transacao(
            function (PDO $pdo) use ($denunciaId, $moderadorId, $situacao, $providencia): array {
                // Ler antes de escrever: a auditoria precisa do valor anterior de den_situacao,
                // e o bloqueio precisa saber qual é o alvo.
                $repo      = new DenunciaRepository($pdo);
                $denuncia  = $repo->porId($denunciaId);

                if ($denuncia === null) {
                    throw new ValidacaoException('Denúncia não encontrada.');
                }

                $repo->tratar($denunciaId, $moderadorId, $situacao, $providencia);

                Auditoria::registrar(
                    Auditoria::MODERAR,
                    'pro_denuncias',
                    $denunciaId,
                    'den_situacao',
                    $denuncia['den_situacao'],
                    $situacao,
                    $moderadorId,
                    $pdo,
                );

                if ($providencia !== 'BLOQUEAR') {
                    return ['bloqueado' => false, 'sessoes_derrubadas' => 0];
                }

                if ($denuncia['den_entidade'] !== 'USUARIO') {
                    throw new ValidacaoException('Só é possível bloquear quando o alvo da denúncia é uma conta.');
                }

                $alvo = (new UsuarioRepository($pdo))->porId((int) $denuncia['den_entidade_id']);

                if ($alvo === null) {
                    throw new ValidacaoException('A conta alvo não está mais ativa.');
                }

                // Sem esta guarda um administrador bloqueia o outro, ou a si mesmo, e o acesso
                // ao painel se perde no meio da demonstração.
                if ($alvo['per_codigo'] === PERFIL_ADMIN || (int) $alvo['usu_id'] === $moderadorId) {
                    throw new ValidacaoException('Conta de administração não pode ser bloqueada por aqui.');
                }

                // Estado antes de efeito: o status é a verdade que persiste, a revogação é a
                // consequência. Na transação as duas desfazem juntas, mas a ordem é o que a
                // trilha vai mostrar, e a trilha é o produto.
                (new UsuarioRepository($pdo))->alterarStatus((int) $alvo['usu_id'], STATUS_INATIVO);
                $derrubadas = (new SessaoRepository($pdo))->revogarTodasDoUsuario((int) $alvo['usu_id']);

                Auditoria::registrar(
                    Auditoria::BLOQUEAR,
                    'sis_usuarios',
                    (int) $alvo['usu_id'],
                    'usu_status',
                    STATUS_ATIVO,
                    STATUS_INATIVO,
                    $moderadorId,
                    $pdo,
                );

                Auditoria::registrar(
                    Auditoria::REVOGAR,
                    'sis_sessoes',
                    (int) $alvo['usu_id'],
                    null,
                    null,
                    [
                        'sessoes_revogadas' => $derrubadas,
                        'motivo'            => 'bloqueio_administrativo',
                        'denuncia_id'       => $denunciaId,
                    ],
                    $moderadorId,
                    $pdo,
                );

                return ['bloqueado' => true, 'sessoes_derrubadas' => $derrubadas];
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
