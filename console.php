#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use Mlangeni\Machinjiri\Core\Console\Kernel;

$kernel = new Kernel();
$kernel->handle();