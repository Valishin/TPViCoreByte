<?php

namespace App\Exceptions;

use RuntimeException;

/** No se pudo hablar con la AEAT (sin conexión, tiempo agotado, TLS): se reintenta (Descripción servicios web 5.1). */
class AeatConnectionException extends RuntimeException {}
