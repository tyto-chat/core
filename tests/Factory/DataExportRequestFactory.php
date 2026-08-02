<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\DataExportRequest;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<DataExportRequest>
 */
final class DataExportRequestFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return DataExportRequest::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'user' => UserFactory::new(),
            'status' => DataExportRequest::STATUS_QUEUED,
        ];
    }
}
