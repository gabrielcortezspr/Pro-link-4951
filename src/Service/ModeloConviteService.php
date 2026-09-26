<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\ModeloConvite;
use ProLink\Support\Validacao;

/**
 * O modelo de mensagem de convite de cada conta (D88): ler, salvar e voltar ao padrão.
 *
 * É da conta, e não de `pro_empresas`, porque quem publica demanda também pode ser contratante
 * pessoa física, que não tem cadastro de empresa.
 */
final class ModeloConviteService
{
    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
    ) {
    }

    /** @return array{texto: string, personalizado: bool} */
    public function modelo(int $usuarioId): array
    {
        $salvo = $this->usuarios->modeloConvite($usuarioId);

        return ['texto' => ModeloConvite::efetivo($salvo), 'personalizado' => trim((string) $salvo) !== ''];
    }

    /**
     * Grava o modelo. Texto em branco, ou idêntico ao padrão, volta ao padrão (null), para uma
     * mudança futura do padrão chegar a quem nunca personalizou de fato.
     *
     * @throws ValidacaoException
     */
    public function salvar(int $usuarioId, string $texto): void
    {
        $texto = trim(str_replace("\r\n", "\n", $texto));

        (new Validacao())
            ->tamanhoMaximo('modelo', $texto, ModeloConvite::TAMANHO_MAXIMO,
                sprintf('No máximo %d caracteres.', ModeloConvite::TAMANHO_MAXIMO))
            ->lancarSeInvalido();

        $novo  = ($texto === '' || $texto === ModeloConvite::PADRAO) ? null : $texto;
        $antes = $this->usuarios->modeloConvite($usuarioId);

        if ($novo === $antes) {
            return;
        }

        $this->usuarios->salvarModeloConvite($usuarioId, $novo);

        Auditoria::registrar(
            Auditoria::EDITAR, 'sis_usuarios', $usuarioId, 'usu_modelo_convite', $antes, $novo, $usuarioId,
        );
    }
}
