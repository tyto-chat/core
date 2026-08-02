<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\AccessDeniedException;
use App\Exception\DomainExceptionInterface;
use App\Exception\HttpHeadersAwareExceptionInterface;
use App\Exception\TranslationParamsInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
class ExceptionListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof DomainExceptionInterface) {
            return;
        }

        // Anonymous denials must reach the firewall for the 401 challenge.
        if ($exception instanceof AccessDeniedException && null === $this->security->getUser()) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => $this->translator->trans(
                $exception->translationKey(),
                $exception instanceof TranslationParamsInterface ? $exception->translationParams() : [],
                'messages',
            )],
            $exception->statusCode(),
            $exception instanceof HttpHeadersAwareExceptionInterface ? $exception->httpHeaders() : [],
        ));
    }
}
