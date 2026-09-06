# Dependências de terceiros

Exigido pelo edital, Anexo I, item 8.3.2e, e pelo item 6 da minuta de licença institucional
(Anexo V): componentes de terceiros permanecem sob suas licenças originais e devem ser
integralmente declarados.

## Runtime

| Componente | Versão | Licença | Para quê |
|---|---|---|---|
| PHP | 8.2 | PHP License 3.01 | exigido pelo item 8.1.1a |
| MariaDB | 10.11 | GPL-2.0 | exigido pelo item 8.1.2a |
| nginx | 1.27 | BSD-2-Clause | servidor web |

## Composer

| Pacote | Versão | Licença | Para quê |
|---|---|---|---|
| `twig/twig` | ^3.8 | BSD-3-Clause | template engine; item 8.2 veda PHP no HTML |
| `vlucas/phpdotenv` | ^5.6 | BSD-3-Clause | leitura do `.env`; item 8.3.1j |
| `phpmailer/phpmailer` | ^6.9 | LGPL-2.1 | envio SMTP da RF07 |
| `phpunit/phpunit` (dev) | ^10.5 | BSD-3-Clause | testes |

## Front-end

| Componente | Versão | Licença | Origem |
|---|---|---|---|
| Bootstrap | 5.3.3 | MIT | CDN jsDelivr; exigido pelo item 8.1.3 |

Bootstrap é carregado por CDN no desenvolvimento. **Antes da entrega**, baixar os arquivos para
`public/assets/` — o ambiente da banca pode não ter rede externa, e o item 8.8 pede execução
reproduzível.

## Extensões de PHP

`pdo_mysql`, `mbstring`, `intl`, `zip`, `opcache`, `openssl`, `curl`, `json`. Todas instaladas
pelo `docker/php/Dockerfile`.

## Ferramentas de desenvolvimento (não vão para produção)

| Componente | Para quê |
|---|---|
| Mailpit | captura os e-mails da RF07 em desenvolvimento, sem SMTP real |
| Composer | gerenciamento de dependências; item 8.1.1d |

## Compatibilidade de licença

Todas as licenças acima (MIT, BSD, LGPL, GPL para o servidor de banco, PHP License) são
compatíveis com o uso institucional previsto no Anexo V do edital. Nenhum componente sob licença
restritiva ou de uso comercial condicionado foi incorporado.
