<?php

declare(strict_types=1);

namespace ProLink\Service;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use ProLink\Repository\NotificacaoRepository;
use ProLink\Support\View;
use Throwable;

/**
 * Notificação por e-mail (RF07). Versão mínima da E1: enfileirar e despachar.
 *
 * Enfileirar nunca falha por causa do SMTP — a linha em sis_notificacoes entra na transação da
 * operação e o envio é tentado depois. Assim um servidor de e-mail fora do ar não impede ninguém
 * de criar conta, e a fila fica como registro do que o sistema tentou mandar.
 *
 * Em desenvolvimento o destino é o Mailpit do docker-compose (`:8025` para ver, `:1025` para
 * enviar), sem autenticação e sem TLS. Sem MAIL_HOST configurado o despacho não tenta nada e
 * deixa a linha na fila, que é o comportamento correto num ambiente sem SMTP.
 *
 * A E5 completa isto com os eventos de manifestação, demanda e denúncia.
 */
final class NotificacaoService
{
    public const CADASTRO           = 'CADASTRO';
    public const RECUPERACAO_SENHA  = 'RECUPERACAO_SENHA';

    public function __construct(
        private readonly NotificacaoRepository $notificacoes = new NotificacaoRepository(),
    ) {
    }

    /**
     * Monta o corpo a partir de um template Twig e põe na fila.
     *
     * @param array<string, mixed> $dados variáveis do template
     */
    public function enfileirar(
        int $usuarioId,
        string $tipo,
        string $destinatario,
        string $assunto,
        string $template,
        array $dados = [],
    ): int {
        return $this->notificacoes->enfileirar(
            $usuarioId,
            $tipo,
            $destinatario,
            $assunto,
            View::render($template, $dados),
        );
    }

    /**
     * Tenta enviar o que está na fila.
     *
     * @return array{enviadas: int, falhas: int, ignoradas: int}
     */
    public function despachar(int $limite = 50): array
    {
        $pendentes = $this->notificacoes->pendentes($limite);

        if (MAIL_HOST === '') {
            return ['enviadas' => 0, 'falhas' => 0, 'ignoradas' => count($pendentes)];
        }

        $enviadas = 0;
        $falhas   = 0;

        foreach ($pendentes as $notificacao) {
            try {
                $this->enviar($notificacao['not_destinatario'], $notificacao['not_assunto'], $notificacao['not_corpo']);
                $this->notificacoes->marcarEnviada((int) $notificacao['not_id']);
                $enviadas++;
            } catch (Throwable $e) {
                // Falha de envio não derruba a fila: registra no registro e segue para a próxima.
                $this->notificacoes->marcarFalha((int) $notificacao['not_id'], $e->getMessage());
                error_log('Falha ao enviar notificação ' . $notificacao['not_id'] . ': ' . $e->getMessage());
                $falhas++;
            }
        }

        return ['enviadas' => $enviadas, 'falhas' => $falhas, 'ignoradas' => 0];
    }

    /** @throws PHPMailerException */
    private function enviar(string $destinatario, string $assunto, string $corpo): void
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host    = MAIL_HOST;
        $mail->Port    = MAIL_PORT;
        $mail->CharSet = 'UTF-8';

        if (MAIL_USERNAME !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;
        }

        // Mailpit não fala TLS. Em produção MAIL_ENCRYPTION é 'tls' e a criptografia em trânsito
        // do item 11.3 vale também para o e-mail.
        if (in_array(MAIL_ENCRYPTION, ['tls', 'ssl'], true)) {
            $mail->SMTPSecure = MAIL_ENCRYPTION;
        } else {
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($destinatario);
        $mail->Subject = $assunto;
        $mail->isHTML(true);
        $mail->Body    = $corpo;
        $mail->AltBody = strip_tags($corpo);

        $mail->send();
    }
}
