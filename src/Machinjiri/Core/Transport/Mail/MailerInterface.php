<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailResponse;

interface MailerInterface
{
    /**
     * Send an email message.
     *
     * @param MailMessage $message
     * @return MailResponse
     *
     * @throws Exception\MailException
     */
    public function send(MailMessage $message): MailResponse;
}