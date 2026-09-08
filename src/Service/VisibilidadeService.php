<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Repository\VisibilidadeRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Visao;
use ProLink\Support\Visibilidade;

/**
 * Aplica a visibilidade granular (RF03; edital 11.3).
 *
 * ## Dois portões, e o de cima fecha tudo
 *
 * O controle por campo só é consultado depois que o perfil está aberto. Dois fatos fecham o
 * perfil inteiro, independentemente do que o titular marcou campo a campo:
 *
 *   · **`EXIBICAO_PERFIL` revogado.** É o consentimento do item 11.3, e revogar tem de ter
 *     efeito imediato e total, não parcial.
 *   · **`prf_status_api != 'A'`.** Registro suspenso ou irregular no CREA zera a visibilidade
 *     (proposta: "profissional com registro suspenso tem visibilidade zerada automaticamente").
 *     A plataforma existe para mostrar capacidade comprovada; enquanto o conselho não reconhece
 *     o registro, não temos o que comprovar.
 *
 * Nenhum dos dois apaga as escolhas do titular. Voltando o consentimento ou o registro, o perfil
 * reabre como estava — o que ele decidiu campo a campo continua gravado.
 */
final class VisibilidadeService
{
    public function __construct(
        private readonly VisibilidadeRepository $visibilidades = new VisibilidadeRepository(),
        private readonly ConsentimentoRepository $consentimentos = new ConsentimentoRepository(),
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
    ) {
    }

    /**
     * Monta a visão de um espectador sobre um titular. Uma consulta, e o resto decide em memória.
     *
     * @param int|null $espectadorId null = visitante anônimo
     */
    public function visao(int $donoId, ?int $espectadorId): Visao
    {
        $ehDono = $espectadorId !== null && $espectadorId === $donoId;

        return new Visao(
            $this->visibilidades->mapaDoUsuario($donoId),
            $ehDono,
            $espectadorId !== null,
            $this->perfilAberto($donoId),
        );
    }

    /**
     * Os portões globais. Chamado uma vez por visão, nunca por campo.
     */
    public function perfilAberto(int $usuarioId): bool
    {
        if (!$this->consentimentos->concedido($usuarioId, FINALIDADE_EXIBICAO_PERFIL)) {
            return false;
        }

        // porId() já filtra usu_status = 'A', então conta excluída pelo titular volta null aqui
        // e o perfil fecha junto — que é o efeito esperado da exclusão lógica (D05).
        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            return false;
        }

        // Empresa e terceiro não têm situação no CREA para conferir: para eles o consentimento é
        // o portão inteiro.
        if (($usuario['per_codigo'] ?? '') !== PERFIL_PROFISSIONAL) {
            return true;
        }

        $profissional = $this->profissionais->porUsuario($usuarioId);

        // Profissional sem linha é validação pendente (D20): a conta diz que a pessoa tem
        // registro no CREA e nós ainda não confirmamos. Exibir seria publicar uma afirmação que
        // não podemos sustentar, então fica fechado até validar.
        return $profissional !== null && ($profissional['prf_status_api'] ?? null) === 'A';
    }

    /**
     * Grava a escolha do titular sobre um alvo.
     *
     * @return bool true se algo mudou de fato — deixa o chamador dizer a verdade na mensagem
     * @throws ValidacaoException nível ou campo fora da lista fechada
     */
    public function definir(
        int $usuarioId,
        string $entidade,
        ?int $entidadeId,
        ?string $campo,
        string $nivel,
    ): bool {
        if (!Visibilidade::nivelValido($nivel)) {
            throw new ValidacaoException('Nível de visibilidade inválido.');
        }

        if ($entidade === Visibilidade::PERFIL && $campo !== null && !Visibilidade::campoValido($campo)) {
            throw new ValidacaoException('Este campo do perfil não tem controle de visibilidade.');
        }

        // Ausência de linha e PRIVADO são o mesmo estado (D22), então precisam ser comparados
        // como o mesmo. Sem isso, salvar o formulário uma vez criava linha para todo campo que o
        // titular deixou como está — destruindo justamente o padrão-por-ausência que a decisão
        // estabeleceu — e enchia `sis_auditoria` de "PRIVADO → PRIVADO".
        $anterior = $this->visibilidades->nivel($usuarioId, $entidade, $entidadeId, $campo)
            ?? VISIBILIDADE_PRIVADO;

        if ($anterior === $nivel) {
            return false;
        }

        Database::transacao(function (PDO $pdo) use ($usuarioId, $entidade, $entidadeId, $campo, $nivel, $anterior): void {
            $this->visibilidades->definir($usuarioId, $entidade, $entidadeId, $campo, $nivel);

            Auditoria::registrar(
                Auditoria::EDITAR,
                'pro_visibilidade',
                $entidadeId,
                Visibilidade::chave($entidade, $entidadeId, $campo),
                $anterior,
                $nivel,
                $usuarioId,
                $pdo,
            );
        });

        return true;
    }

    /**
     * Fecha tudo. Chamado pela revogação de `EXIBICAO_PERFIL` e pela sincronização de status.
     *
     * Redundante com o portão global, e de propósito: o portão decide o que a tela mostra agora,
     * este método deixa o banco coerente com a decisão. Se um dia alguém consultar
     * `pro_visibilidade` sem passar pelo serviço, encontra o estado certo.
     */
    public function fecharTudo(int $usuarioId, string $motivo): int
    {
        return Database::transacao(function (PDO $pdo) use ($usuarioId, $motivo): int {
            $fechados = $this->visibilidades->fecharTudo($usuarioId);

            if ($fechados > 0) {
                Auditoria::registrar(
                    Auditoria::EDITAR, 'pro_visibilidade', null, 'todos',
                    $fechados . ' aberto(s)', 'PRIVADO — ' . $motivo, $usuarioId, $pdo,
                );
            }

            return $fechados;
        });
    }
}
