<?php

namespace App\Exceptions;

use LogicException;

/** En producción, desde la fecha de obligación no se puede emitir sin VERI*FACTU activo (respuesta a la duda Q5). */
class VerifactuRequiredException extends LogicException {}
