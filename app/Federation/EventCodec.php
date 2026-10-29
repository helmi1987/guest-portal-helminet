<?php

namespace App\Federation;

/**
 * Wire format and signature message of federation events (CONCEPT.md §10.2, §10.3). The payload
 * travels as the exact JSON string stored at the origin, so signatures stay verifiable after relays.
 */
final class EventCodec
{
    private const DOMAIN = 'portal-federation-event/1';

    /** @return array{origin: string, seq: int, table: string, record_id: string, version: string, payload: string, proto: int, sig: ?string} */
    public static function toWire(array|object $event): array
    {
        $event = (array) $event;
        $payload = $event['payload'];

        return [
            'origin' => (string) $event['origin'],
            'seq' => (int) $event['seq'],
            'table' => (string) $event['table'],
            'record_id' => (string) $event['record_id'],
            'version' => (string) $event['version'],
            'payload' => is_string($payload) ? $payload : Outbox::encode((array) $payload),
            'proto' => (int) $event['proto'],
            'sig' => $event['sig'] ?? null,
        ];
    }

    /** Signed message: every field of the event, the payload as its exact JSON string. */
    public static function message(string $origin, int $seq, string $table, string $recordId, string $version, int $proto, string $payload): string
    {
        return implode("\n", [self::DOMAIN, $origin, $seq, $table, $recordId, $version, $proto, $payload]);
    }

    /** Signature check of a wire event with the origin's public key. */
    public static function verify(array $event, ?string $publicKey): bool
    {
        if (! is_string($event['payload'] ?? null)) {
            return false;
        }

        return SigningKey::verify(self::message((string) $event['origin'], (int) $event['seq'], (string) $event['table'],
            (string) $event['record_id'], (string) $event['version'], (int) $event['proto'], $event['payload']),
            $event['sig'] ?? null, $publicKey);
    }
}
