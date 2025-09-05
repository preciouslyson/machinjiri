<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;

use Composer\Script\Event;

class Installer
{
    public static function postInstall(Event $event)
    {
        self::setupFramework($event);
    }

    public static function postUpdate(Event $event)
    {
        self::setupFramework($event);
    }

    private static function setupFramework(Event $event)
    {
        $io = $event->getIO();
        
        // Create required directories
        $dirs = ['storage/cache', 'storage/logs', 'storage/sessions', 'public/assets'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Generate encryption key if not exists
        if (file_exists('.env')) {
            $env = file_get_contents('.env');
            if (strpos($env, 'APP_KEY=') === false) {
                $key = base64_encode(random_bytes(32));
                file_put_contents('.env', "\nAPP_KEY=$key\n", FILE_APPEND);
                $io->write('<info>Generated application key</info>');
            }
        }
        
        $io->write('<info>Machinjiri framework installed successfully!</info>');
    }
}