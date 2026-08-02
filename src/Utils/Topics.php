<?php

declare(strict_types=1);

namespace App\Utils;

/** Topics are unversioned /api/... — IriConverter output used in topic position must pass through MercurePublisher::topic(). */
final class Topics
{
    public static function community(?string $communityIdentifier): string
    {
        return '/api/communities/'.$communityIdentifier;
    }

    public static function communityActivity(?string $communityIdentifier): string
    {
        return self::community($communityIdentifier).'/activity';
    }

    public static function communityEmojis(?string $communityIdentifier): string
    {
        return self::community($communityIdentifier).'/emojis';
    }

    public static function channel(?string $communityIdentifier, ?string $channelIdentifier): string
    {
        return self::community($communityIdentifier).'/channels/'.$channelIdentifier;
    }

    public static function channelParticipants(?string $communityIdentifier, ?string $channelIdentifier): string
    {
        return self::channel($communityIdentifier, $channelIdentifier).'/participants';
    }

    public static function channelThreads(?string $communityIdentifier, ?string $channelIdentifier): string
    {
        return self::channel($communityIdentifier, $channelIdentifier).'/messages/{messageId}/thread';
    }

    public static function conversationThreads(string $conversationIri): string
    {
        return $conversationIri.'/messages/{messageId}/thread';
    }

    public static function userNotifications(?int $userId): string
    {
        return '/api/users/'.$userId.'/notifications';
    }

    public static function userEvents(?int $userId): string
    {
        return '/api/users/'.$userId.'/events';
    }

    public static function userConversationActivity(?int $userId): string
    {
        return '/api/users/'.$userId.'/conversation-activity';
    }

    public static function userPresence(?int $userId): string
    {
        return '/api/users/'.$userId.'/presence';
    }

    public static function presenceTemplate(): string
    {
        return '/api/users/{userId}/presence';
    }
}
