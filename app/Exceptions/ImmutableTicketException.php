<?php

namespace App\Exceptions;

use LogicException;

/** Se intenta modificar o borrar un ticket emitido. Para corregirlo, emite un rectificativo. */
class ImmutableTicketException extends LogicException {}
