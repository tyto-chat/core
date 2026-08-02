<?php

declare(strict_types=1);

namespace App\Enum\Notification;

enum NotificationType: string
{
    case Mention = 'mention';
    case BroadcastMention = 'broadcast_mention';
    case DmMessage = 'dm_message';
    case ChannelActivity = 'channel_activity';
    case ChannelAccess = 'channel_access';
    case ChannelModerator = 'channel_moderator';
    case GroupAdded = 'group_added';
    case GroupRemoved = 'group_removed';
    case GroupOwnershipTransferred = 'group_ownership_transferred';
    case ReportFiled = 'report_filed';
    case ReportEscalated = 'report_escalated';
    case ReportResolved = 'report_resolved';
    case ReportDismissed = 'report_dismissed';
    case AppealFiled = 'appeal_filed';
    case AppealUpheld = 'appeal_upheld';
    case AppealOverturned = 'appeal_overturned';
    case WebhookFailed = 'webhook_failed';
    case Warn = 'warn';
    case Timeout = 'timeout';
    case Ban = 'ban';
    case ServerBan = 'server_ban';
    case TimeoutLifted = 'timeout_lifted';
    case BanLifted = 'ban_lifted';
    case ServerBanLifted = 'server_ban_lifted';
    case DiskPressurePurge = 'disk_pressure_purge';
}
