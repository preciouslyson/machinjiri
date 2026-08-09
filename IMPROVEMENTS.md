# Machinjiri Framework Improvements

This document summarizes the major improvements currently available in Machinjiri 2.2.0.

## Architecture and Developer Experience

- Added full PHP 8.3 compatibility across the core framework.
- Added an interactive installer workflow for the global Machinjiri installer.
- Added a modular service-provider system for registering and bootstrapping framework services.
- Improved dependency injection through the application container and service bindings.
- Added facades for convenient access to commonly used framework services.
- Added environment-aware configuration loading with `.env` support.
- Added Artisan generators and terminal tooling powered by Symfony Console.
- Added Symfony Filesystem and Process integration for CLI and file operations.
- Added FTP filesystem adapter support for remote storage operations.
- Added Predis Redis integration for distributed cache and queue workflows.
- Added Angular SPA and frontend integration helpers for CSRF/meta tags and asset bridging.

## Routing and HTTP

- Expanded routing support for GET, POST, PUT, DELETE, PATCH, and AJAX-only routes.
- Added route groups with shared prefixes, middleware, and CORS configuration.
- Added named routes and URL generation with route parameters.
- Added route parameter matching, validation, and route caching for better performance.
- Added middleware dispatching, rate limiting, and CORS preflight handling.
- Added dedicated HTTP request and response objects, including JSON, redirect, download, and streaming responses.
- Added built-in server management utilities and HTTP client support for external API calls.

## Views and UI Components

- Added template inheritance with layouts and sections.
- Added partial includes, shared view data, loop directives, and asset management.
- Added reusable UI components with attribute handling and dynamic CSS class building.
- Added component support for common elements such as alerts, buttons, cards, forms, inputs, modals, navigation, and progress bars.
- Added a component factory for programmatic UI element creation.

## Database and Persistence

- Added support for MySQL, PostgreSQL, and SQLite through a common database layer.
- Added fluent query builders and database grammars for expressive queries.
- Added migrations and schema builders for programmatic schema management.
- Added seeders and factories to simplify test and development data setup.
- Added support for multiple database connections, connection management, and transactions.
- Added database caching and queue persistence capabilities.
- Added Redis-backed queue driver support with delayed job scheduling, reservation, and retry handling.

## Authentication and Security

- Added session and cookie management with configurable security options.
- Added OAuth integrations for third-party authentication providers.
- Added password hashing with bcrypt and Argon2 support.
- Added CSRF protection for form submissions.
- Added AES encryption and JWT token support.
- Added parameterized database queries to reduce SQL injection risk.
- Added LDAP integration and authentication middleware support.

## Forms and Validation

- Added a reusable form request and validation layer.
- Added a fluent rule builder for composing validation rules.
- Added password-specific validation rules.
- Added structured validation errors through the form error bag.
- Added file-upload handling and support for custom error messages and localization.

## Queues and Events

- Added background job dispatching and worker processing.
- Added database-backed queues for durable job storage.
- Added configurable retry behavior for failed jobs.
- Added Redis-backed queue support for high-performance job processing.
- Added queue-related Artisan commands and job generators.
- Added event listeners for application and queue lifecycle events.

## Logging, Errors, and Diagnostics

- Added multi-channel logging for database, file, and event-based output.
- Added structured log levels from debug through critical.
- Added environment-aware logging behavior for development and production.
- Added centralized exception and error handling.
- Added debugging and data-dumping utilities.

## Integrations and Utilities

- Added a cURL-based HTTP client for external API requests.
- Added mail transport integration through PHPMailer.
- Added filesystem abstractions and adapters for storage operations.
- Added Vite and Angular integration points for frontend workflows.
- Added UUID components with validation and dedicated UUID exceptions.
- Added ULID generation, validation, and parsing support.
- Added OTP/TOTP generation and verification utilities for secure one-time authentication.
- Added webhook processing with signature verification, idempotency, async dispatch, and provider subscription handling.
- Added unified date and time handling with configurable timezone support.

## Testing and Quality

- Added framework testing helpers, assertions, mocks, and a reusable test case.
- Added database refresh support for isolated tests.
- Added Faker integration for generated test data.
- Added support for parallel test execution through ParaTest.
- Added PHPStan and PHP_CodeSniffer configuration for static analysis and coding standards.
