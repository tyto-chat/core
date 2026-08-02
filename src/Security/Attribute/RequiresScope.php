<?php

declare(strict_types=1);

namespace App\Security\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class RequiresScope
{
    public function __construct(
        public ?string $resource = null,
        public ?string $scope = null,
    ) {
        if ((null === $resource) === (null === $scope)) {
            throw new \LogicException('RequiresScope requires exactly one of $resource or $scope.');
        }
    }
}
