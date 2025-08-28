*Machinjiri PHP Framework*

Machinjiri is a lightweight, flexible PHP framework for rapid web development. It features a modular architecture, simple routing, database abstraction, and built-in security. Designed for speed and scalability, Machinjiri empowers developers to build robust applications efficiently.

*Table of Contents*
- #introduction
- #features
- #installation
- #usage
- #documentation
- #contributing
- #license

*Introduction*
Machingjiri is designed to accelerate web development with a modular architecture, simple routing, and database abstraction.

*Features*
- Modular architecture
- Simple routing
- Database abstraction
- Built-in security features
- API production

*Installation*
composer require mlangenigroup/machinjiri

*Usage*
In project folder, create a public folder and inside create an entry point file e.g. index.php and write the following code and execute. The framework will initialize

// in public/index.php
require __DIR__ . '/../vendor/autoload.php';
use Mlangeni\Machinjiri\Core\Machinjiri;
return (new Machinjiri())->init();

*Documentation*
https://machingjiri.com/docs

*Contributing*
Contributions are welcome! Please see CONTRIBUTING.md for details.

*License*
Machinjiri is open-sourced software licensed under the https://opensource.org/licenses/MIT.