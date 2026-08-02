<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Dto\Search\SearchOptions;
use App\Dto\Search\SearchResultDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;

interface SearchFacadeInterface
{
    public function searchChannel(Channel $channel, string $query, ?SearchOptions $options = null): SearchResultDto;

    public function searchConversation(Conversation $conversation, string $query, ?SearchOptions $options = null): SearchResultDto;

    public function searchCommunity(Community $community, string $query, ?SearchOptions $options = null): SearchResultDto;
}
