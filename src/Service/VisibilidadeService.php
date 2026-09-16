<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\EmpresaRepository;
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
 *   · **Registro no CREA não confirmado.** Para o profissional é `prf_status_api != 'A'`, que
 *     zera a visibilidade (proposta: "profissional com registro suspenso tem visibilidade zerada
 *     automaticamente"). A plataforma existe para mostrar capacidade comprovada; enquanto o
 *     conselho não reconhece o registro, não temos o que comprovar. Para a empresa não existe
 *     situação equivalente na API — não há `emp_status` —, então o que se confere é a pendência
 *     de validação: sem linha em `pro_empresas`, fechado.
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
        private readonly EmpresaRepository $empresas = new EmpresaRepository(),
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
     * Várias visões de uma vez, para o feed (D52).
     *
     * Mesma regra da `visao()`, montada em duas consultas para o lote inteiro em vez de duas por
     * titular: `mapaDeUsuarios()` traz as escolhas e `perfisAbertos()` os dois portões globais.
     * Delega a decisão à própria `Visao`, e não a uma cópia da regra aqui, pelo mesmo motivo que
     * `perfilAberto()` delega ao caminho em lote: dois lugares decidindo quem vê o quê é como
     * eles passam a discordar.
     *
     * @param  list<int> $donoIds
     * @return array<int, Visao>
     */
    public function visoes(array $donoIds, ?int $espectadorId): array
    {
        $donoIds = array_values(array_unique(array_map('intval', $donoIds)));

        if ($donoIds === []) {
            return [];
        }

        $mapas   = $this->visibilidades->mapaDeUsuarios($donoIds);
        $abertos = $this->perfisAbertos($donoIds);

        $visoes = [];

        foreach ($donoIds as $donoId) {
            $visoes[$donoId] = new Visao(
                $mapas[$donoId] ?? [],
                $espectadorId !== null && $espectadorId === $donoId,
                $espectadorId !== null,
                $abertos[$donoId] ?? false,
            );
        }

        return $visoes;
    }

    /**
     * Os portões globais. Chamado uma vez por visão, nunca por campo.
     *
     * Delega ao caminho em lote em vez de repetir a regra. A duplicação seria tentadora — um
     * `if` a menos para um titular só — e seria exatamente o jeito de os dois caminhos passarem a
     * discordar sobre quem aparece no motor e quem aparece no perfil público.
     */
    public function perfilAberto(int $usuarioId): bool
    {
        return $this->perfisAbertos([$usuarioId])[$usuarioId] ?? false;
    }

    /**
     * Os mesmos portões, para muitos titulares de uma vez — duas a quatro consultas no total,
     * conforme os perfis que aparecerem no lote, e não três por candidato.
     *
     * É o caminho do motor. `CompatibilizacaoService` confere o portão por candidato antes de
     * pontuar, e com a versão de um titular só isso era N+1 na abertura do feed: três consultas
     * vezes o número de candidatos, num arquivo cujo vizinho (`CandidatoRepository`) foi escrito
     * justamente para evitar isso.
     *
     * A ordem das negativas é a mesma da versão individual, e importa para quem depura: sem
     * consentimento fecha antes de tudo; conta inativa fecha em seguida; só então o registro no
     * conselho é olhado.
     *
     * @param  list<int> $usuarioIds
     * @return array<int, bool> usuario_id => perfil aberto
     */
    public function perfisAbertos(array $usuarioIds): array
    {
        $usuarioIds = array_values(array_unique(array_map('intval', $usuarioIds)));

        if ($usuarioIds === []) {
            return [];
        }

        $consentiu = $this->consentimentos->concedidosEmLote($usuarioIds, FINALIDADE_EXIBICAO_PERFIL);

        // perfisAtivos() já filtra usu_status = 'A', então conta excluída pelo titular ou
        // bloqueada pela moderação simplesmente não volta — e a ausência fecha o perfil, que é o
        // efeito esperado da exclusão lógica (D05) e do bloqueio da E6.
        $perfis = $this->usuarios->perfisAtivos($usuarioIds);

        // Cada tabela de registro é consultada só se houver alguém daquele perfil no lote. Sem
        // isto, conferir um titular só custaria quatro consultas onde antes eram três — o caminho
        // individual pagaria pela existência do caminho em lote, e `buscarPorIds()` já devolve
        // vazio sem ir ao banco quando a lista chega vazia.
        $porPerfil = static fn (string $codigo): array => array_keys(
            array_filter($perfis, static fn (string $p): bool => $p === $codigo),
        );

        $situacaoApi = $this->profissionais->statusApiEmLote($porPerfil(PERFIL_PROFISSIONAL));
        $empresaOk   = $this->empresas->validadasEmLote($porPerfil(PERFIL_EMPRESA));

        $abertos = [];

        foreach ($usuarioIds as $id) {
            $abertos[$id] = ($consentiu[$id] ?? false)
                && isset($perfis[$id])
                && self::registroSustenta($perfis[$id], $situacaoApi, $empresaOk, $id);
        }

        return $abertos;
    }

    /**
     * O registro no conselho sustenta a exibição deste perfil?
     *
     * @param array<int, ?string> $situacaoApi
     * @param array<int, bool>    $empresaOk
     */
    private static function registroSustenta(
        string $perfil,
        array $situacaoApi,
        array $empresaOk,
        int $usuarioId,
    ): bool {
        // Empresa: a API não devolve situação de empresa — a busca por CNPJ traz razão social,
        // nome fantasia, registro e data, e não existe `emp_status`. Não há, portanto, o
        // equivalente ao `prf_status_api != 'A'`. O que se pode conferir é a pendência da D20,
        // que vale igual dos dois lados: sem linha em `pro_empresas`, a conta afirma um registro
        // no CREA que nós ainda não confirmamos, e exibir seria publicar essa afirmação.
        if ($perfil === PERFIL_EMPRESA) {
            return $empresaOk[$usuarioId] ?? false;
        }

        // Terceiro não tem registro no conselho para conferir: para ele o consentimento é o
        // portão inteiro.
        if ($perfil !== PERFIL_PROFISSIONAL) {
            return true;
        }

        // Profissional sem linha é validação pendente (D20): a conta diz que a pessoa tem
        // registro no CREA e nós ainda não confirmamos. Exibir seria publicar uma afirmação que
        // não podemos sustentar, então fica fechado até validar.
        return ($situacaoApi[$usuarioId] ?? null) === 'A';
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
     * Aplica o formulário de visibilidade inteiro — ou nenhuma parte dele.
     *
     * `$chavesPermitidas` são os alvos que a tela desenhou para este titular (as chaves de
     * `perfil.niveis`). Alvo fora dessa lista é ignorado: sem isso, um `name` adulterado no HTML
     * criava linha em `pro_visibilidade` para qualquer `art_id` do banco, inclusive de ARTs que
     * não são do titular. Não vazava nada — a `Visao` consultada é sempre a do dono do perfil
     * exibido, e a lista de ARTs de uma tela nunca sai de `pro_visibilidade` —, mas enchia a
     * tabela e a trilha de auditoria de linhas sem sentido, e deixava uma escolha "PÚBLICO"
     * esperando por uma ART que ainda ia chegar.
     *
     * A validação dos níveis acontece **antes** de qualquer escrita. Uma tela de privacidade não
     * pode terminar com "deu erro" e metade das escolhas aplicadas.
     *
     * @param array<array-key, mixed> $enviados         `nivel[<chave>] => <nível>`, cru do POST
     * @param list<string>            $chavesPermitidas alvos legítimos deste titular
     * @return int quantos mudaram de fato — deixa o chamador dizer a verdade na mensagem
     * @throws ValidacaoException nível fora dos três; nada é gravado
     */
    public function definirLote(int $usuarioId, array $enviados, array $chavesPermitidas): int
    {
        $lote = Visibilidade::lote($enviados, $chavesPermitidas);

        if ($lote['nivel_invalido']) {
            throw new ValidacaoException(
                'Nível de visibilidade inválido. Nenhuma das suas escolhas foi alterada.'
            );
        }

        $salvos = 0;

        foreach ($lote['aceitos'] as $alvo) {
            $salvos += $this->definir(
                $usuarioId, $alvo['entidade'], $alvo['id'], $alvo['campo'], $alvo['nivel'],
            ) ? 1 : 0;
        }

        return $salvos;
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
