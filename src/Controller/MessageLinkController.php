<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Message\MessageEmbedPresenterInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MessageLinkController extends AbstractController
{
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const int MAX_AGE_SECONDS = 60;

    public function __construct(
        private readonly MessageEmbedPresenterInterface $presenter,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[Route('/m/{uuid}', name: 'message_link', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function link(string $uuid, Request $request): Response
    {
        $messageUrl = $this->messageUrl($request, $uuid);
        $clientBaseUrl = $this->settings->get(Settings::clientBaseUrl());

        return $this->cacheable($this->render('message/link.html.twig', [
            'embed' => $this->presenter->present($uuid),
            'uuid' => strtolower($uuid),
            'messageUrl' => $messageUrl,
            'desktopLink' => 'tyto://open?url='.rawurlencode($messageUrl),
            'webLink' => '' === $clientBaseUrl ? null : $clientBaseUrl.'/m/'.strtolower($uuid),
            'serverName' => $this->settings->get(Settings::serverName()),
        ]));
    }

    #[Route('/embed/m/{uuid}', name: 'message_embed', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function embed(string $uuid, Request $request): Response
    {
        return $this->cacheable($this->render('message/embed.html.twig', [
            'embed' => $this->presenter->present($uuid),
            'uuid' => strtolower($uuid),
            'messageUrl' => $this->messageUrl($request, $uuid),
        ]));
    }

    private function messageUrl(Request $request, string $uuid): string
    {
        return $request->getSchemeAndHttpHost().'/m/'.strtolower($uuid);
    }

    private function cacheable(Response $response): Response
    {
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
