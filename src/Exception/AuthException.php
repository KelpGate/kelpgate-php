<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The API refused the credentials (401), or the tenant may not use it (403). */
class AuthException extends KelpgateException
{
}
