<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Machinjiri;

final class MachinjiriException extends \Exception {
  
    /**
     * @var array Additional context data
     */
    private array $context = [];

    /**
     * @var bool Whether to report this exception
     */
    private bool $shouldReport = true;

    /**
     * @var string Error category
     */
    private string $category = 'general';

    /**
     * Create a new MachinjiriException instance
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param array $context
     * @param string $category
     */
    public function __construct(
        string $message = "", 
        int $code = 0, 
        ?\Throwable $previous = null,
        array $context = [],
        string $category = 'general'
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        $this->category = $category;
        
        // Add context to ErrorHandler
        if (!empty($context)) {
            ErrorHandler::addContext($context);
        }
    }

    /**
     * Set additional context data
     *
     * @param array $context
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        ErrorHandler::addContext($context);
        return $this;
    }

    /**
     * Get context data
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set whether to report this exception
     *
     * @param bool $shouldReport
     * @return self
     */
    public function setShouldReport(bool $shouldReport): self
    {
        $this->shouldReport = $shouldReport;
        return $this;
    }

    /**
     * Get whether to report this exception
     *
     * @return bool
     */
    public function shouldReport(): bool
    {
        return $this->shouldReport;
    }

    /**
     * Set error category
     *
     * @param string $category
     * @return self
     */
    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Get error category
     *
     * @return string
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Create a validation exception
     *
     * @param array $errors
     * @param string $message
     * @return self
     */
    public static function validation(array $errors, string $message = "Validation failed"): self
    {
        return new self($message, 422, null, ['validation_errors' => $errors], 'validation');
    }

    /**
     * Create a not found exception
     *
     * @param string $message
     * @return self
     */
    public static function notFound(string $message = "Resource not found"): self
    {
        return new self($message, 404, null, [], 'not_found');
    }

    /**
     * Create an unauthorized exception
     *
     * @param string $message
     * @return self
     */
    public static function unauthorized(string $message = "Unauthorized access"): self
    {
        return new self($message, 401, null, [], 'unauthorized');
    }

    /**
     * Create a forbidden exception
     *
     * @param string $message
     * @return self
     */
    public static function forbidden(string $message = "Access forbidden"): self
    {
        return new self($message, 403, null, [], 'forbidden');
    }

    /**
     * Create a database exception
     *
     * @param string $message
     * @param \Throwable|null $previous
     * @return self
     */
    public static function database(string $message = "Database error", ?\Throwable $previous = null): self
    {
        return new self($message, 500, $previous, [], 'database');
    }

    /**
     * Create a service unavailable exception
     *
     * @param string $message
     * @param int $retryAfter
     * @return self
     */
    public static function serviceUnavailable(string $message = "Service temporarily unavailable", int $retryAfter = 60): self
    {
        return new self($message, 503, null, ['retry_after' => $retryAfter], 'service_unavailable');
    }

    /**
     * Create a rate limit exception
     *
     * @param string $message
     * @param int $retryAfter
     * @return self
     */
    public static function rateLimit(string $message = "Too many requests", int $retryAfter = 60): self
    {
        return new self($message, 429, null, ['retry_after' => $retryAfter], 'rate_limit');
    }

    /**
     * Show the exception with enhanced features
     *
     * @return void
     */
    public final function show(): void {
        $app = Machinjiri::getInstance();
        $appName = getenv("APP_NAME") ?? "Machinjiri";
        
        // Add exception to context
        ErrorHandler::addContext([
            'exception_category' => $this->category,
            'exception_context' => $this->context,
            'exception_should_report' => $this->shouldReport
        ]);

        // Trigger exception shown event
        if (method_exists($app, 'getEventListener')) {
            $app->getEventListener()->trigger('exception.shown', [
                'exception' => $this,
                'category' => $this->category,
                'context' => $this->context
            ]);
        }

        // Determine how to render based on environment and request type
        if ($this->shouldRenderAsJson()) {
            $this->renderJson($app->getEnvironment());
        } elseif ($app->getEnvironment() === 'development') {
            $this->showException($appName);
        } elseif ($app->getEnvironment() === 'production') {
            $this->renderGeneric($appName);
        }
    }

    /**
     * Check if exception should be rendered as JSON
     *
     * @return bool
     */
    private function shouldRenderAsJson(): bool
    {
        // Check if it's an AJAX request or API call
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        $acceptsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        
        return $isAjax || $acceptsJson || $this->isApiRequest();
    }

    /**
     * Check if it's an API request
     *
     * @return bool
     */
    private function isApiRequest(): bool
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($path, '/api/') === 0 || strpos($path, '/ajax/') === 0;
    }

    /**
     * Render exception as JSON
     *
     * @param string $environment
     * @return void
     */
    private function renderJson(string $environment): void
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $this->getCode(),
                'message' => $this->getMessage(),
                'category' => $this->category,
                'timestamp' => time()
            ]
        ];

        // Add debug information in development
        if ($environment === 'development') {
            $response['error']['debug'] = [
                'file' => $this->getFile(),
                'line' => $this->getLine(),
                'trace' => array_slice($this->getTrace(), 0, 5), // First 5 frames
                'context' => $this->context
            ];
        }

        // Set appropriate HTTP status code
        $statusCode = $this->getCode() >= 400 && $this->getCode() < 600 ? $this->getCode() : 500;
        
        // Create HTTP response
        $httpResponse = new HttpResponse();
        $httpResponse->setStatusCode($statusCode)
                     ->setJsonBody($response)
                     ->send();
    }

    /**
     * Render generic error page for production
     *
     * @param string $appName
     * @return void 
     */
    public function renderGeneric(string $appName): void 
    {
        // Use the enhanced ErrorHandler's generic error page
        ErrorHandler::renderGenericErrorPage();
    }
  
    /**
     * Show detailed exception for development
     *
     * @param string $appName
     * @return void 
     */
    public function showException(string $appName): void 
    {
        // Use the enhanced ErrorHandler's error page
        ErrorHandler::renderErrorPage($this);
    }

    /**
     * Render exception as HTML snippet (for embedding in layouts)
     *
     * @return string
     */
    public function renderAsHtmlSnippet(): string
    {
        return <<<HTML
<div class="alert alert-danger" role="alert">
    <h4 class="alert-heading">
        <i class="fas fa-exclamation-triangle"></i> Error #{$this->getCode()}
    </h4>
    <p>{$this->getMessage()}</p>
    <hr>
    <p class="mb-0">
        <small>
            <i class="fas fa-file"></i> {$this->getFile()} 
            <i class="fas fa-code"></i> Line {$this->getLine()}
        </small>
    </p>
</div>
HTML;
    }

    /**
     * Get exception as array for logging
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'class' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'category' => $this->category,
            'context' => $this->context,
            'trace' => $this->getTrace(),
            'timestamp' => time(),
            'should_report' => $this->shouldReport
        ];
    }

    /**
     * Magic method for string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            "[%s] #%d: %s in %s on line %d",
            $this->category,
            $this->getCode(),
            $this->getMessage(),
            $this->getFile(),
            $this->getLine()
        );
    }

    /**
     * Get formatted error message with category
     *
     * @return string
     */
    public function getFormattedMessage(): string
    {
        return sprintf(
            "[%s] %s",
            strtoupper($this->category),
            $this->getMessage()
        );
    }

    /**
     * Get suggestion for fixing the error
     *
     * @return string|null
     */
    public function getSuggestion(): ?string
    {
        $suggestions = [
            'validation' => 'Please check your input data and try again.',
            'not_found' => 'The requested resource does not exist. Check the URL or parameters.',
            'unauthorized' => 'Please log in to access this resource.',
            'forbidden' => 'You do not have permission to access this resource.',
            'database' => 'A database error occurred. Please try again later.',
            'service_unavailable' => 'The service is temporarily unavailable. Please try again later.',
            'rate_limit' => 'You have made too many requests. Please wait and try again.'
        ];

        return $suggestions[$this->category] ?? null;
    }

    /**
     * Check if exception is a client error (4xx)
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->getCode() >= 400 && $this->getCode() < 500;
    }

    /**
     * Check if exception is a server error (5xx)
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->getCode() >= 500 && $this->getCode() < 600;
    }

    /**
     * Get HTTP status text
     *
     * @return string
     */
    public function getHttpStatusText(): string
    {
        $statusTexts = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable'
        ];

        return $statusTexts[$this->getCode()] ?? 'Unknown Status';
    }
}