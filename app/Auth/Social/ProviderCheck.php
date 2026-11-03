<?php

namespace App\Auth\Social;

use App\Support\OutboundHttp;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Check button of a social login provider (CONCEPT.md §9, decision 2026-11-03): sends the token request
 * of the login flow with a made-up authorization code. The provider checks the client credentials
 * before the code, so "invalid client" means the credentials are wrong and "invalid code" means they
 * were accepted. No guest login is needed and nothing is created at the provider. The secret never
 * reaches a log, the audit log or the flash; only provider, outcome and HTTP status are logged.
 */
final class ProviderCheck
{
    public const OK = 'ok';

    public const REJECTED = 'rejected';

    public const INCOMPLETE = 'incomplete';

    public const UNREACHABLE = 'unreachable';

    /** Made-up authorization code of the check request. */
    public const CODE = 'portal-check';

    public const TIMEOUT = 10;

    public const URLS = [
        SocialSettings::GOOGLE => 'https://oauth2.googleapis.com/token',
        SocialSettings::APPLE => 'https://appleid.apple.com/auth/token',
        SocialSettings::FACEBOOK => 'https://graph.facebook.com/oauth/access_token',
        SocialSettings::GITHUB => 'https://github.com/login/oauth/access_token',
    ];

    /**
     * @return array{status: string, message: string, http: int|null} message: lang key (portal.settings.social.check.*)
     */
    public static function run(string $provider): array
    {
        $result = self::evaluate($provider);
        Log::info('Social login provider checked', ['provider' => $provider, 'status' => $result['status'], 'http' => $result['http']]);

        return $result;
    }

    /** @return array{status: string, message: string, http: int|null} */
    private static function evaluate(string $provider): array
    {
        $config = SocialSettings::provider($provider);
        if ($config === []) {
            throw new InvalidArgumentException('Unknown social login provider.');
        }
        if (trim((string) $config['client_id']) === '') {
            return self::result(self::INCOMPLETE, 'missing_client_id');
        }
        if (SocialSettings::secretBroken($provider)) {
            return self::result(self::INCOMPLETE, 'secret_broken');
        }
        $secret = SocialSettings::secret($provider);
        if ($secret === null) {
            return self::result(self::INCOMPLETE, 'missing_secret');
        }
        if ($provider === SocialSettings::APPLE) {
            if ($config['team_id'] === '' || $config['key_id'] === '') {
                return self::result(self::INCOMPLETE, 'missing_apple_ids');
            }
            try {
                $secret = AppleClientSecret::make($config['client_id'], $config['team_id'], $config['key_id'], $secret);
            } catch (Throwable) {
                return self::result(self::INCOMPLETE, 'key_invalid');
            }
        }

        try {
            $response = self::request($provider, $config['client_id'], $secret);
        } catch (Throwable) {
            // Transport error or timeout; the exception text may contain the request, so it is not logged.
            return self::result(self::UNREACHABLE, 'unreachable');
        }

        return self::interpret($provider, $response);
    }

    private static function request(string $provider, string $clientId, #[\SensitiveParameter] string $secret): Response
    {
        $http = OutboundHttp::client(self::TIMEOUT)->acceptJson();
        $url = self::URLS[$provider];

        return match ($provider) {
            SocialSettings::GOOGLE => $http->asForm()->post($url, [
                'grant_type' => 'authorization_code', 'code' => self::CODE, 'client_id' => $clientId,
                'client_secret' => $secret, 'redirect_uri' => SocialSettings::redirectUris($provider)[0] ?? '',
            ]),
            SocialSettings::APPLE => $http->asForm()->post($url, [
                'grant_type' => 'authorization_code', 'code' => self::CODE, 'client_id' => $clientId, 'client_secret' => $secret,
            ]),
            SocialSettings::FACEBOOK => $http->get($url, [
                'client_id' => $clientId, 'client_secret' => $secret, 'grant_type' => 'client_credentials',
            ]),
            SocialSettings::GITHUB => $http->asForm()->post($url, [
                'client_id' => $clientId, 'client_secret' => $secret, 'code' => self::CODE,
            ]),
        };
    }

    /** @return array{status: string, message: string, http: int|null} */
    private static function interpret(string $provider, Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $error = is_string($json['error'] ?? null) ? $json['error'] : null;

        if ($status < 300 || $status >= 400) {
            $outcome = match ($provider) {
                SocialSettings::GOOGLE => match ($error) {
                    'invalid_client' => self::REJECTED,
                    'invalid_grant', 'redirect_uri_mismatch' => self::OK,
                    default => null,
                },
                SocialSettings::APPLE => match ($error) {
                    'invalid_client' => self::REJECTED,
                    'invalid_grant' => self::OK,
                    default => null,
                },
                SocialSettings::GITHUB => match ($error) {
                    'incorrect_client_credentials' => self::REJECTED,
                    'bad_verification_code' => self::OK,
                    default => null,
                },
                SocialSettings::FACEBOOK => match (true) {
                    $status === 200 && is_string($json['access_token'] ?? null) => self::OK,
                    $status === 400 && is_array($json['error'] ?? null)
                        && (($json['error']['code'] ?? null) === 101 || ($json['error']['type'] ?? null) === 'OAuthException') => self::REJECTED,
                    default => null,
                },
            };
            if ($outcome !== null) {
                return self::result($outcome, $outcome, $status);
            }
        }

        return self::result(self::UNREACHABLE, 'unexpected', $status);
    }

    /** @return array{status: string, message: string, http: int|null} */
    private static function result(string $status, string $message, ?int $http = null): array
    {
        return ['status' => $status, 'message' => 'portal.settings.social.check.'.$message, 'http' => $http];
    }
}
