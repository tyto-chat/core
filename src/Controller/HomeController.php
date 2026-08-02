<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\Get;
use App\State\ServerInfo\Provider\ServerInfoProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly ServerInfoProvider $serverInfoProvider,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $info = $this->serverInfoProvider->provide(new Get());
        $data = $this->normalizer->normalize($info, 'jsonld', ['groups' => ['server_info:read', 'community:read']]);

        return $this->render('home.html.twig', ['info' => $data]);
    }
}
