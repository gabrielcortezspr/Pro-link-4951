<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Como uma requisição sai daqui e volta (decisão D08).
 *
 * Só existe `get`: a API do desafio é somente leitura e roteada por query string, então um
 * verbo e um mapa de parâmetros descrevem qualquer chamada possível.
 *
 * Duas implementações: `TransporteCurl` em produção e `TransporteFixture` no desenvolvimento e
 * nos testes. Nenhuma variável de ambiente troca uma pela outra — a produção usa a de rede por
 * ser o padrão do construtor, e a de fixtures só entra onde alguém a injeta explicitamente.
 * Um interruptor em `.env` seria uma forma barata de a aplicação servir dado fictício achando
 * que é da API, e o item 8.4 do edital é exatamente sobre não fazer isso.
 */
interface Transporte
{
    /**
     * @param array<string, string|int> $params `p` é o recurso; o resto são filtros.
     * @throws \ProLink\Service\ApiIndisponivelException se a requisição não chegar a completar
     */
    public function get(array $params): RespostaHttp;
}
