<?php

declare(strict_types=1);

namespace App\Utils;

final class Topics
{
    public static function userNotifications(int $userId): string
    {
        return 'u/'.$userId.'/n';
    }

    public static function userEvents(int $userId): string
    {
        return 'u/'.$userId.'/e';
    }

    public static function userConversationActivity(int $userId): string
    {
        return 'u/'.$userId.'/d';
    }

    public static function userPresence(int $userId): string
    {
        return 'u/'.$userId.'/p';
    }

    public static function presenceTemplate(): string
    {
        return 'u/{userId}/p';
    }

    public static function community(int $communityId): string
    {
        return 'k/'.$communityId;
    }

    public static function communityActivity(int $communityId): string
    {
        return self::community($communityId).'/a';
    }

    public static function communityEmojis(int $communityId): string
    {
        return self::community($communityId).'/e';
    }

    public static function communityPresence(int $communityId): string
    {
        return self::community($communityId).'/p';
    }

    public static function channelActivity(int $channelId): string
    {
        return self::channel($channelId).'/a';
    }

    public static function channel(int $channelId): string
    {
        return 'c/'.$channelId;
    }

    public static function channelThreads(int $channelId): string
    {
        return self::channel($channelId).'/t';
    }

    public static function channelParticipants(int $channelId): string
    {
        return self::channel($channelId).'/p';
    }

    public static function conversation(int $conversationId): string
    {
        return 'm/'.$conversationId;
    }

    public static function conversationThreads(int $conversationId): string
    {
        return self::conversation($conversationId).'/t';
    }
}
