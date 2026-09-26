<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\ModeloConvite;

/** O modelo de mensagem do convite (D88). */
final class ModeloConviteTest extends TestCase
{
    public function testSemModeloSalvoUsaOPadrao(): void
    {
        $texto = ModeloConvite::montar(null, ['nome' => 'ARTHUR GOMES', 'demanda' => 'Galpão logístico']);

        self::assertSame(
            'Olá, Arthur. Vimos o seu perfil e gostaríamos de conversar sobre a demanda "Galpão logístico". Se tiver interesse, responda por aqui.',
            $texto,
        );
    }

    public function testModeloDaEmpresaUsaOsTresMarcadores(): void
    {
        $texto = ModeloConvite::montar(
            'Bom dia, {nome}! Temos uma obra em {local}: {demanda}.',
            ['nome' => 'sophia martins', 'demanda' => 'Plano setorial', 'local' => 'Manaus/AM'],
        );

        self::assertSame('Bom dia, Sophia! Temos uma obra em Manaus/AM: Plano setorial.', $texto);
    }

    public function testMarcadorSemDadoNaoDeixaEspacoNemPontuacaoSolta(): void
    {
        $texto = ModeloConvite::montar('Olá, {nome}. Obra em {local} .', ['nome' => '', 'local' => '']);

        self::assertSame('Olá. Obra em.', $texto);
    }

    public function testModeloEmBrancoVoltaAoPadrao(): void
    {
        self::assertSame(ModeloConvite::PADRAO, ModeloConvite::efetivo("   \n"));
    }

    public function testEmpresaEntraPeloNomeInteiro(): void
    {
        self::assertSame('Rio Negro Engenharia Civil', ModeloConvite::primeiroNome('RIO NEGRO ENGENHARIA CIVIL S.A.', true));
        self::assertSame('Construtora Manauara', ModeloConvite::primeiroNome('CONSTRUTORA MANAUARA LTDA', true));
        self::assertSame('Base Sólida Construções', ModeloConvite::primeiroNome('BASE SÓLIDA CONSTRUÇÕES LTDA.', true));
        self::assertSame('Mecânica Norte', ModeloConvite::primeiroNome('MECÂNICA NORTE EIRELI', true));
        self::assertSame('LTDA', ModeloConvite::primeiroNome('LTDA', true));   // só o sufixo: fica como veio
        self::assertSame('Rio', ModeloConvite::primeiroNome('RIO NEGRO ENGENHARIA CIVIL S.A.'));
    }

    public function testEmpresaNaoViraPrimeiraPalavraNaMensagem(): void
    {
        $texto = ModeloConvite::montar(null, ['nome' => 'MECÂNICA NORTE LTDA', 'empresa' => true, 'demanda' => 'Galpão']);

        self::assertStringStartsWith('Olá, Mecânica Norte. Vimos', $texto);
    }
}
