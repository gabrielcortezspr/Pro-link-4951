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
    private const CHAVE_TOKEN     = 'sessao_servidor';

    /**
     * Prefixo `__Host-` no nome do cookie de sessão quando a conexão é HTTPS (D86).
     *
     * O prefixo é uma trava que o navegador aplica sozinho: só aceita gravar um cookie assim se ele
     * vier com `Secure`, `Path=/` e SEM atributo `Domain`. O efeito é fechar a fixação de sessão
     * por subdomínio ou por caminho: nenhuma outra página do mesmo host (nem um subdomínio, nem um
     * `/caminho` diferente, nem uma resposta em HTTP) consegue plantar ou sobrescrever o
     * `__Host-PHPSESSID` do titular. É a diferença entre "o cookie tem Secure" e "o navegador
     * recusa qualquer versão insegura dele".
     */
    private const NOME_COOKIE_SEGURO = '__Host-PHPSESSID';
    private const NOME_COOKIE        = 'PHPSESSID';

    /** Abre a sessão com os parâmetros de cookie seguros e aplica a expiração por inatividade. */
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $segundos = SESSION_LIFETIME_MINUTES * 60;

        ini_set('session.gc_maxlifetime', (string) $segundos);
        ini_set('session.use_strict_mode', '1');

        $cookie = self::parametrosCookie(self::conexaoSegura(), $segundos);
        session_name($cookie['nome']);
        session_set_cookie_params($cookie['params']);

        session_start();

        $ultima = $_SESSION[self::CHAVE_ATIVIDADE] ?? null;

        if (is_int($ultima) && (time() - $ultima) > $segundos) {
            self::reiniciar();
            Flash::aviso('Sua sessão expirou por inatividade. Entre novamente.');
        }

        $_SESSION[self::CHAVE_ATIVIDADE] = time();
    }

    /**
     * Nome do cookie e parâmetros, decididos só pelo esquema da conexão (D86). Função pura, sem
     * tocar em `$_SERVER` nem em `$_SESSION`, para o teste conferir a regra sem subir servidor.
     *
     * Em HTTPS: nome com prefixo `__Host-` e `Secure`, que juntos exigem `Path=/` e proíbem
     * `Domain` (por isso `domain` não é passado). Fora de HTTPS (CLI dos testes, e o HTTP da 8080
     * que só redireciona): nome simples e sem `Secure`, senão o navegador recusaria o cookie e o
     * login não funcionaria em texto puro.
     *
     * @return array{nome: string, params: array{lifetime:int, path:string, httponly:bool, samesite:string, secure:bool}}
     */
    public static function parametrosCookie(bool $https, int $segundos): array
    {
        return [
            'nome'   => $https ? self::NOME_COOKIE_SEGURO : self::NOME_COOKIE,
            'params' => [
                'lifetime' => $segundos,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $https,
            ],
        ];
    }

    /**
     * A requisição chegou por HTTPS? Só cabeçalhos que o nginx escreve (D81): `HTTPS=on` e
     * `REQUEST_SCHEME=https`, os dois em `fastcgi_params`. Nada de `X-Forwarded-Proto`, que o
     * cliente poderia forjar. Antes disto o `Secure` dependia de `APP_ENV !== 'dev'`, e o notebook
     * do Demo Day roda como `dev`: servia o cookie de sessão em HTTPS sem `Secure`, que é o furo
     * que esta troca fecha.
     */
    private static function conexaoSegura(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');
    }

    /**
     * Abre uma sessão nova depois de encerrar a anterior.
     *
     * Existe porque logout e exclusão de conta precisam destruir a sessão e, ainda assim, deixar
     * uma mensagem para a tela seguinte — e Flash mora na sessão. Sem isto, os controllers
     * chamavam session_start() direto, furando a regra que esta classe existe para garantir: o
     * $_SESSION é tocado aqui, em Csrf e em Flash, e em nenhum outro lugar.
     */
    public static function reiniciar(): void
    {
        self::encerrar();
        session_start();
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

    /**
     * Hash da linha correspondente em sis_sessoes. Guardado aqui para que o front controller
     * possa conferir, a cada requisição, se a sessão ainda está válida do lado do servidor —
     * é o que faz o bloqueio de usuário pelo administrador (E6) derrubar quem já está logado.
     */
    public static function definirTokenServidor(string $hash): void
    {
        $_SESSION[self::CHAVE_TOKEN] = $hash;
    }

    public static function tokenServidor(): ?string
    {
        return $_SESSION[self::CHAVE_TOKEN] ?? null;
    }

    /**
     * Troca o perfil guardado na sessão pelo que o banco diz agora.
     *
     * Chamado pelo front controller quando a reconferência de sessão encontra divergência. Não
     * regenera o identificador de sessão nem mexe no relógio de atividade: não é um login novo, é
     * a mesma sessão deixando de carregar uma informação vencida.
     *
     * Devolve o perfil anterior, para quem chama poder registrar a troca na trilha. Mudança de
     * papel é evento raro e vale linha de auditoria; a reconferência que não acha divergência,
     * que é o caso de toda requisição normal, não escreve nada.
     */
    public static function trocarPerfil(string $perfil): ?string
    {
        $atual = $_SESSION[self::CHAVE_USUARIO]['perfil'] ?? null;

        if ($atual === null || $atual === $perfil) {
            return null;
        }

        $_SESSION[self::CHAVE_USUARIO]['perfil'] = $perfil;

        return $atual;
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
