<?php

declare(strict_types=1);

namespace ProLink\Service;

use RuntimeException;

/** A API oficial não respondeu, ou respondeu com erro. Falha de infraestrutura, não do usuário. */
final class ApiIndisponivelException extends RuntimeException
{
}
