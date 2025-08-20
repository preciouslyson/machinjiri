<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Process;

interface TaskInterface
{
    public function execute(): void;
    public function getMaxAttempts(): int;
}