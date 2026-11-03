<?php

namespace App\Auth\Social;

use RuntimeException;

/**
 * A social login failed (provider error, token or profile request, ID token check). The message is
 * logged and never shown to the guest; it must not contain tokens, codes or personal data.
 */
class SocialLoginException extends RuntimeException {}
