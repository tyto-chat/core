<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

final class MultipartDecoder implements DecoderInterface
{
    public const string FORMAT = 'multipart';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function decode(string $data, string $format, array $context = []): ?array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return null;
        }

        return array_map(static function (mixed $element) {
            if (!is_string($element)) {
                return $element;
            }
            // Values are JSON-encoded for the denormalizer, but plain-text fields must pass through rather than 500.
            try {
                return json_decode($element, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $element;
            }
        }, $request->request->all()) + $request->files->all();
    }

    public function supportsDecoding(string $format): bool
    {
        return self::FORMAT === $format;
    }
}
