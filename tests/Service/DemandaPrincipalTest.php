<?php

declare(strict_types=1);

namespace ProLink\Tests\Service;

use PHPUnit\Framework\TestCase;
use ProLink\Service\DemandaService;
use ProLink\Service\ValidacaoException;

/** A demanda com atividades tem sempre ao menos uma principal (D94). */
final class DemandaPrincipalTest extends TestCase
{
    public function testUnicaAtividadeNaoViraSecundaria(): void
    {
        $this->expectException(ValidacaoException::class);

        DemandaService::exigirPrincipal(['TOS_1.6.4' => DemandaService::PESO_SECUNDARIO], 'secundaria');
    }

    public function testUltimaPrincipalNaoViraSecundariaMesmoComOutras(): void
    {
        $this->expectException(ValidacaoException::class);

        DemandaService::exigirPrincipal([
            'TOS_1.6.4' => DemandaService::PESO_SECUNDARIO,
            'TOS_1.2.6' => DemandaService::PESO_SECUNDARIO,
        ], 'secundaria');
    }

    public function testRemoverAUltimaPrincipalDeixandoSecundariaEhRecusado(): void
    {
        $this->expectExceptionMessage('Torne outra atividade principal antes de removê-la');

        DemandaService::exigirPrincipal(['TOS_1.2.6' => DemandaService::PESO_SECUNDARIO], 'remover');
    }

    public function testComOutraPrincipalPode(): void
    {
        DemandaService::exigirPrincipal([
            'TOS_1.6.4' => DemandaService::PESO_SECUNDARIO,
            'TOS_1.6.6' => DemandaService::PESO_PRINCIPAL,
        ], 'secundaria');

        // Rascunho sem atividade nenhuma continua possível: é a publicação que o recusa.
        DemandaService::exigirPrincipal([], 'remover');

        $this->addToAssertionCount(1);
    }
}
