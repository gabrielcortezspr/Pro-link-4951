<?php

declare(strict_types=1);

namespace ProLink\Tests\Service;

use PHPUnit\Framework\TestCase;
use ProLink\Repository\NotificacaoRepository;
use ProLink\Service\NotificacaoService;

/**
 * Quais avisos respeitam o consentimento de notificações (D85). Regra pura, sem banco.
 *
 * O caminho gravado (a linha com situação `N` e o motivo em `not_erro`) é conferido contra o banco
 * real dentro de uma transação desfeita, porque depende de conta com consentimento revogado.
 */
final class NotificacaoServiceTest extends TestCase
{
    /** Aviso de relacionamento só sai com o consentimento vigente: é a promessa da tela de privacidade. */
    public function testAvisosDeRelacionamentoDependemDoConsentimento(): void
    {
        self::assertTrue(NotificacaoService::dependeDeConsentimento('MANIFESTACAO'));
        self::assertTrue(NotificacaoService::dependeDeConsentimento('DEMANDA'));
        self::assertTrue(NotificacaoService::dependeDeConsentimento('MENSAGEM'));
    }

    /** Cadastro e recuperação de senha são transacionais: sem eles a pessoa não entra na conta. */
    public function testAvisosDeContaSaemMesmoSemConsentimento(): void
    {
        self::assertFalse(NotificacaoService::dependeDeConsentimento(NotificacaoService::CADASTRO));
        self::assertFalse(NotificacaoService::dependeDeConsentimento(NotificacaoService::RECUPERACAO_SENHA));
    }

    /** A linha não enviada não pode ser `A`, senão o despacho a pegaria como pendente. */
    public function testSituacaoDoAvisoNaoEnviadoFicaForaDaFila(): void
    {
        self::assertNotSame(STATUS_ATIVO, NotificacaoRepository::NAO_ENVIADA);
    }
}
