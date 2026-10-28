<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

trait EnvelopeFixturesTrait
{
    private static function envelope(int $generation = 1, string $ciphertext = 'Y2lwaGVydGV4dA'): string
    {
        return self::encodeEnvelope(['g' => $generation, 'n' => 'bm9uY2U', 'c' => $ciphertext, 'm' => 'bWxz']);
    }

    /** @param array<mixed> $fields */
    private static function encodeEnvelope(array $fields): string
    {
        return 'e2ee:1:'.rtrim(strtr(base64_encode(json_encode($fields, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function envelopeOfBytes(int $bytes): string
    {
        $length = max(0, intdiv(($bytes - \strlen(self::envelope(ciphertext: ''))) * 3, 4) - 8);
        while (true) {
            $envelope = self::envelope(ciphertext: str_repeat('A', $length++));
            if (\strlen($envelope) === $bytes) {
                return $envelope;
            }
            if (\strlen($envelope) > $bytes) {
                throw new \LogicException('No valid envelope is exactly '.$bytes.' bytes.');
            }
        }
    }
}
