<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\State\Notification\Processor\MarkAllConversationsReadProcessor;
use App\State\Notification\Processor\MarkAllDmNotificationsReadProcessor;
use App\State\Notification\Processor\MarkEverythingReadProcessor;

#[ApiResource(
    description: 'Caller-global bulk mark-read actions spanning channels, conversations, and notifications.',
    operations: [
        new Post(
            uriTemplate: '/me/mark-all-read',
            security: "is_granted('ROLE_USER')",
            input: false,
            output: false,
            processor: MarkEverythingReadProcessor::class,
            extraProperties: ['scopeResource' => 'notifications'],
            openapi: new Model\Operation(
                summary: 'Mark everything as read',
                description: 'Marks every channel, every conversation, and every notification of the caller '
                    .'as read in one call. Takes no body and returns no content.',
            ),
        ),
        new Post(
            uriTemplate: '/me/conversations/mark-all-read',
            security: "is_granted('ROLE_USER')",
            input: false,
            output: false,
            processor: MarkAllConversationsReadProcessor::class,
            extraProperties: ['scopeResource' => 'notifications'],
            openapi: new Model\Operation(
                summary: 'Mark all conversations as read',
                description: 'Marks every direct-message conversation of the caller as read and clears their '
                    .'DM notifications. Channel unread state is untouched. Takes no body and returns no '
                    .'content.',
            ),
        ),
        new Post(
            uriTemplate: '/me/notifications/mark-all-read',
            security: "is_granted('ROLE_USER')",
            input: false,
            output: false,
            processor: MarkAllDmNotificationsReadProcessor::class,
            extraProperties: ['scopeResource' => 'notifications'],
            openapi: new Model\Operation(
                summary: 'Mark all DM notifications as read',
                description: 'Marks the caller\'s unread community-less notifications (chiefly `dm_message`) '
                    .'as read without touching the conversations\' own read state. Takes no body and returns '
                    .'no content.',
            ),
        ),
    ],
)]
class ReadReceipt
{
}
