<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Dto\Appeal\UpdateAppealDto;
use App\Dto\Notification\CreateNotificationDto;
use App\Entity\Appeal;
use App\Entity\Community;
use App\Enum\Moderation\AppealStatus;
use App\Enum\Notification\NotificationType;
use App\Exception\Moderation\AppealNotAllowedException;
use App\Exception\Moderation\AppealNotFoundException;
use App\Exception\Moderation\DuplicateAppealException;
use App\Exception\Moderation\ModerationActionNotFoundException;
use App\Repository\AppealRepository;
use App\Repository\ModerationActionRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ModerationVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationServiceInterface;

class AppealService extends AbstractDoctrineService implements AppealServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly AppealRepository $appealRepository,
        private readonly ModerationActionRepository $moderationActionRepository,
        private readonly ModerationServiceInterface $moderationService,
        private readonly CommunityMembershipServiceInterface $membership,
        private readonly NotificationServiceInterface $notificationService,
    ) {
    }

    #[\Override]
    public function create(int $actionId, string $reason): Appeal
    {
        $actor = $this->security->currentUser('You must be signed in to appeal.');

        $action = $this->moderationActionRepository->find($actionId);
        if (null === $action) {
            throw new ModerationActionNotFoundException(sprintf('Moderation action %d not found.', $actionId));
        }

        if ($action->getTargetUser() !== $actor) {
            throw new AppealNotFoundException(sprintf('Moderation action %d not found.', $actionId));
        }

        if (!$action->isActive()) {
            throw new AppealNotAllowedException('This action is no longer active and cannot be appealed.');
        }

        if (null !== $this->appealRepository->findOneByAction($action)) {
            throw new DuplicateAppealException('You have already appealed this action.');
        }

        $appeal = new Appeal();
        $appeal->setModerationAction($action);
        $appeal->setAppellant($actor);
        $appeal->setReason(trim($reason));

        $this->persist($appeal);
        $this->flush();

        $this->notifyModerators($appeal);

        return $appeal;
    }

    #[\Override]
    public function get(int $id): Appeal
    {
        $appeal = $this->appealRepository->find($id);
        if (null === $appeal) {
            throw new AppealNotFoundException(sprintf('Appeal %d not found.', $id));
        }

        return $appeal;
    }

    #[\Override]
    public function listForCommunity(Community $community, ?AppealStatus $status = null, int $page = 1): array
    {
        $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to view appeals.');

        return $this->appealRepository->findForCommunity($community, $status, max(1, $page));
    }

    #[\Override]
    public function resolve(Appeal $appeal, UpdateAppealDto $dto): Appeal
    {
        $action = $appeal->getModerationAction();
        $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::VIEW, $action, 'You do not have permission to resolve this appeal.');

        if ($appeal->getStatus()->isResolved()) {
            throw new AppealNotAllowedException('This appeal has already been decided.');
        }

        if (AppealStatus::Overturned === $dto->status) {
            $this->moderationService->lift($action);
        }

        $appeal->setStatus($dto->status);
        $appeal->setResolvedBy($this->security->currentUser());
        $appeal->setResolvedAt(new \DateTimeImmutable());
        $appeal->setResolutionNote(null !== $dto->resolutionNote && '' !== trim($dto->resolutionNote) ? trim($dto->resolutionNote) : null);

        $this->persist($appeal);
        $this->flush();

        $this->notifyAppellant($appeal);

        return $appeal;
    }

    private function notifyModerators(Appeal $appeal): void
    {
        $community = $appeal->getModerationAction()->getCommunity();
        $appellantName = $appeal->getAppellant()->getProfile()?->getName() ?? '';

        foreach ($this->membership->findModeratorUsers($community) as $recipient) {
            $this->notificationService->new(new CreateNotificationDto(
                recipient: $recipient,
                community: $community,
                communityIdentifier: $community->getIdentifier() ?? '',
                type: NotificationType::AppealFiled,
                authorName: $appellantName,
            ));
        }
    }

    private function notifyAppellant(Appeal $appeal): void
    {
        $community = $appeal->getModerationAction()->getCommunity();

        $this->notificationService->new(new CreateNotificationDto(
            recipient: $appeal->getAppellant(),
            community: $community,
            communityIdentifier: $community->getIdentifier() ?? '',
            type: AppealStatus::Overturned === $appeal->getStatus() ? NotificationType::AppealOverturned : NotificationType::AppealUpheld,
            reason: $appeal->getResolutionNote(),
        ));
    }
}
