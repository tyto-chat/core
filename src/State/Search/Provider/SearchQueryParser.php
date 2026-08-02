<?php

declare(strict_types=1);

namespace App\State\Search\Provider;

use App\Dto\Search\SearchOptions;
use Symfony\Component\HttpFoundation\Request;

final readonly class SearchQueryParser
{
    private const int MAX_LIMIT = 50;
    private const int MIN_QUERY_LENGTH = 2;

    public function isTooShort(string $q): bool
    {
        return mb_strlen(trim($q)) < self::MIN_QUERY_LENGTH;
    }

    public function options(?Request $request): SearchOptions
    {
        if (null === $request) {
            return new SearchOptions();
        }

        $authorId = $request->query->get('authorId');
        $before = $request->query->get('before');
        $after = $request->query->get('after');

        return new SearchOptions(
            authorId: is_numeric($authorId) ? (int) $authorId : null,
            createdBefore: is_numeric($before) ? (int) $before : null,
            createdAfter: is_numeric($after) ? (int) $after : null,
            limit: max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', '25'))),
            offset: max(0, (int) $request->query->get('offset', '0')),
        );
    }
}
