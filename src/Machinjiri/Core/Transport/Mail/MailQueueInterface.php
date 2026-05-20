<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

interface MailQueueInterface
{
    public function push(MailMessage $message, string $transport = 'default'): void;
    public function process(int $maxJobs = 5): void;
}