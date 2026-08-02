<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Community;
use App\Entity\ModeratorNote;
use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\Moderation\ModeratorNoteNotFoundException;
use App\Repository\ModeratorNoteRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;

class ModeratorNoteService extends AbstractDoctrineService implements ModeratorNoteServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly ModeratorNoteRepository $moderatorNoteRepository,
    ) {
    }

    #[\Override]
    public function getNote(int $id, Community $community): ModeratorNote
    {
        $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to manage moderator notes.');

        $note = $this->moderatorNoteRepository->findOneBy(['id' => $id, 'community' => $community]);
        if (null === $note) {
            throw new ModeratorNoteNotFoundException(sprintf('Moderator note %d not found.', $id));
        }

        return $note;
    }

    #[\Override]
    public function new(Community $community, User $target, string $content): ModeratorNote
    {
        $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to manage moderator notes.');
        $actor = $this->security->currentUser();

        $note = new ModeratorNote();
        $note->setCommunity($community);
        $note->setTargetUser($target);
        $note->setAuthor($actor);
        $note->setContent($content);

        return $this->save($note);
    }

    #[\Override]
    public function update(ModeratorNote $note, string $content): ModeratorNote
    {
        $actor = $this->security->currentUser();
        $this->requireUpdatePermission($note, $actor);

        $note->setContent($content);

        return $this->save($note);
    }

    #[\Override]
    public function delete(ModeratorNote $note): void
    {
        $actor = $this->security->currentUser();
        $this->requireDeletePermission($note, $actor);

        $this->removeAndFlush($note);
    }

    #[\Override]
    public function getNotes(Community $community, User $target): array
    {
        $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to manage moderator notes.');

        return $this->moderatorNoteRepository->findByUserAndCommunity($community, $target);
    }

    private function requireUpdatePermission(ModeratorNote $note, User $actor): void
    {
        $this->requireManageNotePermission($note, $actor, 'You do not have permission to edit this note.');
    }

    private function requireDeletePermission(ModeratorNote $note, User $actor): void
    {
        $this->requireManageNotePermission($note, $actor, 'You do not have permission to delete this note.');
    }

    private function requireManageNotePermission(ModeratorNote $note, User $actor, string $message): void
    {
        if ($this->security->isAdmin()) {
            return;
        }

        if ($this->security->isCommunityAdmin($note->getCommunity())) {
            return;
        }

        if ($actor->getId() === $note->getAuthor()->getId()) {
            return;
        }

        throw new AccessDeniedException($message);
    }
}
