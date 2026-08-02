<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Async\GenerateDataExportMessage;
use App\Entity\DataExportRequest;
use App\Entity\User;
use App\Enum\Settings\SupportedLocale;
use App\Exception\User\DataExportAlreadyPendingException;
use App\Exception\User\DataExportCooldownException;
use App\Exception\User\DataExportNotReadyException;
use App\Repository\DataExportRequestRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

class DataExportService extends AbstractDoctrineService implements DataExportServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly DataExportRequestRepository $repository,
        private readonly MessageBusInterface $messageBus,
        private readonly DataExportCollector $collector,
        private readonly Environment $twig,
        private readonly string $exportDir,
    ) {
    }

    #[\Override]
    public function request(): DataExportRequest
    {
        $user = $this->security->currentUser('You must be signed in to request a data export.');

        $active = $this->repository->findActiveForUser($user);
        if (null !== $active && DataExportRequest::STATUS_READY !== $active->getStatus()) {
            throw new DataExportAlreadyPendingException('A data export is already in progress.');
        }

        $mostRecent = $this->repository->findMostRecentForUser($user);
        if (null !== $mostRecent) {
            $cutoff = (new \DateTimeImmutable())->modify('-'.DataExportRequest::COOLDOWN_HOURS.' hours');
            if ($mostRecent->getRequestedAt() > $cutoff) {
                $retryAfter = $mostRecent->getRequestedAt()
                    ->modify('+'.DataExportRequest::COOLDOWN_HOURS.' hours')
                    ->getTimestamp() - time();

                throw new DataExportCooldownException('Cooldown not elapsed.', max(1, $retryAfter));
            }
        }

        if (null !== $active && DataExportRequest::STATUS_READY === $active->getStatus()) {
            $this->deleteFile($active);
            $active->setStatus(DataExportRequest::STATUS_EXPIRED);
        }

        $request = new DataExportRequest();
        $request->setUser($user);
        $this->persist($request);
        $this->flush();

        $this->messageBus->dispatch(new GenerateDataExportMessage($request->getId() ?? 0));

        $this->logger?->info('gdpr.export.requested', [
            'channel' => 'gdpr',
            'user_id' => $user->getId(),
            'request_id' => $request->getId(),
        ]);

        return $request;
    }

    #[\Override]
    public function findActiveForCurrentUser(): ?DataExportRequest
    {
        $user = $this->security->currentUser('You must be signed in to view data export status.');

        return $this->repository->findActiveForUser($user);
    }

    /**
     * @return array{path: string, fileName: string, mimeType: string}
     */
    #[\Override]
    public function prepareDownload(User $user, string $token): array
    {
        $request = $this->repository->findOneByDownloadToken($token);
        if (null === $request
            || !$request->isReady()
            || $request->getUser()->getId() !== $user->getId()
        ) {
            throw new DataExportNotReadyException('Export not found or no longer available.');
        }

        $expiresAt = $request->getExpiresAt();
        if (null !== $expiresAt && $expiresAt < new \DateTimeImmutable()) {
            throw new DataExportNotReadyException('Download window has elapsed.');
        }

        $filePath = $request->getFilePath();
        if (null === $filePath || !is_file($this->exportDir.'/'.$filePath)) {
            throw new DataExportNotReadyException('Export file is missing.');
        }

        return [
            'path' => $this->exportDir.'/'.$filePath,
            'fileName' => sprintf('tyto-export-%d.zip', $request->getId() ?? 0),
            'mimeType' => 'application/zip',
        ];
    }

    #[\Override]
    public function expireOld(): int
    {
        $now = new \DateTimeImmutable();
        $expired = $this->repository->findExpired($now);

        foreach ($expired as $request) {
            $this->deleteFile($request);
            $request->setStatus(DataExportRequest::STATUS_EXPIRED);
        }
        if (0 !== count($expired)) {
            $this->flush();
        }

        return count($expired);
    }

    #[\Override]
    public function generate(DataExportRequest $request): void
    {
        if (!is_dir($this->exportDir) && !mkdir($this->exportDir, 0o755, true) && !is_dir($this->exportDir)) {
            throw new \RuntimeException(sprintf('Cannot create export dir "%s".', $this->exportDir));
        }

        $request->setStatus(DataExportRequest::STATUS_PROCESSING);
        $this->flush();

        $relative = sprintf('%d-%s.zip', $request->getId() ?? 0, bin2hex(random_bytes(8)));
        $absolute = $this->exportDir.'/'.$relative;

        try {
            $zip = new \ZipArchive();
            $opened = $zip->open($absolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if (true !== $opened) {
                throw new \RuntimeException('Failed to open archive: '.$opened);
            }

            $user = $request->getUser();
            $ttlSeconds = DataExportCollector::attachmentTtlSeconds();

            $zip->addFromString('README.txt', $this->renderReadme($request));
            $zip->addFromString('profile.json', self::asJson($this->collector->profile($user)));
            $zip->addFromString('messages.json', self::asJson($this->collector->messages($user, $ttlSeconds)));
            $zip->addFromString('conversations.json', self::asJson($this->collector->conversations($user)));
            $zip->addFromString('attachments.json', self::asJson($this->collector->attachments($user, $ttlSeconds)));
            $zip->addFromString('reactions.json', self::asJson($this->collector->reactions($user)));
            $zip->addFromString('moderation.json', self::asJson($this->collector->moderationAsTarget($user)));
            $zip->addFromString('notifications.json', self::asJson($this->collector->notifications($user)));

            $zip->close();
        } catch (\Throwable $e) {
            @unlink($absolute);
            $request->setStatus(DataExportRequest::STATUS_FAILED);
            $request->setErrorMessage(substr($e->getMessage(), 0, 1024));
            $this->flush();

            $this->logger?->error('gdpr.export.failed', [
                'channel' => 'gdpr',
                'request_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $now = new \DateTimeImmutable();
        $request->setFilePath($relative);
        $size = filesize($absolute);
        $request->setFileSize(false === $size ? 0 : $size);
        $request->setDownloadToken(bin2hex(random_bytes(32)));
        $request->setReadyAt($now);
        $request->setExpiresAt($now->modify('+'.DataExportRequest::DOWNLOAD_TTL_DAYS.' days'));
        $request->setStatus(DataExportRequest::STATUS_READY);
        $this->flush();

        $this->logger?->info('gdpr.export.ready', [
            'channel' => 'gdpr',
            'request_id' => $request->getId(),
            'user_id' => $request->getUser()->getId(),
            'size' => $request->getFileSize(),
        ]);
    }

    private function deleteFile(DataExportRequest $request): void
    {
        $relative = $request->getFilePath();
        if (null === $relative) {
            return;
        }
        $absolute = $this->exportDir.'/'.$relative;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * @param array<int|string, mixed>|array{} $data
     */
    private static function asJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function renderReadme(DataExportRequest $request): string
    {
        $user = $request->getUser();

        return $this->twig->render('data_export/readme.txt.twig', [
            'locale' => self::resolveLocale($user->getLocale()),
            'generated' => $request->getRequestedAt()->format(\DateTimeInterface::ATOM),
            'email' => (string) $user->getEmail(),
            'userId' => (string) $user->getId(),
            'attachmentHours' => DataExportCollector::ATTACHMENT_TTL_HOURS,
            'attachmentMaxDownloads' => ExportDownloadLimiter::MAX_DOWNLOADS,
        ]);
    }

    private static function resolveLocale(string $userLocale): string
    {
        return in_array($userLocale, SupportedLocale::values(), true) ? $userLocale : 'en';
    }
}
