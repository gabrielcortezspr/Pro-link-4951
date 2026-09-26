<?php

declare(strict_types=1);

namespace ProLink\Support;

use DateTimeImmutable;

/**
 * O "Para fazer agora" do Início (D90), sem banco: dado o estado da conta, a lista do que pede
 * uma ação dela, cada item com o destino que resolve.
 *
 * **Só entra o que tem ação.** Um número que não leva a lugar nenhum é o que o Início tinha antes
 * e o que esta lista substitui. Quando não há nada, a lista vem vazia e a tela diz isso, o que
 * também é informação.
 *
 * A ordem é de urgência para a outra parte, não de importância da conta: primeiro o que tem
 * alguém esperando resposta (mensagem, candidatura, convite), depois o que destrava o trabalho da
 * própria conta (rascunho, prazo, perfis a avaliar), por último a manutenção do perfil.
 */
final class Inicio
{
    /** Acima disto, o acervo guardado é antigo o bastante para valer uma nova consulta ao CREA. */
    public const DIAS_ACERVO_ANTIGO = 30;

    /** Prazo de início que vence dentro disto já é assunto do dia. */
    public const DIAS_PRAZO_PROXIMO = 7;

    /**
     * @param ?array{convites_novos: int, nao_lidas: int, perfil_aberto: bool,
     *               preferencias_vazias: bool, acervo_em: ?string, pode_atualizar: bool} $candidato
     *        o lado de quem tem registro no CREA; null para o Terceiro
     * @param list<array<string, mixed>> $demandas as demandas da conta, cada uma com `painel`
     *        (PainelDemandaService::painel) quando publicada e aberta
     * @return list<array{chave: string, titulo: string, detalhe: ?string, url: string, acao: string}>
     */
    public static function paraFazer(?array $candidato, array $demandas, string $hoje): array
    {
        $conversa = [];
        $trabalho = [];
        $perfil   = [];

        foreach ($demandas as $d) {
            $id     = (int) $d['dem_id'];
            $titulo = (string) $d['dem_titulo'];
            $painel = $d['painel'] ?? null;

            if (($d['dem_dt_publicacao'] ?? null) === null) {
                $trabalho[] = self::item(
                    'rascunho-' . $id, 'Demanda em rascunho', $titulo,
                    '/demandas/' . $id, 'Revisar e publicar',
                );
                continue;
            }

            if (($d['dem_situacao'] ?? '') === 'ENCERRADA' || !is_array($painel)) {
                continue;
            }

            if ($painel['candidaturas_novas'] > 0) {
                $n = (int) $painel['candidaturas_novas'];
                $conversa[] = self::item(
                    'candidaturas-' . $id,
                    self::plural($n, 'candidatura nova', 'candidaturas novas'), $titulo,
                    '/demandas/' . $id . '/contatos', $n === 1 ? 'Ver candidatura' : 'Ver candidaturas',
                );
            }

            if ($painel['nao_lidas'] > 0) {
                $n = (int) $painel['nao_lidas'];
                $conversa[] = self::item(
                    'mensagens-demanda-' . $id,
                    self::plural($n, 'mensagem não lida', 'mensagens não lidas'), $titulo,
                    '/demandas/' . $id . '/contatos', 'Responder',
                );
            }

            if (($painel['a_analisar'] ?? 0) > 0) {
                $n = (int) $painel['a_analisar'];
                $trabalho[] = self::item(
                    'avaliar-' . $id,
                    self::plural($n, 'perfil compatível a avaliar', 'perfis compatíveis a avaliar'), $titulo,
                    '/demandas/' . $id . '/compativeis', 'Avaliar perfis',
                );
            }

            $prazo = self::prazo($d['dem_inicio_ate'] ?? null, $hoje);

            if ($prazo !== null) {
                $trabalho[] = self::item('prazo-' . $id, $prazo, $titulo, '/demandas/' . $id, 'Rever o prazo');
            }
        }

        if ($candidato !== null) {
            if ($candidato['convites_novos'] > 0) {
                $n = (int) $candidato['convites_novos'];
                array_unshift($conversa, self::item(
                    'convites',
                    self::plural($n, 'convite novo de empresa', 'convites novos de empresas'), null,
                    '/manifestacoes', $n === 1 ? 'Ver convite' : 'Ver convites',
                ));
            }

            if ($candidato['nao_lidas'] > 0) {
                $n = (int) $candidato['nao_lidas'];
                $conversa[] = self::item(
                    'mensagens-candidato',
                    self::plural($n, 'mensagem não lida', 'mensagens não lidas'),
                    'Nas suas candidaturas e convites', '/manifestacoes', 'Responder',
                );
            }

            if (!$candidato['perfil_aberto']) {
                $perfil[] = self::item(
                    'perfil-fechado', 'Seu perfil está fechado',
                    'Nenhuma empresa encontra você entre os compatíveis enquanto ele estiver assim.',
                    '/perfil#privacidade', 'Rever a visibilidade',
                );
            }

            if ($candidato['preferencias_vazias']) {
                $perfil[] = self::item(
                    'preferencias', 'Regime e região de trabalho não informados',
                    'Com eles, a demanda que combina com você chega antes.',
                    '/perfil#preferencias', 'Informar',
                );
            }

            if ($candidato['pode_atualizar'] && self::acervoAntigo($candidato['acervo_em'], $hoje)) {
                $perfil[] = self::item(
                    'acervo', 'Acervo consultado no CREA há mais de ' . self::DIAS_ACERVO_ANTIGO . ' dias',
                    'ARTs e CATs novas só aparecem depois de uma nova consulta.',
                    '/perfil#acervo', 'Atualizar acervo',
                );
            }
        }

        return array_merge($conversa, $trabalho, $perfil);
    }

    /** Frase do prazo de início, quando ele já venceu ou vence nos próximos dias. */
    public static function prazo(?string $inicioAte, string $hoje): ?string
    {
        if ($inicioAte === null || $inicioAte === '') {
            return null;
        }

        $dias = (int) (new DateTimeImmutable(substr($hoje, 0, 10)))
            ->diff(new DateTimeImmutable(substr($inicioAte, 0, 10)))
            ->format('%r%a');

        return match (true) {
            $dias < 0                         => 'Prazo de início vencido',
            $dias === 0                       => 'Prazo de início vence hoje',
            $dias === 1                       => 'Prazo de início vence amanhã',
            $dias <= self::DIAS_PRAZO_PROXIMO => 'Prazo de início vence em ' . $dias . ' dias',
            default                           => null,
        };
    }

    /** Acervo nunca consultado também conta: não há o que mostrar sem a consulta. */
    public static function acervoAntigo(?string $consultadoEm, string $hoje): bool
    {
        if ($consultadoEm === null || $consultadoEm === '') {
            return true;
        }

        $limite = (new DateTimeImmutable(substr($hoje, 0, 10)))->modify('-' . self::DIAS_ACERVO_ANTIGO . ' days');

        return new DateTimeImmutable(substr($consultadoEm, 0, 10)) < $limite;
    }

    private static function plural(int $n, string $um, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $um : $varios);
    }

    /** @return array{chave: string, titulo: string, detalhe: ?string, url: string, acao: string} */
    private static function item(string $chave, string $titulo, ?string $detalhe, string $url, string $acao): array
    {
        return ['chave' => $chave, 'titulo' => $titulo, 'detalhe' => $detalhe, 'url' => $url, 'acao' => $acao];
    }
}
