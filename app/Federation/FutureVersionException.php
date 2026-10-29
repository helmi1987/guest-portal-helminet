<?php

namespace App\Federation;

use RuntimeException;

/** A remote HLC version lies further in the future than portal.federation.max_future_ms allows. */
class FutureVersionException extends RuntimeException {}
