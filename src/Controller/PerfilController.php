<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\EmpresaCreaService;
use ProLink\Service\PerfilCreaService;
use ProLink\Service\PerfilEmpresaService;
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
 * O perfil público de terceiros virá em `/perfil/{id}` e usa os mesmos serviços de montagem: a
 * diferença é só o espectador passado, e é a `Visao` que decide o resto. Nenhuma regra de
 * visibilidade mora aqui.
 *
 * ## Um endereço, duas telas, e o perfil da conta é que escolhe
 *
 * Profissional e empresa são perfis diferentes do edital com dados diferentes — um tem RNP,
 * modalidades e acervo próprio; o outro tem registro de pessoa jurídica, quadro técnico e acervo
 * herdado. Cada um tem seu serviço de montagem e seu template. O que não se duplica é a rota:
 * `/perfil` é "o meu perfil" para quem estiver logado, e endereços separados por tipo de conta
 * só dariam ao usuário a chance de abrir o errado.
 */
final class PerfilController
{
    public function __construct(
        private readonly PerfilService $perfis = new PerfilService(),
        private readonly PerfilEmpresaService $perfisEmpresa = new PerfilEmpresaService(),
        private readonly VisibilidadeService $visibilidades = new VisibilidadeService(),
        private readonly PerfilCreaService $perfilCrea = new PerfilCreaService(),
        private readonly EmpresaCreaService $empresaCrea = new EmpresaCreaService(),
    ) {
    }

    public function index(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $ehEmpresa = Sessao::temPerfil(PERFIL_EMPRESA);

        $perfil = $ehEmpresa
            ? $this->perfisEmpresa->montar($usuarioId, $usuarioId)
            : $this->perfis->montar($usuarioId, $usuarioId);

        if ($perfil === null) {
            return View::erro(404, 'Perfil não encontrado.');
        }

        return View::render($ehEmpresa ? 'perfil/empresa.html.twig' : 'perfil/index.html.twig', [
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
     * Serve aos dois estados incompletos, dos dois lados — sem linha em `pro_profissionais` /
     * `pro_empresas` (a API estava fora do ar quando a conta nasceu) e com a data de
     * sincronização nula (a identidade entrou, o acervo não). O documento não é pedido de novo:
     * o serviço decifra o que já está guardado.
     */
    public function validarRegistro(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $ehEmpresa = Sessao::temPerfil(PERFIL_EMPRESA);

        try {
            $resultado = $ehEmpresa
                ? $this->empresaCrea->vincularEmpresa($usuarioId)
                : $this->perfilCrea->vincularProfissional($usuarioId);
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

        Flash::sucesso($ehEmpresa
            ? sprintf(
                'Registro da empresa validado no CREA (registro %s). Quadro técnico com %d '
                . 'vínculo(s) vigente(s) e %d ART(s) no acervo.',
                $resultado['registro_crea'],
                $resultado['vigentes'],
                $resultado['arts'],
            )
            : sprintf(
                'Registro validado no CREA (RNP %s). Seu acervo tem %d %s.',
                $resultado['rnp'],
                $resultado['arts'],
                $resultado['arts'] === 1 ? 'ART' : 'ARTs',
            ));

        View::redirecionar('/perfil');
    }
}
