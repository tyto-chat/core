<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\DispatchWebhookMessage;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookSigner;
use App\Service\Webhook\WebhookUrlGuard;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final class DispatchWebhookHandler
{
    public function __construct(
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $webhookHttpClient,
        private readonly WebhookSigner $signer,
        private readonly WebhookUrlGuard $urlGuard,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    public function __invoke(DispatchWebhookMessage $message): void
    {
        $delivery = $this->deliveryRepository->find($message->deliveryId);
        if (null === $delivery) {
            return;
        }

        $webhook = $delivery->getWebhook();
        $allowInternal = $this->settings->get(Settings::webhookAllowInternalUrls());

        $host = parse_url($webhook->getUrl(), \PHP_URL_HOST);
        $scheme = parse_url($webhook->getUrl(), \PHP_URL_SCHEME);

        if (!\is_string($host) || !\in_array($scheme, ['http', 'https'], true)) {
            $this->markTerminalFailure($delivery, 'URL blocked by SSRF guard');

            return;
        }

        $validatedIps = $this->urlGuard->resolvedIps($host, $allowInternal);
        if (!$allowInternal && [] === $validatedIps) {
            $this->markTerminalFailure($delivery, 'URL blocked by SSRF guard');

            return;
        }

        $body = json_encode($delivery->getRequestPayload(), \JSON_THROW_ON_ERROR);
        $delivery->incrementAttempts();
        $start = microtime(true);

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Tyto-Event' => $delivery->getTriggerKey(),
                'X-Tyto-Delivery' => (string) $delivery->getId(),
                'X-Tyto-Signature' => $this->signer->sign($webhook->getSecret(), $body, time()),
            ],
            'body' => $body,
            'timeout' => 10,
            'max_duration' => 15,
            // max_redirects 0: a 3xx to an internal host would bypass the SSRF guard.
            'max_redirects' => 0,
        ];
        // Pin to a validated IP — letting HttpClient re-resolve at connect time allows DNS-rebinding past the SSRF check.
        if ([] !== $validatedIps) {
            $options['resolve'] = [$host => $validatedIps[0]];
        }

        try {
            $response = $this->webhookHttpClient->request('POST', $webhook->getUrl(), $options);

            $code = $response->getStatusCode();
            $respBody = mb_substr($response->getContent(false), 0, 2048);

            $delivery->setDurationMs((int) ((microtime(true) - $start) * 1000));
            $delivery->setHttpCode($code);
            $delivery->setResponseBody($respBody);

            if ($code >= 200 && $code < 300) {
                $delivery->setStatus(WebhookDeliveryStatus::Success);
                $this->em->flush();

                return;
            }

            $delivery->setError('HTTP '.$code);
            $this->em->flush();

            throw new \RuntimeException('Webhook delivery failed: HTTP '.$code);
        } catch (TransportExceptionInterface $e) {
            $delivery->setDurationMs((int) ((microtime(true) - $start) * 1000));
            $delivery->setError($e->getMessage());
            $this->em->flush();

            throw new \RuntimeException('Webhook transport error: '.$e->getMessage(), 0, $e);
        }
    }

    private function markTerminalFailure(WebhookDelivery $delivery, string $error): void
    {
        $delivery->setStatus(WebhookDeliveryStatus::Failed);
        $delivery->setError($error);
        $delivery->incrementAttempts();
        $this->em->flush();
    }
}
