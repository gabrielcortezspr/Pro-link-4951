<?php

declare(strict_types=1);

namespace ProLink\Tests\Service;

use PHPUnit\Framework\TestCase;
use ProLink\Service\DenunciaService;

/**
 * As listas fechadas da denúncia, que são estáticas e não tocam o banco.
 *
 * A escrita, a transação e a trilha de auditoria são verificadas contra o banco real em
 * `scripts/verificar-e6.php`, porque dependem de conexão e de linha gravada.
 */
final class DenunciaServiceTest extends TestCase
{
    /** Tipo fora da lista não chega ao banco: é aqui que a lista é a fonte. */
    public function testTiposDeDenunciaSaoListaFechada(): void
    {
        self::assertCount(4, DenunciaService::TIPOS);
        self::assertArrayHasKey('DADO_ENGANOSO', DenunciaService::TIPOS);
    }

    /** Os quatro alvos são os mesmos que o comentário de pro_denuncias declara. */
    public function testEntidadesDenunciaveisSaoAsQuatroDoSchema(): void
    {
        self::assertSame(['USUARIO', 'DEMANDA', 'MENSAGEM', 'EXPERIENCIA'], DenunciaService::ENTIDADES);
    }

    /** A tela nunca fica em branco: código desconhecido volta como veio. */
    public function testRotuloDeTipoDesconhecidoDevolveOProprioCodigo(): void
    {
        self::assertSame('XPTO', DenunciaService::rotuloDoTipo('XPTO'));
    }
}
