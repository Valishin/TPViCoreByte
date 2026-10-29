<?php

namespace App\Support;

use Attribute;

/** Marca clases o métodos que deben funcionar también en modo solo consulta. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class AllowedInReadOnly {}
