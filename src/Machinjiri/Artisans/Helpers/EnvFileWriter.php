<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;

class EnvFileWriter
{
    public function create(string $filePath = '.env'): bool
    {
        $envContent = <<<EOT
# App Settings
APP_NAME="MyApp"
APP_ENV=local
APP_KEY=base64:YourGeneratedAppKeyHere
APP_DEBUG=true
APP_URL=http://localhost

# Database Configuration
DB_CONNECTION=sqlite
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=secret

# Cache & Session
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=debug

# Third-Party Services (Example)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=

# Custom Configuration
CUSTOM_API_URL=https://api.example.com
CUSTOM_FEATURE_FLAG=true

EOT;

        return file_put_contents($filePath, $envContent) !== false;
    }
}