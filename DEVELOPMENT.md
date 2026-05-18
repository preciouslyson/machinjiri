# Machinjiri Framework - Development Guide

## Development Setup

### Prerequisites
- PHP 8.2 or higher
- Composer
- Git
- Docker & Docker Compose (optional)

### Installation

#### Using Docker (Recommended)
```bash
git clone https://github.com/machinjiri/framework.git
cd framework
docker-compose up -d
docker-compose exec php composer install
```

#### Local Development
```bash
git clone https://github.com/machinjiri/framework.git
cd framework
composer install
```

### Environment Configuration
```bash
cp .env.example .env
php artisan key:generate
```

## Testing

### Running Tests
```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run specific test file
vendor/bin/phpunit tests/Unit/RoutingTest.php

# Run with watch mode
composer test:watch
```

### Code Quality

```bash
# Run code sniffer (PSR-12)
composer lint

# Run PHPStan static analysis
composer analyse

# Run all checks
composer check-all

# Fix code style automatically
composer lint:fix
```

## Contributing

### Commit Message Format
Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**
- `feat` - A new feature
- `fix` - A bug fix
- `docs` - Documentation only
- `style` - Code style changes (semicolons, quotes, etc.)
- `refactor` - Code refactoring without feature changes
- `test` - Adding or updating tests
- `chore` - Build process, dependencies, etc.
- `perf` - Performance improvements

**Examples:**
```
feat(routing): add route group middleware support

fix(database): correct transaction rollback behavior

docs(readme): update installation instructions
```

### Branch Naming
```
feature/<feature-name>      # New features
bugfix/<issue-name>         # Bug fixes
docs/<doc-name>             # Documentation
hotfix/<issue-name>         # Critical fixes
refactor/<component-name>   # Code refactoring
```

### Pull Request Process

1. Create feature branch from `develop`:
   ```bash
   git checkout -b feature/my-feature develop
   ```

2. Make your changes and commit with conventional messages

3. Ensure tests pass:
   ```bash
   composer test
   composer check-all
   ```

4. Push to your fork:
   ```bash
   git push origin feature/my-feature
   ```

5. Open a Pull Request with a clear description

### Code Style Guidelines

- **PSR-12** compliance required
- 4-space indentation for PHP
- 2-space indentation for YAML/JSON
- Maximum line length: 120 characters
- Use type hints for all parameters and return types

Example:
```php
public function process(string $data, int $timeout = 30): array
{
    // Implementation
}
```

## Project Structure

```
machinjiri/
├── src/                     # Framework source code
│   ├── Components/          # UI components
│   ├── Machinjiri/         # Core framework
│   │   ├── Artisans/       # CLI commands
│   │   ├── Database/       # Query builder, migrations, schema
│   │   ├── Http/           # Request/Response handling
│   │   ├── Kernel/         # Core kernel modules
│   │   ├── Routing/        # Router and routing
│   │   ├── Security/       # Encryption, hashing, tokens
│   │   └── Views/          # View engine
├── tests/                   # Test suite
├── database/               # Migrations and seeds
├── resources/              # Resources (views, translations)
├── routes/                 # Route definitions
├── storage/               # Logs, cache, uploads
└── public/                # Web root
```

## Configuration

### Environment Variables
```env
APP_NAME=Machinjiri
APP_DEBUG=true
APP_ENV=local
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=machinjiri
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=file
QUEUE_DRIVER=database
SESSION_DRIVER=cookie
```

## Documentation

- **API Documentation**: See [README.md](README.md)
- **Architecture Guide**: See [ARCHITECTURE.md](docs/ARCHITECTURE.md) (if available)
- **Contributing**: See [CONTRIBUTING.md](CONTRIBUTING.md)

## Debugging

### Enable Debug Mode
```env
APP_DEBUG=true
```

### View Logs
```bash
tail -f storage/logs/app.log
```

### Database Debugging
```php
// Enable query logging
DB::listen(function($query) {
    echo $query->sql;
    echo $query->bindings;
});
```

## Deployment

### Production Build
```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan view:cache
php artisan route:cache
```

### Using Docker
```bash
docker build --target production -t machinjiri:latest .
docker run -p 8000:8000 machinjiri:latest
```

## Getting Help

- **Issues**: Report bugs on [GitHub Issues](https://github.com/machinjiri/framework/issues)
- **Discussions**: Join [GitHub Discussions](https://github.com/machinjiri/framework/discussions)
- **Documentation**: Check [README.md](README.md) and docs folder

## License

This project is licensed under the MIT License - see [LICENSE](LICENSE) file for details.
