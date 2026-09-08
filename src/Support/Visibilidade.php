<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Quem enxerga o quê (RF03; edital 11.3; proposta, "visibilidade granular por ART individual").
 *
 * Classe sem estado e sem banco: as regras ficam aqui, o `VisibilidadeService` aplica e o
 * `VisibilidadeRepository` guarda. Mesma divisão de `Support\Acervo`, pelo mesmo motivo — é
 * regra que a banca vai perguntar, então precisa de teste rápido e leitura fácil.
 *
 * ## Ausência de registro é PRIVADO
 *
 * "Nada público por padrão" é implementado pela ausência, não por linhas escritas no cadastro.
 * Perfil recém-criado não tem nenhuma linha em `pro_visibilidade` e, por isso mesmo, não mostra
 * nada a ninguém. Um campo novo que apareça no futuro nasce invisível sem ninguém lembrar de
 * escrever a migração — o desenho falha fechado por construção, e não por disciplina.
 *
 * Isto **não** contradiz a D10, que exigiu distinguir "nunca concedido" de "concedido e
 * revogado" no consentimento. Lá a diferença tem efeito jurídico: o titular precisa poder provar
 * o que autorizou e quando. Aqui não existe efeito nenhum entre "nunca marquei" e "marquei
 * privado" — o resultado é o mesmo, ninguém vê —, e guardar linha para todo campo de todo
 * usuário só criaria estado para manter.
 *
 * ## O dono sempre se vê; ninguém mais tem passe livre
 *
 * Não há exceção de administrador (D06: sem superusuário implícito). Quem administra a
 * plataforma modera denúncia e conteúdo publicado, não abre o perfil fechado de ninguém.
 */
final class Visibilidade
{
    public const PERFIL      = 'PERFIL';
    public const ART         = 'ART';
    public const CAT         = 'CAT';
    public const EXPERIENCIA = 'EXPERIENCIA';

    /**
     * Campos do perfil que o titular pode abrir ou fechar um a um.
     *
     * Lista fechada de propósito: campo que não está aqui não é controlável, e um valor
     * arbitrário vindo de formulário é recusado antes de virar linha no banco. Nome e registro
     * profissional não aparecem — sem eles o perfil não identifica ninguém, e um perfil que não
     * identifica não serve para o demandante decidir nada.
     */
    public const CAMPOS_DO_PERFIL = [
        'EMAIL',
        'TELEFONE',
        'RESUMO',
        'MODALIDADES',
        'TIPO_CONTRATO',
        'DISPONIBILIDADE',
    ];

    /** Do mais fechado ao mais aberto. A ordem é a regra: comparar posição responde tudo. */
    private const ORDEM = [
        VISIBILIDADE_PRIVADO     => 0,
        VISIBILIDADE_AUTENTICADO => 1,
        VISIBILIDADE_PUBLICO     => 2,
    ];

    public static function nivelValido(string $nivel): bool
    {
        return isset(self::ORDEM[$nivel]);
    }

    public static function campoValido(string $campo): bool
    {
        return in_array($campo, self::CAMPOS_DO_PERFIL, true);
    }

    /**
     * O nível que um espectador alcança: anônimo enxerga só o público, quem entrou enxerga
     * também o de autenticados. Não existe nível intermediário por perfil de acesso — empresa e
     * terceiro veem o mesmo, porque o edital manda todo perfil poder buscar (Anexo I, item 2).
     */
    public static function alcanceDe(bool $autenticado): string
    {
        return $autenticado ? VISIBILIDADE_AUTENTICADO : VISIBILIDADE_PUBLICO;
    }

    /**
     * Decide um item.
     *
     * @param string|null $nivelGravado nível da linha, ou null quando não há linha (= privado)
     * @param bool        $ehDono       o espectador é o titular do dado
     * @param bool        $autenticado  o espectador está logado
     * @param bool        $perfilAberto portão global: consentimento EXIBICAO_PERFIL vigente e
     *                                  registro regular no CREA. Falso fecha tudo de uma vez.
     */
    public static function podeVer(
        ?string $nivelGravado,
        bool $ehDono,
        bool $autenticado,
        bool $perfilAberto,
    ): bool {
        // O titular enxerga o próprio dado mesmo com o perfil fechado — senão revogar a exibição
        // o trancaria para fora do próprio cadastro.
        if ($ehDono) {
            return true;
        }

        if (!$perfilAberto) {
            return false;
        }

        $nivel = $nivelGravado ?? VISIBILIDADE_PRIVADO;

        if (!self::nivelValido($nivel) || $nivel === VISIBILIDADE_PRIVADO) {
            return false;
        }

        // PUBLICO é visível a todos; AUTENTICADO exige login. Comparação por posição na ordem.
        return $nivel === VISIBILIDADE_PUBLICO || $autenticado;
    }

    /**
     * Chave de um alvo, para indexar o mapa em memória sem consultar o banco por campo.
     *
     * O `uq_vis_alvo` do banco usa as mesmas quatro colunas; esta função é a versão em string da
     * mesma identidade, para as duas nunca discordarem sobre o que é "o mesmo alvo".
     */
    public static function chave(string $entidade, ?int $entidadeId, ?string $campo): string
    {
        return $entidade . ':' . ($entidadeId ?? '-') . ':' . ($campo ?? '-');
    }
}
