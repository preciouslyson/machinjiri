<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\Contracts;

use Mlangeni\Machinjiri\Core\Transport\SMS\Message;
use Mlangeni\Machinjiri\Core\Transport\SMS\Response;

interface TransportInterface
{
    public function send(Message $message): Response;

    public function getName(): string;
}