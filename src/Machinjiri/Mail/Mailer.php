<?php

namespace Mlangeni\Machinjiri\Core\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class Mailer {
    private PHPMailer $mailer;
    private array $config;

    public function __construct()
    {
        $this->config = $this->mailConfigurations();
        $this->mailer = new PHPMailer(true);
        $this->configureMailer();
    }
    
    private function mailConfigurations(): array
    {
        return $_ENV;
    }

    private function configureMailer(): void
    {
        // Server settings
        $this->mailer->isSMTP();
        $this->mailer->Host = $this->config['MAIL_HOST'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $this->config['MAIL_USERNAME'];
        $this->mailer->Password = $this->config['MAIL_PASSWORD'];
        $this->mailer->SMTPSecure = $this->config['MAIL_ENCRYPTION'];
        $this->mailer->Port = $this->config['MAIL_PORT'];

        // Encoding
        $this->mailer->CharSet = 'UTF-8';

        // From address
        $this->mailer->setFrom(
            $this->config['MAIL_FROM_ADDRESS'],
            $this->config['MAIL_FROM_NAME']
        );
    }

    public function send(
        array $to,
        string $subject,
        string $htmlContent,
        ?string $textContent = null
    ): string {
        try {
            // Recipients
            foreach ($to as $recipient) {
                $this->mailer->addAddress(
                    $recipient['email'],
                    $recipient['name'] ?? ''
                );
            }

            // Content
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlContent;
            
            if ($textContent !== null) {
                $this->mailer->AltBody = $textContent;
            }

            $this->mailer->send();
            
            // Return the Message-ID header if available
            return $this->mailer->getLastMessageID() ?: 'Unknown message ID';
            
        } catch (PHPMailerException $e) {
            throw new MachinjiriException('Mailer error: ' . $e->getMessage());
        }
    }

    // Note: Activity tracking is not available with PHPMailer
    public function getActivity(string $messageId, array $options = []): array
    {
        throw new \RuntimeException('Activity tracking is not supported when using PHPMailer');
    }
}