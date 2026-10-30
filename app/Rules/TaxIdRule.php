<?php

namespace App\Rules;

use App\Enums\TaxIdType;
use App\Support\TaxId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** NIF, NIE, CIF (con control), NIF-IVA de la UE (formato) u "otro" (art. 6.1.d RD 1619/2012). */
class TaxIdRule implements ValidationRule
{
    public function __construct(private ?TaxIdType $type = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($error = TaxId::error(is_string($value) ? $value : null, $this->type)) {
            $fail($error);
        }
    }
}
