<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Enum\Admin\AdminAuditAction;
use App\Security\SecurityContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * record() flushes the WHOLE unit of work — call only after the operation's own flush, never mid-mutation.
 *
 * @internal direct EM access — sanctioned audit-trail exemption
 */
final class AdminAuditLogger implements AdminAuditLoggerInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function record(
        AdminAuditAction $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $payload = null,
    ): void {
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            $actor = null;
        }

        $log = new AdminAuditLog($actor, $action, $targetType, $targetId, $payload);
        $this->em->persist($log);
        $this->em->flush();
    }
}
