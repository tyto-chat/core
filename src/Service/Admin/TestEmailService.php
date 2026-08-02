<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\Admin\AdminAuditAction;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class TestEmailService implements TestEmailServiceInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    #[\Override]
    public function send(string $to): ?string
    {
        $email = new Email()
            ->to($to)
            ->subject('Tyto — test email')
            ->text("This is a test message from your Tyto admin panel.\nIf you can read this, SMTP is working.\n");

        $error = null;
        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->auditLogger->record(AdminAuditAction::ServerConfigTestEmail, 'server_config', null, ['to' => $to, 'ok' => null === $error, 'error' => $error]);

        return $error;
    }
}
