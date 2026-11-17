<?php

namespace App\Services\WebAudit;

use RuntimeException;

/** Serwer pokazuje automatom ekran weryfikacji (zabezpieczenie / limit zapytań) zamiast strony – treści nie da się ocenić. */
class SiteBlockedException extends RuntimeException
{
    public function __construct(public readonly string $protection)
    {
        parent::__construct('Strona pokazała ekran weryfikacji zamiast treści ('.$protection.').');
    }
}
