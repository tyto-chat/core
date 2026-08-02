<?php

declare(strict_types=1);

namespace App\Service\Report;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Dto\Notification\CreateNotificationDto;
use App\Dto\Report\CreateReportDto;
use App\Dto\Report\UpdateReportDto;
use App\Entity\Community;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\Message\MessageKind;
use App\Enum\Moderation\ReportStatus;
use App\Enum\Notification\NotificationType;
use App\Exception\Message\MessageNotFoundException;
use App\Exception\Report\DuplicateReportException;
use App\Exception\Report\InvalidReportTargetException;
use App\Exception\Report\InvalidReportTransitionException;
use App\Exception\Report\ReportNotFoundException;
use App\Exception\User\UserNotFoundException;
use App\Repository\ReportRepository;
use App\Security\SecurityContext;
use App\Security\Voter\MessageVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Message\MessageServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\User\UserServiceInterface;

class ReportService extends AbstractDoctrineService implements ReportServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly ReportRepository $reportRepository,
        private readonly MessageServiceInterface $messageService,
        private readonly UserServiceInterface $userService,
        private readonly CommunityServiceInterface $communityService,
        private readonly NotificationServiceInterface $notificationService,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    #[\Override]
    public function create(CreateReportDto $dto): Report
    {
        $reporter = $this->security->currentUser('You must be signed in to report content.');

        $report = new Report();
        $report->setReporter($reporter);
        $report->setCategory($dto->category);
        $report->setComment(null !== $dto->comment && '' !== trim($dto->comment) ? trim($dto->comment) : null);

        if (null !== $dto->messageId) {
            $this->applyMessageTarget($report, $reporter, $dto->messageId);
        } else {
            $this->applyUserTarget($report, $reporter, (int) $dto->userId, $dto->communityIdentifier);
        }

        $this->persist($report);
        $this->flush();

        $this->notifyQueue($report);

        return $report;
    }

    #[\Override]
    public function get(int $id): Report
    {
        $report = $this->reportRepository->find($id);
        if (null === $report) {
            throw new ReportNotFoundException(sprintf('Report %d not found.', $id));
        }

        return $report;
    }

    #[\Override]
    public function listForCommunity(Community $community, ?ReportStatus $status = null, int $page = 1): array
    {
        $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to view reports.');

        return $this->reportRepository->findForCommunity($community, $status, max(1, $page));
    }

    #[\Override]
    public function listForAdmin(?ReportStatus $status = null, int $page = 1): array
    {
        $this->security->throwAccessDeniedUnlessAdmin('You do not have permission to view reports.');

        return $this->reportRepository->findForAdmin($status, max(1, $page));
    }

    #[\Override]
    public function update(Report $report, UpdateReportDto $dto): Report
    {
        $community = $report->getCommunity();
        if (null === $community) {
            $this->security->throwAccessDeniedUnlessAdmin('You do not have permission to update this report.');
        } else {
            $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to update this report.');
        }

        $current = $report->getStatus();
        if ($current->isFinal()) {
            throw new InvalidReportTransitionException('This report has already been closed.');
        }

        if (ReportStatus::Escalated === $dto->status) {
            if (ReportStatus::Open !== $current) {
                throw new InvalidReportTransitionException('Only open reports can be escalated.');
            }

            $report->setStatus(ReportStatus::Escalated);
            $this->persist($report);
            $this->flush();

            $this->notifyEscalated($report);

            return $report;
        }

        if (ReportStatus::Escalated === $current) {
            $this->security->throwAccessDeniedUnlessAdmin('Escalated reports can only be closed by a server administrator.');
        }

        $report->setStatus($dto->status);
        $report->setResolvedBy($this->security->currentUser());
        $report->setResolvedAt(new \DateTimeImmutable());
        $report->setResolutionNote(null !== $dto->resolutionNote && '' !== trim($dto->resolutionNote) ? trim($dto->resolutionNote) : null);

        $this->persist($report);
        $this->flush();

        $this->notifyReporterOfOutcome($report);

        return $report;
    }

    private function applyMessageTarget(Report $report, User $reporter, string $messageId): void
    {
        $message = $this->messageService->getById($messageId);

        if (!$this->security->isGranted(MessageVoter::VIEW, $message)) {
            throw new MessageNotFoundException(sprintf('Message %s not found.', $messageId));
        }

        $author = $message->getCreatedBy();
        if (MessageKind::System === $message->getKind() || null === $author) {
            throw new InvalidReportTargetException('System messages cannot be reported.');
        }

        if ($author->getId() === $reporter->getId()) {
            throw new InvalidReportTargetException('You cannot report your own content.');
        }

        if ($this->reportRepository->existsPendingByReporterAndMessage($reporter, $message)) {
            throw new DuplicateReportException('You already have a pending report for this message.');
        }

        $this->messageService->hydrateText($message);

        $report->setMessage($message);
        $report->setReportedUser($author);
        $report->setCommunity($message->getChannel()?->getCommunity());
        $report->setMessageTextSnapshot($message->getText());
    }

    private function applyUserTarget(Report $report, User $reporter, int $userId, ?string $communityIdentifier): void
    {
        $target = $this->userService->get($userId);

        if ($target->getId() === $reporter->getId()) {
            throw new InvalidReportTargetException('You cannot report yourself.');
        }

        if (!$this->security->isAdmin() && !$this->communityMembership->existsSharedCommunity($reporter, $target)) {
            throw new UserNotFoundException(sprintf('User %d not found.', $userId));
        }

        $community = null;
        if (null !== $communityIdentifier) {
            $community = $this->communityService->getByIdentifier($communityIdentifier);
            if (!$this->communityMembership->isMember($target, $community)) {
                throw new InvalidReportTargetException('The reported user is not a member of this community.');
            }
        }

        if ($this->reportRepository->existsPendingByReporterAndUser($reporter, $target)) {
            throw new DuplicateReportException('You already have a pending report for this user.');
        }

        $report->setReportedUser($target);
        $report->setCommunity($community);
    }

    private function notifyQueue(Report $report): void
    {
        $community = $report->getCommunity();
        $recipients = null !== $community
            ? $this->communityMembership->findModeratorUsers($community)
            : $this->userService->getAdmins();

        $this->notifyUsers($report, $recipients, NotificationType::ReportFiled);
    }

    private function notifyEscalated(Report $report): void
    {
        $this->notifyUsers($report, $this->userService->getAdmins(), NotificationType::ReportEscalated);
    }

    /** @param User[] $recipients */
    private function notifyUsers(Report $report, array $recipients, NotificationType $type): void
    {
        $reporter = $report->getReporter();
        $reporterName = $reporter?->getProfile()?->getName() ?? '';
        $message = $report->getMessage();
        $messageIri = null !== $message ? $this->iriConverter->getIriFromResource($message) : null;

        foreach ($recipients as $recipient) {
            if ($recipient->getId() === $reporter?->getId()) {
                continue;
            }

            $this->notificationService->new(new CreateNotificationDto(
                recipient: $recipient,
                community: $report->getCommunity(),
                communityIdentifier: $report->getCommunity()?->getIdentifier() ?? '',
                type: $type,
                authorName: $reporterName,
                messageIri: $messageIri,
            ));
        }
    }

    private function notifyReporterOfOutcome(Report $report): void
    {
        $reporter = $report->getReporter();
        if (null === $reporter) {
            return;
        }

        $this->notificationService->new(new CreateNotificationDto(
            recipient: $reporter,
            community: $report->getCommunity(),
            communityIdentifier: $report->getCommunity()?->getIdentifier() ?? '',
            type: ReportStatus::Resolved === $report->getStatus() ? NotificationType::ReportResolved : NotificationType::ReportDismissed,
            reason: $report->getResolutionNote(),
        ));
    }
}
