<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProLink\Support\Visao;
use ProLink\Support\Visibilidade;

/**
 * A regra de quem enxerga o quê, sem banco.
 *
 * O teste que mais importa aqui é o primeiro: alvo sem linha não aparece. É dele que depende a
 * afirmação "nada é público por padrão", e é ele que garante que um campo novo nasça invisível
 * sem ninguém precisar lembrar disso.
 */
final class VisibilidadeTest extends TestCase
{
    private function visao(array $mapa, bool $dono = false, bool $autenticado = false, bool $aberto = true): Visao
    {
        return new Visao($mapa, $dono, $autenticado, $aberto);
    }

    // ---------------------------------------------------------------- o padrão

    #[Test]
    public function alvo_sem_registro_nao_aparece_para_ninguem(): void
    {
        $anonimo = $this->visao([]);
        $logado  = $this->visao([], autenticado: true);

        self::assertFalse($anonimo->podeVer(Visibilidade::PERFIL, null, 'TELEFONE'));
        self::assertFalse($logado->podeVer(Visibilidade::PERFIL, null, 'TELEFONE'));
    }

    #[Test]
    public function campo_novo_nasce_invisivel_sem_migracao(): void
    {
        // O mapa tem uma escolha para RESUMO e nenhuma para um campo que ainda não existia.
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'RESUMO') => VISIBILIDADE_PUBLICO,
        ], autenticado: true);

        self::assertTrue($visao->podeVer(Visibilidade::PERFIL, null, 'RESUMO'));
        self::assertFalse($visao->podeVer(Visibilidade::PERFIL, null, 'CAMPO_QUE_NAO_EXISTIA'));
    }

    // ---------------------------------------------------------------- níveis

    #[Test]
    public function publico_aparece_para_anonimo(): void
    {
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'RESUMO') => VISIBILIDADE_PUBLICO,
        ]);

        self::assertTrue($visao->podeVer(Visibilidade::PERFIL, null, 'RESUMO'));
    }

    #[Test]
    public function autenticado_exige_login(): void
    {
        $mapa = [Visibilidade::chave(Visibilidade::PERFIL, null, 'EMAIL') => VISIBILIDADE_AUTENTICADO];

        self::assertFalse($this->visao($mapa)->podeVer(Visibilidade::PERFIL, null, 'EMAIL'));
        self::assertTrue($this->visao($mapa, autenticado: true)->podeVer(Visibilidade::PERFIL, null, 'EMAIL'));
    }

    #[Test]
    public function privado_nao_aparece_nem_para_quem_entrou(): void
    {
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'EMAIL') => VISIBILIDADE_PRIVADO,
        ], autenticado: true);

        self::assertFalse($visao->podeVer(Visibilidade::PERFIL, null, 'EMAIL'));
    }

    #[Test]
    public function nivel_desconhecido_no_banco_e_tratado_como_privado(): void
    {
        // Fail-closed: valor estranho na coluna fecha, não abre.
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'EMAIL') => 'QUALQUER_COISA',
        ], autenticado: true);

        self::assertFalse($visao->podeVer(Visibilidade::PERFIL, null, 'EMAIL'));
    }

    // ---------------------------------------------------------------- o dono

    #[Test]
    public function o_dono_ve_o_proprio_dado_mesmo_privado(): void
    {
        $visao = $this->visao([], dono: true, autenticado: true);

        self::assertTrue($visao->podeVer(Visibilidade::PERFIL, null, 'TELEFONE'));
    }

    #[Test]
    public function o_dono_ve_o_proprio_dado_mesmo_com_o_perfil_fechado(): void
    {
        // Senão revogar EXIBICAO_PERFIL trancaria a pessoa para fora do próprio cadastro.
        $visao = $this->visao([], dono: true, autenticado: true, aberto: false);

        self::assertTrue($visao->podeVer(Visibilidade::PERFIL, null, 'TELEFONE'));
    }

    // ---------------------------------------------------------------- o portão global

    #[Test]
    public function perfil_fechado_esconde_ate_o_que_esta_marcado_publico(): void
    {
        // Revogar a exibição ou perder a regularidade no CREA tem efeito total, não parcial.
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'RESUMO') => VISIBILIDADE_PUBLICO,
        ], autenticado: true, aberto: false);

        self::assertFalse($visao->podeVer(Visibilidade::PERFIL, null, 'RESUMO'));
    }

    #[Test]
    public function fechar_o_perfil_nao_apaga_a_escolha_do_titular(): void
    {
        // O nível continua legível para a tela do próprio dono desenhar o controle.
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'RESUMO') => VISIBILIDADE_PUBLICO,
        ], dono: true, autenticado: true, aberto: false);

        self::assertSame(VISIBILIDADE_PUBLICO, $visao->nivel(Visibilidade::PERFIL, null, 'RESUMO'));
    }

    // ---------------------------------------------------------------- ART individual

    #[Test]
    public function art_a_art_sao_alvos_independentes(): void
    {
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::ART, 10, null) => VISIBILIDADE_PUBLICO,
            Visibilidade::chave(Visibilidade::ART, 11, null) => VISIBILIDADE_PRIVADO,
        ]);

        self::assertTrue($visao->podeVer(Visibilidade::ART, 10));
        self::assertFalse($visao->podeVer(Visibilidade::ART, 11));
        self::assertFalse($visao->podeVer(Visibilidade::ART, 12), 'ART sem escolha continua fechada');
    }

    // ---------------------------------------------------------------- filtro de assembleia

    #[Test]
    public function filtrar_devolve_so_o_que_pode_sair_do_controlador(): void
    {
        // O template recebe o resultado disto, e não o mapa inteiro: `if` esquecido não vaza o
        // que nunca chegou até lá.
        $visao = $this->visao([
            Visibilidade::chave(Visibilidade::PERFIL, null, 'RESUMO')   => VISIBILIDADE_PUBLICO,
            Visibilidade::chave(Visibilidade::PERFIL, null, 'EMAIL')    => VISIBILIDADE_AUTENTICADO,
            Visibilidade::chave(Visibilidade::PERFIL, null, 'TELEFONE') => VISIBILIDADE_PRIVADO,
        ]);

        $visivel = $visao->filtrarCampos([
            'RESUMO'   => 'Engenheira florestal',
            'EMAIL'    => 'ana@exemplo.com',
            'TELEFONE' => '92 90000-0000',
        ]);

        self::assertSame(['RESUMO' => 'Engenheira florestal'], $visivel);
    }

    // ---------------------------------------------------------------- listas fechadas

    #[Test]
    public function so_os_campos_da_lista_tem_controle(): void
    {
        self::assertTrue(Visibilidade::campoValido('TELEFONE'));
        self::assertFalse(Visibilidade::campoValido('USU_SENHA_HASH'));
        self::assertFalse(Visibilidade::campoValido('NOME'), 'perfil sem nome não identifica ninguém');
    }

    // ---------------------------------------------------------------- chave vinda do formulário

    #[Test]
    public function a_chave_do_formulario_volta_a_ser_alvo(): void
    {
        self::assertSame(
            ['entidade' => Visibilidade::PERFIL, 'id' => null, 'campo' => 'RESUMO'],
            Visibilidade::deChave('PERFIL:-:RESUMO'),
        );
        self::assertSame(
            ['entidade' => Visibilidade::ART, 'id' => 203, 'campo' => null],
            Visibilidade::deChave('ART:203:-'),
        );
    }

    #[Test]
    public function chave_adulterada_no_html_e_ignorada(): void
    {
        // Entrada de fora: devolver null faz o controlador pular o alvo, e pular é o
        // comportamento fechado — o alvo desconhecido continua privado.
        self::assertNull(Visibilidade::deChave('USUARIOS:-:USU_SENHA_HASH'));
        self::assertNull(Visibilidade::deChave('PERFIL:-:CAMPO_INVENTADO'));
        self::assertNull(Visibilidade::deChave('PERFIL'));
        self::assertNull(Visibilidade::deChave(''));
    }

    #[Test]
    public function ida_e_volta_da_chave_sao_simetricas(): void
    {
        $chave = Visibilidade::chave(Visibilidade::ART, 42, null);
        $alvo  = Visibilidade::deChave($chave);

        self::assertSame($chave, Visibilidade::chave($alvo['entidade'], $alvo['id'], $alvo['campo']));
    }

    #[Test]
    public function so_os_tres_niveis_sao_aceitos(): void
    {
        self::assertTrue(Visibilidade::nivelValido(VISIBILIDADE_PUBLICO));
        self::assertFalse(Visibilidade::nivelValido('SEMI_PUBLICO'));
    }
}
