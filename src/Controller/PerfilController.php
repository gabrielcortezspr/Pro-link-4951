<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\PerfilCreaService;
use ProLink\Service\PerfilService;
use ProLink\Service\ValidacaoException;
use ProLink\Service\VisibilidadeService;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;
use ProLink\Support\View;
use ProLink\Support\Visibilidade;
use Throwable;

/**
 * O perfil do próprio titular (RF03).
 *
 * O perfil público de terceiros virá em `/perfil/{id}` e usa o mesmo `PerfilService`: a diferença
 * é só o espectador passado, e é a `Visao` que decide o resto. Nenhuma regra de visibilidade
 * mora aqui.
 */
final class PerfilController
{
    public function __construct(
        private readonly PerfilService $perfis = new PerfilService(),
        private readonly VisibilidadeService $visibilidades = new VisibilidadeService(),
        private readonly PerfilCreaService $perfilCrea = new PerfilCreaService(),
    ) {
    }

    public function index(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $perfil    = $this->perfis->montar($usuarioId, $usuarioId);

        if ($perfil === null) {
            return View::erro(404, 'Perfil não encontrado.');
        }

        return View::render('perfil/index.html.twig', [
            'titulo' => 'Meu perfil',
            'perfil' => $perfil,
            'niveis_possiveis' => [
                VISIBILIDADE_PRIVADO     => 'Só eu',
                VISIBILIDADE_AUTENTICADO => 'Quem tem conta',
                VISIBILIDADE_PUBLICO     => 'Qualquer pessoa',
            ],
        ]);
    }

    /**
     * Salva os níveis de visibilidade de uma vez.
     *
     * O formulário manda `nivel[<chave do alvo>]`, e a chave é a mesma string que
     * `Visibilidade::chave()` produz. Chave que não corresponde a um alvo conhecido é ignorada em
     * silêncio: `name` adulterado no HTML é requisição inválida, não erro a exibir — e ignorar
     * é o comportamento fechado, porque o alvo desconhecido continua privado.
     */
    public function definirVisibilidade(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $enviados  = $_POST['nivel'] ?? [];
        $salvos    = 0;

        if (!is_array($enviados)) {
            $enviados = [];
        }

        try {
            foreach ($enviados as $chave => $nivel) {
                $alvo = Visibilidade::deChave((string) $chave);

                if ($alvo === null) {
                    continue;
                }

                $mudou = $this->visibilidades->definir(
                    $usuarioId, $alvo['entidade'], $alvo['id'], $alvo['campo'], (string) $nivel,
                );

                $salvos += $mudou ? 1 : 0;
            }
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/perfil');
        }

        Flash::sucesso($salvos === 0
            ? 'Nada mudou na visibilidade.'
            : 'Visibilidade atualizada. Nada fica visível sem você escolher.');

        View::redirecionar('/perfil');
    }

    /**
     * Resolve a pendência da D20: valida o registro no CREA depois do cadastro.
     *
     * Serve aos dois estados incompletos — sem linha em `pro_profissionais` (a API estava fora do
     * ar quando a conta nasceu) e com `prf_dt_sincronizacao` nula (o perfil entrou, o acervo
     * não). O CPF não é pedido de novo: o serviço decifra o que já está guardado.
     */
    public function validarRegistro(): string
    {
        $usuarioId = (int) Sessao::usuarioId();

        try {
            $resultado = $this->perfilCrea->vincularProfissional($usuarioId);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/perfil');
        } catch (Throwable $e) {
            error_log('Falha ao validar registro do usuário ' . $usuarioId . ': ' . $e->getMessage());
            Flash::erro('Não conseguimos falar com o CREA agora. Tente de novo em alguns minutos.');
            View::redirecionar('/perfil');
        }

        if ($resultado['aviso'] !== null) {
            Flash::aviso($resultado['aviso']);
            View::redirecionar('/perfil');
        }

        Flash::sucesso(sprintf(
            'Registro validado no CREA (RNP %s). Seu acervo tem %d %s.',
            $resultado['rnp'],
            $resultado['arts'],
            $resultado['arts'] === 1 ? 'ART' : 'ARTs',
        ));

        View::redirecionar('/perfil');
    }
}
