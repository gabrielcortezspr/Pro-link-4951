<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\AcervoRepository;
use ProLink\Repository\ExperienciaRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Acervo;
use ProLink\Support\Auditoria;
use ProLink\Support\Visao;
use ProLink\Support\Visibilidade;

/**
 * Monta o perfil de um profissional para uma tela (RF03; proposta, cenário 03A).
 *
 * ## O filtro acontece aqui, não no template
 *
 * A `Visao` é aplicada durante a montagem, e o que sai deste serviço já é o que pode ser
 * exibido. O template não recebe o dado escondido para depois escondê-lo com `{% if %}` — assim
 * um `if` esquecido não vaza nada, porque o valor nunca chegou até lá (D22).
 *
 * ## O selo é conferido no momento de exibir, e nunca lido do banco como verdade
 *
 * Cada ART sai daqui com `selo_confere` recalculado a partir da própria linha. Não existe coluna
 * "está selada": a conferência é o ato de recalcular o HMAC e comparar. Divergência vira aviso
 * na tela e linha em `sis_auditoria`, e não exceção — derrubar a página esconderia o problema de
 * quem precisa vê-lo.
 */
final class PerfilService
{
    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
        private readonly AcervoRepository $acervo = new AcervoRepository(),
        private readonly ExperienciaRepository $experiencias = new ExperienciaRepository(),
        private readonly VisibilidadeService $visibilidades = new VisibilidadeService(),
    ) {
    }

    /**
     * @param int|null $espectadorId null = visitante anônimo
     * @return array<string, mixed>|null null quando a conta não existe
     */
    public function montar(int $donoId, ?int $espectadorId): ?array
    {
        $usuario = $this->usuarios->porId($donoId);

        if ($usuario === null) {
            return null;
        }

        $visao        = $this->visibilidades->visao($donoId, $espectadorId);
        $profissional = $this->profissionais->porUsuario($donoId);
        $ehDono       = $visao->ehDono();

        // Identidade nunca é ocultável: perfil sem nome e sem registro não identifica ninguém, e
        // um perfil que não identifica não ajuda o demandante a decidir nada. Quem não quer ser
        // encontrado revoga EXIBICAO_PERFIL, que fecha o perfil inteiro.
        $identidade = [
            'nome'          => $profissional['prf_nome_api'] ?? $usuario['usu_nome'],
            'nome_conta'    => $usuario['usu_nome'],
            'rnp'           => $profissional['prf_rnp'] ?? null,
            'registro_crea' => $profissional['prf_registro_crea'] ?? null,
            'status_api'    => $profissional['prf_status_api'] ?? null,
        ];

        $campos = $visao->filtrarCampos([
            'EMAIL'           => $usuario['usu_email'],
            'TELEFONE'        => $usuario['usu_telefone'],
            'RESUMO'          => $profissional['prf_resumo'] ?? null,
            'TIPO_CONTRATO'   => $profissional['prf_tipo_contrato'] ?? null,
            'DISPONIBILIDADE' => $profissional['prf_disponibilidade'] ?? null,
        ]);

        $modalidades = $visao->podeVer(Visibilidade::PERFIL, null, 'MODALIDADES') && $profissional !== null
            ? $this->profissionais->modalidades((int) $profissional['prf_id'])
            : [];

        // Uma leitura do acervo, usada tanto para a lista quanto para os controles do dono.
        $acervo = $profissional === null ? [] : $this->acervo->porRnp((string) $profissional['prf_rnp']);

        $experiencias = $profissional === null
            ? []
            : $this->experiencias->porProfissional((int) $profissional['prf_id']);

        return [
            'usuario_id'   => $donoId,
            'eh_dono'      => $ehDono,
            'perfil_aberto' => $visao->perfilAberto(),
            'identidade'   => $identidade,
            'campos'       => $campos,
            'modalidades'  => $modalidades,
            'arts'         => $this->arts($acervo, $visao, $espectadorId),
            'experiencias' => $this->experiencias($experiencias, $acervo, $visao),

            // Estados que só o titular vê, porque são recado para ele agir (D20, D21).
            'validacao_pendente'       => $ehDono && $profissional === null
                && $usuario['per_codigo'] === PERFIL_PROFISSIONAL,
            'sincronizacao_incompleta' => $ehDono && $profissional !== null
                && ($profissional['prf_dt_sincronizacao'] ?? null) === null,
            'em_construcao'            => (bool) ($profissional['prf_em_construcao'] ?? false),

            // Níveis escolhidos, para o dono desenhar os controles. Vazio para os outros.
            'niveis'       => $ehDono ? $this->niveis($visao, $acervo, $experiencias) : [],
            'campos_do_perfil' => Visibilidade::CAMPOS_DO_PERFIL,
        ];
    }

    /**
     * As ARTs visíveis, cada uma com o selo reconferido.
     *
     * @param list<array<string, mixed>> $acervo
     * @return list<array<string, mixed>>
     */
    private function arts(array $acervo, Visao $visao, ?int $espectadorId): array
    {
        $visiveis = [];

        foreach ($acervo as $art) {
            $id = (int) $art['art_id'];

            if (!$visao->podeVer(Visibilidade::ART, $id)) {
                continue;
            }

            $confere = hash_equals(
                (string) $art['art_hash'],
                Acervo::selo($art, $art['atividades']),
            );

            if (!$confere) {
                Auditoria::registrar(
                    Auditoria::SELO_DIVERGENTE, 'crea_arts', $id, 'art_hash',
                    (string) $art['art_hash'], 'recálculo não confere ao exibir', $espectadorId,
                );
            }

            $visiveis[] = [
                'id'           => $id,
                'numero'       => $art['art_numero'],
                'objeto'       => $art['art_objeto'],
                'contratante'  => $art['art_contratante_nome'],
                'situacao'     => $art['art_situacao'],
                'local'        => trim(($art['art_local_municipio'] ?? '') . ' ' . ($art['art_local_uf'] ?? '')),
                'dt_consulta'  => $art['art_dt_consulta'],
                'atividades'   => $art['atividades'],
                'selo_confere' => $confere,
                'nivel'        => $visao->nivel(Visibilidade::ART, $id),
            ];
        }

        return $visiveis;
    }

    /**
     * As experiências visíveis, cada uma sabendo se está amarrada a uma ART do acervo.
     *
     * **Nada aqui tem selo, e é o ponto.** O que sai deste método é o que a pessoa afirmou; o que
     * sai de `arts()` é o que a API confirmou. O template dá cores diferentes aos dois
     * (`.dado-declarado` e `.selo-art`), e a separação começa aqui, na montagem, e não lá.
     *
     * Quando `exp_art_id` aponta para uma ART, o número dela viaja junto — mas só se a ART também
     * estiver visível para este espectador. Uma ART fechada não pode reaparecer pela porta dos
     * fundos, escrita dentro de uma experiência aberta.
     *
     * @param list<array<string, mixed>> $experiencias
     * @param list<array<string, mixed>> $acervo
     * @return list<array<string, mixed>>
     */
    private function experiencias(array $experiencias, array $acervo, Visao $visao): array
    {
        $numeroPorId = [];

        foreach ($acervo as $art) {
            $id = (int) $art['art_id'];

            if ($visao->podeVer(Visibilidade::ART, $id)) {
                $numeroPorId[$id] = (string) $art['art_numero'];
            }
        }

        $visiveis = [];

        foreach ($experiencias as $experiencia) {
            $id = (int) $experiencia['exp_id'];

            if (!$visao->podeVer(Visibilidade::EXPERIENCIA, $id)) {
                continue;
            }

            $artId = $experiencia['exp_art_id'] === null ? null : (int) $experiencia['exp_art_id'];

            $visiveis[] = [
                'id'         => $id,
                'titulo'     => $experiencia['exp_titulo'],
                'descricao'  => $experiencia['exp_descricao'],
                'dt_inicio'  => $experiencia['exp_dt_inicio'],
                'dt_fim'     => $experiencia['exp_dt_fim'],
                'art_id'     => $artId,
                'art_numero' => $artId === null ? null : ($numeroPorId[$artId] ?? null),
                'nivel'      => $visao->nivel(Visibilidade::EXPERIENCIA, $id),
            ];
        }

        return $visiveis;
    }

    /**
     * As chaves de alvo que esta tela desenha — e, por isso mesmo, as únicas que o POST de
     * visibilidade aceita de volta (D27).
     *
     * Alvo novo no perfil precisa entrar aqui **junto** com o controle no template. Esquecer
     * significa que a tela mostra um seletor e o formulário descarta a escolha em silêncio: falha
     * fechada, que é o comportamento certo, mas parece defeito.
     *
     * @param list<array<string, mixed>> $acervo
     * @param list<array<string, mixed>> $experiencias
     * @return array<string, string> chave do alvo => nível escolhido
     */
    private function niveis(Visao $visao, array $acervo, array $experiencias): array
    {
        $niveis = [];

        foreach (Visibilidade::CAMPOS_DO_PERFIL as $campo) {
            $niveis[Visibilidade::chave(Visibilidade::PERFIL, null, $campo)]
                = $visao->nivel(Visibilidade::PERFIL, null, $campo);
        }

        foreach ($acervo as $art) {
            $id = (int) $art['art_id'];
            $niveis[Visibilidade::chave(Visibilidade::ART, $id, null)] = $visao->nivel(Visibilidade::ART, $id);
        }

        foreach ($experiencias as $experiencia) {
            $id = (int) $experiencia['exp_id'];
            $niveis[Visibilidade::chave(Visibilidade::EXPERIENCIA, $id, null)]
                = $visao->nivel(Visibilidade::EXPERIENCIA, $id);
        }

        return $niveis;
    }
}
