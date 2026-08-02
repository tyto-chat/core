<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Enum\User\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, mixed>
 */
class AdminPanelVoter extends Voter
{
    public const string VIEW = 'ADMIN_PANEL_VIEW';
    public const string USER_MANAGE = 'ADMIN_USER_MANAGE';
    public const string COMMUNITY_MANAGE = 'ADMIN_COMMUNITY_MANAGE';
    public const string SERVER_CONFIG = 'ADMIN_SERVER_CONFIG';
    public const string WEBHOOKS = 'ADMIN_WEBHOOKS';

    private const array ATTRIBUTES = [
        self::VIEW,
        self::USER_MANAGE,
        self::COMMUNITY_MANAGE,
        self::SERVER_CONFIG,
        self::WEBHOOKS,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array(UserRole::Admin->value, $token->getRoleNames(), true);
    }
}
