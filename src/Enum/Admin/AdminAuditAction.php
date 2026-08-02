<?php

declare(strict_types=1);

namespace App\Enum\Admin;

enum AdminAuditAction: string
{
    case UserCreate = 'user.create';
    case UserPromote = 'user.promote';
    case UserDemote = 'user.demote';
    case UserForceDelete = 'user.force_delete';
    case UserEditProfile = 'user.edit_profile';
    case UserRevokeApiKey = 'user.revoke_api_key';
    case UserIssueApiKey = 'user.issue_api_key';
    case UserTwoFactorDisable = 'user.two_factor_disable';

    case CommunityDelete = 'community.delete';
    case CommunityTransfer = 'community.transfer';
    case CommunityCleanupAttachments = 'community.cleanup_attachments';

    case ServerConfigUpdate = 'server_config.update';
    case ServerConfigTestEmail = 'server_config.test_email';
    case BootstrapComplete = 'bootstrap.complete';

    case WebhookCreate = 'webhook.create';
    case WebhookUpdate = 'webhook.update';
    case WebhookDelete = 'webhook.delete';
    case WebhookRegenerateSecret = 'webhook.regenerate_secret';
    case WebhookReplay = 'webhook.replay';
    case WebhookTest = 'webhook.test';

    case DiskPressurePurge = 'retention.disk_pressure_purge';
    case DiskPressurePurgeExhausted = 'retention.disk_pressure_exhausted';
}
