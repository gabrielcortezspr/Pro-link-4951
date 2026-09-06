<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Sessão autenticada. Envolve $_SESSION para que nenhum outro lugar do código toque nela.
 *
 * Três cuidados de segurança que moram aqui e só aqui (edital 8.5a, 11.3; OWASP A07):
 *   · o id da sessão é regenerado no login, contra fixação;
 *   · a sessão expira por inatividade (SESSION_LIFETIME_MINUTES), não só pelo cookie;
 *   · o usuário na sessão é uma cópia mínima (id, nome, perfil) — nunca a linha inteira,
 *     nunca o hash da senha, nunca o documento.
 *
 * O registro server-side em sis_sessoes (para revogação remota) entra na E1, no
 * AutenticacaoService. Esta classe é a camada de baixo dele.
 */
final class Sessao
{
    private const CHAVE_USUARIO   = 'usuario';
    private const CHAVE_ATIVIDADE = 'ultima_atividade';

    /** Abre a sessão com os parâmetros de cookie seguros e aplica a expiração por inatividade. */
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $segundos = SESSION_LIFETIME_MINUTES * 60;

        ini_set('session.gc_maxlifetime', (string) $segundos);
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => $segundos,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => APP_ENV !== 'dev',
        ]);

        session_start();

        $ultima = $_SESSION[self::CHAVE_ATIVIDADE] ?? null;

        if (is_int($ultima) && (time() - $ultima) > $segundos) {
            self::encerrar();
            session_start();
            Flash::aviso('Sua sessão expirou por inatividade. Entre novamente.');
        }

        $_SESSION[self::CHAVE_ATIVIDADE] = time();
    }

    /**
     * Marca o usuário como autenticado.
     *
     * @param array{id: int, nome: string, perfil: string} $usuario
     */
    public static function autenticar(array $usuario): void
    {
        session_regenerate_id(true);

        $_SESSION[self::CHAVE_USUARIO] = [
            'id'     => (int) $usuario['id'],
            'nome'   => (string) $usuario['nome'],
            'perfil' => (string) $usuario['perfil'],
        ];
        $_SESSION[self::CHAVE_ATIVIDADE] = time();
    }

    /** Destrói a sessão e o cookie. */
    public static function encerrar(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** @return array{id: int, nome: string, perfil: string}|null */
    public static function usuarioAtual(): ?array
    {
        return $_SESSION[self::CHAVE_USUARIO] ?? null;
    }

    public static function usuarioId(): ?int
    {
        return self::usuarioAtual()['id'] ?? null;
    }

    public static function autenticado(): bool
    {
        return self::usuarioAtual() !== null;
    }

    /** Verdadeiro se o usuário logado tem um dos perfis. Sem perfil implícito de superusuário. */
    public static function temPerfil(string ...$perfis): bool
    {
        $atual = self::usuarioAtual()['perfil'] ?? null;

        return $atual !== null && in_array($atual, $perfis, true);
    }
}
