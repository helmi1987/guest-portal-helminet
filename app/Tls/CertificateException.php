<?php

namespace App\Tls;

use RuntimeException;

/** Certificate pull or validation error; the message is safe to show and log (no secrets). */
class CertificateException extends RuntimeException {}
