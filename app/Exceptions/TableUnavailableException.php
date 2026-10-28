<?php

namespace App\Exceptions;

use LogicException;

/** La mesa ya está ocupada o fuera de servicio (otro camarero se ha adelantado). Se responde 409. */
class TableUnavailableException extends LogicException {}
