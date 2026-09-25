<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\DemandaRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\DenunciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * Registro de denúncia pelo usuário autenticado (RF06).
 *
 * O autor é sempre o da sessão, nunca um id vindo da requisição: denúncia é ato identificado, e
 * é o que permite ao moderador apurar. O alvo vem da URL e é resolvido para um nome legível
 * quando é conta, porque cartão de alvo mostrando número não diz a ninguém o que está sendo
 * denunciado.
 */
final class DenunciaController
{
    public function __construct(
        private readonly DenunciaService $denuncias = new DenunciaService(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly DemandaRepository $demandas = new DemandaRepository(),
    ) {
    }

    public function formulario(): string
    {
        return $this->renderizar(
            strtoupper((string) ($_GET['entidade'] ?? 'USUARIO')),
            (int) ($_GET['alvo'] ?? 0),
        );
    }

    public function registrar(): string
    {
        $entidade  = strtoupper((string) ($_POST['entidade'] ?? ''));
        $alvoId    = (int) ($_POST['alvo_id'] ?? 0);
        $tipo      = (string) ($_POST['tipo'] ?? '');
        $descricao = (string) ($_POST['descricao'] ?? '');
        $evidencia = ((string) ($_POST['evidencia'] ?? '')) ?: null;

        try {
            $id = $this->denuncias->abrir(
                (int) Sessao::usuarioId(),
                $entidade,
                $alvoId,
                $tipo,
                $descricao,
                $evidencia,
            );
        } catch (ValidacaoException $e) {
            // Devolve o formulário em vez de redirecionar, como AuthController::cadastrar e
            // ::autenticar: só assim o erro fica no campo que o causou e o que foi digitado
            // não se perde. Redirect aqui traria de volta um formulário vazio com uma mensagem
            // genérica.
            return $this->renderizar(
                $entidade,
                $alvoId,
                ['tipo' => $tipo, 'descricao' => $descricao, 'evidencia' => $evidencia ?? ''],
                $e->erros(),
                $e->getMessage(),
            );
        }

        Flash::sucesso('Denúncia registrada. Ela entra na fila de moderação com o número ' . $id . '.');

        // Destino provisório: é a única tela de "minha conta" que existe hoje. Passa a ser o
        // perfil do alvo quando /perfil/{id} existir.
        View::redirecionar('/privacidade');
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $erros
     */
    private function renderizar(
        string $entidade,
        int $alvoId,
        array $valores = [],
        array $erros = [],
        ?string $aviso = null,
    ): string {
        if ($entidade === 'USUARIO') {
            $alvo = $this->usuarios->porId($alvoId);

            if ($alvo === null) {
                return View::erro(404, 'Alvo de denúncia não encontrado.');
            }

            $rotulo = (string) $alvo['usu_nome'];
            $voltar = '/perfil/' . $alvoId;
        } elseif ($entidade === 'DEMANDA') {
            // A página da demanda tem o link "Denunciar" desde a D84, e o formulário diz qual
            // demanda é pelo título, como a pessoa acabou de lê-la. A regra de quem enxerga é a
            // de DemandaController::ver: rascunho é só do dono, e a exclusão lógica fecha. Sem
            // ela, trocar o número na URL revelaria o título de rascunho alheio.
            $demanda = $this->demandas->porId($alvoId);
            $visivel = $demanda !== null
                && $demanda['dem_status'] === STATUS_ATIVO
                && ($demanda['dem_dt_publicacao'] !== null
                    || (int) $demanda['dem_usu_id'] === Sessao::usuarioId());

            if (!$visivel) {
                return View::erro(404, 'Alvo de denúncia não encontrado.');
            }

            $rotulo = 'Demanda nº ' . $alvoId . ' · ' . $demanda['dem_titulo'];
            $voltar = '/demandas/' . $alvoId;
        } else {
            // Mensagem e experiência não têm ponto de entrada na interface ainda. O serviço já
            // as aceita como alvo; o rótulo legível entra junto com o link que as denuncia.
            $rotulo = $entidade . ' #' . $alvoId;
            $voltar = '/inicio';
        }

        return View::render('denuncia/nova.html.twig', [
            'entidade'    => $entidade,
            'alvo_id'     => $alvoId,
            'alvo_rotulo' => $rotulo,
            // Cancelar devolve a pessoa para onde ela estava, e não para o início.
            'voltar'      => $voltar,
            'tipos'       => DenunciaService::TIPOS,
            'valores'     => $valores,
            'erros'       => $erros,
            'aviso'       => $aviso,
        ]);
    }
}
