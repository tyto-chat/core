<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class JsonRequestBody
{
    /** @return array<string, mixed> */
    public static function decode(?Request $request): array
    {
        $body = $request?->getContent() ?? '';
        if ('' === $body) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: '.$e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new BadRequestHttpException('Expected JSON object body.');
        }

        return $decoded;
    }
}
