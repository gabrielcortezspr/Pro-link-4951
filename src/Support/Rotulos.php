<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Tradução de identificador de sistema para texto de interface.
 *
 * Nenhum SNAKE_CASE, nome de tabela ou nome de coluna aparece numa tela. A auditoria e o painel
 * leem constantes e nomes de esquema direto do banco, e sem esta camada eles chegariam crus ao
 * usuário: ACESSO_NEGADO, pro_denuncias, usu_status. Além de feio, é vazamento gratuito da forma
 * interna do sistema para quem não precisa dela.
 *
 * O valor cru continua existindo: quem audita de verdade quer o identificador exato. O padrão é
 * mostrar o rótulo e deixar o cru em title="" ou como texto secundário, nunca o contrário.
 *
 * Os três mapas são abertos por desenho: chave desconhecida cai no fallback por convenção
 * (prefixo fora, underline vira espaço, primeira maiúscula) em vez de sumir ou estourar. Ação
 * nova entra em Support\Auditoria e a tela continua legível antes de alguém lembrar de vir aqui.
 */
final class Rotulos
{
    /** Ações de Support\Auditoria. Substantivo, não verbo: a coluna responde "o que aconteceu". */
    private const ACOES = [
        'LOGIN'           => 'Entrada',
        'LOGIN_FALHOU'    => 'Entrada recusada',
        'LOGOUT'          => 'Saída',
        'BLOQUEIO_LOGIN'  => 'Bloqueio por tentativas',
        'ACESSO_NEGADO'   => 'Acesso negado',
        'CRIAR'           => 'Criação',
        'EDITAR'          => 'Alteração',
        'EXCLUIR'         => 'Exclusão',
        'RESTAURAR'       => 'Restauração',
        'CONSENTIR'       => 'Consentimento',
        'REVOGAR'         => 'Revogação de sessão',
        'EXPORTAR_DADOS'  => 'Exportação de dados',
        'CONSULTA_API'    => 'Consulta à API do CREA',
        'VALIDAR_ART'     => 'Validação de ART',
        'VALIDAR_CAT'     => 'Validação de CAT',
        'SELO_DIVERGENTE' => 'Selo divergente',
        'BLOQUEAR'        => 'Bloqueio de conta',
        'MODERAR'         => 'Moderação',
    ];

    /** Tabelas do esquema. Singular: a linha de auditoria fala de um registro, não da tabela. */
    private const ENTIDADES = [
        'sis_usuarios'        => 'Conta',
        'sis_sessoes'         => 'Sessão',
        'sis_perfis'          => 'Perfil de acesso',
        'sis_auditoria'       => 'Registro de auditoria',
        'sis_consentimentos'  => 'Consentimento',
        'sis_recuperacoes'    => 'Recuperação de senha',
        'sis_termos'          => 'Termo',
        'sis_notificacoes'    => 'Notificação',
        'sis_parametros'      => 'Parâmetro do sistema',
        'pro_profissionais'   => 'Profissional',
        'pro_empresas'        => 'Empresa',
        'pro_demandas'        => 'Demanda',
        'pro_demanda_tos'     => 'Termo de obra da demanda',
        'pro_manifestacoes'   => 'Manifestação de interesse',
        'pro_mensagens'       => 'Mensagem',
        'pro_denuncias'       => 'Denúncia',
        'pro_experiencias'    => 'Experiência',
        'pro_visibilidade'    => 'Visibilidade do perfil',
        'pro_prof_modalidades' => 'Modalidade do profissional',
        'crea_arts'           => 'ART',
        'crea_art_atividades' => 'Atividade de ART',
        'crea_cats'           => 'CAT',
        'crea_cat_arts'       => 'ART da CAT',
        'crea_tos'            => 'Termo de obra',
        'crea_quadro_tecnico' => 'Quadro técnico',
        'crea_modalidades'    => 'Modalidade',
        'crea_evidencias'     => 'Evidência de consulta',
        'mat_sessoes'         => 'Sessão de compatibilização',
        'mat_sessao_pool'     => 'Conjunto avaliado',
        // Não é tabela: o front controller registra acesso negado com a entidade 'rota'.
        'rota'                => 'Rota',
    ];

    /**
     * O que ocupa aud_campo na trilha. Quase sempre é coluna, e o prefixo de tabela some.
     *
     * A exceção é o consentimento: em sis_consentimentos o campo guarda a FINALIDADE
     * (ACEITE_TERMOS, CONSULTA_API…), não o nome de uma coluna, porque é ela que muda de
     * concedida para revogada. Uso correto, e as quatro entram aqui porque o fallback por
     * convenção devolveria "Consulta api", perdendo acento e sigla.
     */
    private const CAMPOS = [
        'ACEITE_TERMOS'     => 'Aceite dos termos',
        'CONSULTA_API'      => 'Consulta à API do CREA',
        'EXIBICAO_PERFIL'   => 'Exibição do perfil',
        'NOTIFICACOES'      => 'Notificações',
        'usu_status'        => 'Situação da conta',
        'usu_senha_hash'    => 'Senha',
        'usu_per_id'        => 'Perfil de acesso',
        'usu_email'         => 'E-mail',
        'usu_nome'          => 'Nome',
        'den_situacao'      => 'Situação da denúncia',
        'den_providencia'   => 'Providência',
        'emp_registro_crea' => 'Registro no CREA',
        'prf_rnp'           => 'RNP',
        'art_hash'          => 'Hash de validação',
    ];

    public static function acao(?string $valor): string
    {
        return self::traduzir($valor, self::ACOES);
    }

    public static function entidade(?string $valor): string
    {
        return self::traduzir($valor, self::ENTIDADES);
    }

    public static function campo(?string $valor): string
    {
        return self::traduzir($valor, self::CAMPOS);
    }

    /**
     * Fallback por convenção para chave fora do mapa: tira o prefixo de tabela quando houver,
     * troca underline por espaço e capitaliza. Pior caso é um rótulo desajeitado, nunca o
     * identificador cru na tela.
     *
     * @param array<string, string> $mapa
     */
    private static function traduzir(?string $valor, array $mapa): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        if (isset($mapa[$valor])) {
            return $mapa[$valor];
        }

        $texto = strtolower($valor);

        // Prefixo de tabela do projeto (sis_, pro_, crea_, mat_) e prefixo de coluna de três
        // letras (usu_, den_, art_): ruído de esquema, não informação para quem lê a tela.
        $texto = (string) preg_replace('/^(sis|pro|crea|mat|[a-z]{3})_/', '', $texto);

        return ucfirst(str_replace('_', ' ', $texto));
    }
}
