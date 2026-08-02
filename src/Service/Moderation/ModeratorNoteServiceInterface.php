<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Community;
use App\Entity\ModeratorNote;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

interface ModeratorNoteServiceInterface
{
    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws AccessDeniedException
     */
    public function getNote(int $id, Community $community): ModeratorNote;

    /**
     * @throws AccessDeniedException
     */
    public function new(Community $community, User $target, string $content): ModeratorNote;

    /**
     * @throws AccessDeniedException
     */
    public function update(ModeratorNote $note, string $content): ModeratorNote;

    /**
     * @throws AccessDeniedException
     */
    public function delete(ModeratorNote $note): void;

    /**
     * @return ModeratorNote[]
     *
     * @throws AccessDeniedException
     */
    public function getNotes(Community $community, User $target): array;
}
