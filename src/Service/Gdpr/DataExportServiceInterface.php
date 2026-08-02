<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\DataExportRequest;
use App\Entity\User;

interface DataExportServiceInterface
{
    /**
     * @throws \App\Exception\User\DataExportAlreadyPendingException 409
     * @throws \App\Exception\User\DataExportCooldownException       429
     */
    public function request(): DataExportRequest;

    public function findActiveForCurrentUser(): ?DataExportRequest;

    /**
     * @return array{path: string, fileName: string, mimeType: string}
     *
     * @throws \App\Exception\User\DataExportNotReadyException 404
     */
    public function prepareDownload(User $user, string $token): array;

    public function expireOld(): int;

    public function generate(DataExportRequest $request): void;
}
