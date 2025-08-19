<?php

namespace Mlangeni\Machinjiri\Core\Mail;

use MailerSend\MailerSend;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Exceptions\MailerSendException;

class Mailer
{
    private MailerSend $mailerSend;
    private ?string $defaultDomainId;

    public function __construct(?string $defaultDomainId = null)
    {
        $this->mailerSend = new MailerSend(['api_key' => $this->mailConfigurations()['MAIL_MAILER_KEY']]);
        $this->defaultDomainId = $defaultDomainId;
    }
    
    private function mailConfigurations () : array {
      return $_ENV;
    }

    public function send(
        array $to,
        string $subject,
        string $htmlContent,
        ?string $textContent = null
    ): string {
        try {
            $recipients = array_map(function ($recipient) {
                return new Recipient($recipient['email'], $recipient['name'] ?? '');
            }, $to);

            $emailParams = (new EmailParams())
                ->setFrom($this->mailConfigurations()['MAIL_FROM_ADDRESS'])
                ->setFromName($this->mailConfigurations()['MAIL_FROM_NAME'])
                ->setRecipients($recipients)
                ->setSubject($subject)
                ->setHtml($htmlContent)
                ->setText($textContent);

            $response = $this->mailerSend->email->send($emailParams);
            
            return $response['X-Message-Id'] ?? 'Unknown message ID';
        } catch (MailerSendException $e) {
            throw new \RuntimeException('MailerSend error: ' . $e->getMessage());
        }
    }

    public function getActivity(string $messageId, array $options = []): array
    {
        if (!$this->defaultDomainId) {
            throw new \RuntimeException('Domain ID is required to retrieve activity');
        }

        try {
            $activityParams = array_merge(['message_id' => $messageId], $options);
            return $this->mailerSend->activity->get($this->defaultDomainId, $activityParams);
        } catch (MailerSendException $e) {
            throw new \RuntimeException('Failed to retrieve activity: ' . $e->getMessage());
        }
    }
}