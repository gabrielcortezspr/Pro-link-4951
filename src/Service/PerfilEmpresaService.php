<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\AcervoRepository;
use ProLink\Repository\EmpresaRepository;
use ProLink\Repository\QuadroTecnicoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Acervo;
use ProLink\Support\Auditoria;
use ProLink\Support\Visao;
use ProLink\Support\Visibilidade;

/**
 * Monta o perfil de uma empresa para uma tela (RF03; edital Anexo I, item 3).
 *
 * O par de `PerfilService`, e as duas regras de lá valem inteiras aqui: **o filtro da `Visao`
 * acontece na montagem**, nunca no template (D22), e **o Selo ART é reconferido no momento de
 * exibir**, nunca lido do banco como verdade (D17).
 *
 * ## O acervo da empresa é emprestado, e a tela diz isso
 *
 * Toda ART que aparece aqui foi registrada por uma pessoa, não pela empresa. Por isso cada uma
 * sai deste serviço com o RNP e o nome de quem a registrou: esconder isso apresentaria como
 * capacidade própria o que é capacidade do quadro técnico, e é exatamente a confusão que a
 * plataforma existe para desfazer. Quem decide quais ARTs a empresa herda é a view
 * `crea_evidencias`, pelo vínculo vigente (D19) — ver `AcervoRepository::porEmpresa`.
 *
 * ## Visibilidade sobre ART herdada não é conflito
 *
 * A empresa e o profissional podem marcar níveis diferentes para a mesma ART, e isso é correto,
 * não uma corrida: `pro_visibilidade` é por usuário, então cada um decide o que **o seu** perfil
 * mostra. A empresa fechar uma ART não a fecha no perfil de quem a registrou, e vice-versa.
 */
final class PerfilEmpresaService
{
    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly EmpresaRepository $empresas = new EmpresaRepository(),
        private readonly QuadroTecnicoRepository $quadros = new QuadroTecnicoRepository(),
        private readonly AcervoRepository $acervo = new AcervoRepository(),
        private readonly VisibilidadeService $visibilidades = new VisibilidadeService(),
        // Parâmetro novo entra no fim, pela mesma razão registrada no PerfilService.
        private readonly PortfolioService $portfolio = new PortfolioService(),
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

        $visao   = $this->visibilidades->visao($donoId, $espectadorId);
        $empresa = $this->empresas->porUsuario($donoId);
        $ehDono  = $visao->ehDono();

        // Identidade não é ocultável (D23): razão social e registro no CREA são o que identifica
        // a empresa, e um perfil que não identifica não ajuda ninguém a decidir nada. Quem não
        // quer ser encontrado revoga EXIBICAO_PERFIL, que fecha o perfil inteiro.
        $identidade = [
            'razao_social'     => $empresa['emp_razao_social'] ?? $usuario['usu_nome'],
            'nome_fantasia'    => $empresa['emp_nome_fantasia'] ?? null,
            'nome_conta'       => $usuario['usu_nome'],
            'registro_crea'    => $empresa['emp_registro_crea'] ?? null,
            'dt_registro_crea' => $empresa['emp_dt_registro_crea'] ?? null,
        ];

        $campos = $visao->filtrarCampos([
            'EMAIL'    => $usuario['usu_email'],
            'TELEFONE' => $usuario['usu_telefone'],
            'RESUMO'   => $empresa['emp_resumo'] ?? null,
        ]);

        $registroCrea = $empresa['emp_registro_crea'] ?? null;

        $quadro = $registroCrea === null ? [] : $this->quadros->porEmpresa((string) $registroCrea);
        $acervo = $empresa === null ? [] : $this->acervo->porEmpresa((int) $empresa['emp_id']);

        // Nome de quem registrou cada ART, para o acervo não virar uma lista de RNPs soltos. Vem
        // do quadro técnico já lido, e não de uma consulta por ART.
        $nomes = [];

        foreach ($quadro as $membro) {
            $nomes[(string) $membro['qut_pro_rnp']] = $membro['qut_pro_nome'];
        }

        return [
            'usuario_id'    => $donoId,
            'eh_dono'       => $ehDono,
            'perfil_aberto' => $visao->perfilAberto(),
            'identidade'    => $identidade,
            'campos'        => $campos,
            'quadro'        => $this->quadro($quadro),
            'arts'          => $this->arts($acervo, $nomes, $visao, $espectadorId),

            // Estados que só o titular vê, porque são recado para ele agir (D20, D21).
            'validacao_pendente'       => $ehDono && $empresa === null
                && $usuario['per_codigo'] === PERFIL_EMPRESA,
            'sincronizacao_incompleta' => $ehDono && $empresa !== null
                && ($empresa['emp_dt_sincronizacao'] ?? null) === null,

            // Níveis escolhidos, para o dono desenhar os controles. Vazio para os outros.
            'niveis'           => $ehDono ? $this->niveis($visao, $acervo) : [],
            'campos_do_perfil' => Visibilidade::CAMPOS_DA_EMPRESA,
        ];
    }

    // ---------------------------------------------------------------- interno

    /**
     * O quadro técnico como a tela precisa dele, com o vínculo já resolvido em booleano.
     *
     * `qut_dt_fim IS NULL` é a regra inteira da herança (D19), e traduzi-la aqui evita que o
     * template tenha de saber que uma data nula significa "vigente".
     *
     * @param list<array<string, mixed>> $quadro
     * @return list<array<string, mixed>>
     */
    private function quadro(array $quadro): array
    {
        $membros = [];

        foreach ($quadro as $membro) {
            $membros[] = [
                'rnp'         => (string) $membro['qut_pro_rnp'],
                'nome'        => $membro['qut_pro_nome'],
                'funcao'      => $membro['qut_funcao'],
                'responsavel' => ($membro['qut_tipo'] ?? null) === 'R',
                'dt_inicio'   => $membro['qut_dt_inicio'],
                'dt_fim'      => $membro['qut_dt_fim'],
                'vigente'     => ($membro['qut_dt_fim'] ?? null) === null,
            ];
        }

        return $membros;
    }

    /**
     * As ARTs visíveis, cada uma com o selo reconferido e com quem a registrou.
     *
     * @param list<array<string, mixed>> $acervo
     * @param array<string, string|null> $nomes RNP => nome no quadro técnico
     * @return list<array<string, mixed>>
     */
    private function arts(array $acervo, array $nomes, Visao $visao, ?int $espectadorId): array
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

            $rnp = (string) $art['art_pro_rnp'];

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
                'cats'         => [],
                'cat'          => null,
                'nivel'        => $visao->nivel(Visibilidade::ART, $id),
                'rnp'          => $rnp,
                'profissional' => $nomes[$rnp] ?? null,
            ];
        }

        // A certidão de cada ART visível, numa consulta para a lista toda (D76). Só as visíveis:
        // a CAT herda a visibilidade da ART que certifica.
        $certidoes = $this->portfolio->certidoesPorArt(array_column($visiveis, 'id'), $espectadorId);

        foreach ($visiveis as $i => $art) {
            $visiveis[$i]['cats'] = $certidoes[$art['id']] ?? [];
            // `cat` é a primeira da lista, para quem lê uma só: o retrato congelado de
            // manifestação e o resumo do topo.
            $visiveis[$i]['cat']  = $visiveis[$i]['cats'][0] ?? null;
        }

        return $visiveis;
    }

    /**
     * @param list<array<string, mixed>> $acervo
     * @return array<string, string> chave do alvo => nível escolhido
     */
    private function niveis(Visao $visao, array $acervo): array
    {
        $niveis = [];

        foreach (Visibilidade::CAMPOS_DA_EMPRESA as $campo) {
            $niveis[Visibilidade::chave(Visibilidade::PERFIL, null, $campo)]
                = $visao->nivel(Visibilidade::PERFIL, null, $campo);
        }

        foreach ($acervo as $art) {
            $id = (int) $art['art_id'];
            $niveis[Visibilidade::chave(Visibilidade::ART, $id, null)] = $visao->nivel(Visibilidade::ART, $id);
        }

        return $niveis;
    }
}
