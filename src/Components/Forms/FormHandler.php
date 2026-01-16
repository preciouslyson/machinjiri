<?php

namespace Mlangeni\Machinjiri\Components\Forms;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Forms\FormValidator;
use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Security\SQL\SQLInjectionChecker;

/**
 * Comprehensive Form Handler for processing HTTP requests
 */
class FormHandler
{
    private HttpRequest $request;
    private HttpResponse $response;
    private FormValidator $validator;
    private ?CSRFToken $csrfToken;
    private Session $session;
    private Cookie $cookie;
    private SQLInjectionChecker $sqlChecker;
    
    private array $data = [];
    private array $files = [];
    private array $errors = [];
    private array $validatedData = [];
    private array $rules = [];
    private array $messages = [];
    private array $fieldLabels = [];
    
    private string $method;
    private bool $csrfEnabled = true;
    private bool $autoValidate = true;
    private bool $sanitizeInput = true;
    private bool $storeOldInput = true;
    
    public function __construct(
        HttpRequest $request = null,
        HttpResponse $response = null,
        Session $session = null,
        Cookie $cookie = null,
        CSRFToken $csrfToken = null
    ) {
        $this->request = $request ?? HttpRequest::createFromGlobals();
        $this->response = $response ?? new HttpResponse();
        $this->session = $session ?? new Session();
        $this->cookie = $cookie ?? new Cookie();
        $this->csrfToken = $csrfToken;
        $this->validator = new FormValidator([]);
        $this->sqlChecker = new SQLInjectionChecker();
        
        $this->initialize();
    }
    
    private function initialize(): void
    {
        $this->method = $this->request->getMethod();
        $this->loadData();
        $this->loadFiles();
        
        if ($this->storeOldInput) {
            $this->session->set('_old_input', $this->data);
        }
    }
    
    private function loadData(): void
    {
        switch ($this->method) {
            case 'POST':
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                $this->data = array_merge(
                    $this->request->getPostData(),
                    (array)$this->request->getJsonBody(true)
                );
                break;
            case 'GET':
                $this->data = $this->request->getQueryParams();
                break;
            default:
                $this->data = $this->request->all();
        }
        
        // Sanitize input if enabled
        if ($this->sanitizeInput) {
            $this->sanitizeData();
        }
    }
    
    private function loadFiles(): void
    {
        $this->files = $_FILES ?? [];
    }
    
    private function sanitizeData(): void
    {
        foreach ($this->data as $key => $value) {
            if (is_string($value)) {
                $this->data[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $this->data[$key] = $this->recursiveSanitize($value);
            }
        }
    }
    
    private function recursiveSanitize(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $result[$key] = $this->recursiveSanitize($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    public function setRules(array $rules): self
    {
        $this->rules = $rules;
        $this->validator->setData($this->data);
        
        foreach ($rules as $field => $fieldRules) {
            $ruleArray = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            
            foreach ($ruleArray as $rule) {
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $params] = explode(':', $rule, 2);
                    $params = explode(',', $params);
                    $this->validator->addRule($field, $ruleName, ...$params);
                } else {
                    $this->validator->addRule($field, $rule);
                }
            }
        }
        
        return $this;
    }
    
    public function setMessages(array $messages): self
    {
        $this->messages = $messages;
        
        foreach ($messages as $field => $fieldMessages) {
            foreach ($fieldMessages as $rule => $message) {
                $this->validator->setCustomMessage($field, $rule, $message);
            }
        }
        
        return $this;
    }
    
    public function setFieldLabels(array $labels): self
    {
        $this->fieldLabels = $labels;
        
        foreach ($labels as $field => $label) {
            $this->validator->setFieldLabel($field, $label);
        }
        
        return $this;
    }
    
    public function enableCsrf(bool $enabled = true): self
    {
        $this->csrfEnabled = $enabled;
        return $this;
    }
    
    public function autoValidate(bool $autoValidate = true): self
    {
        $this->autoValidate = $autoValidate;
        return $this;
    }
    
    public function sanitizeInput(bool $sanitize = true): self
    {
        $this->sanitizeInput = $sanitize;
        return $this;
    }
    
    public function storeOldInput(bool $store = true): self
    {
        $this->storeOldInput = $store;
        return $this;
    }
    
    public function validate(): bool
    {
        // Check CSRF if enabled
        if ($this->csrfEnabled && $this->csrfToken) {
            $token = $this->data['_token'] ?? $this->request->getHeader('X-CSRF-TOKEN');
            
            if (!$token || !$this->csrfToken->validateToken($token)) {
                $this->errors['_token'] = ['CSRF token validation failed'];
                return false;
            }
        }
        
        // Check for SQL injection
        $suspicious = $this->sqlChecker->checkArray($this->data);
        if (!empty($suspicious)) {
            $this->errors['_security'] = ['Potential SQL injection detected'];
            return false;
        }
        
        // Run validation
        if ($this->validator->validate()) {
            $this->validatedData = $this->validator->getValidatedData();
            return true;
        }
        
        $this->errors = $this->validator->getErrors();
        return false;
    }
    
    public function getData(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->data;
        }
        
        return $this->data[$key] ?? $default;
    }
    
    public function getValidatedData(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->validatedData;
        }
        
        return $this->validatedData[$key] ?? $default;
    }
    
    public function getErrors(string $key = null): array
    {
        if ($key === null) {
            return $this->errors;
        }
        
        return $this->errors[$key] ?? [];
    }
    
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    public function getFiles(string $field = null): array
    {
        if ($field === null) {
            return $this->files;
        }
        
        return $this->files[$field] ?? [];
    }
    
    public function getFile(string $field): ?array
    {
        return $this->files[$field] ?? null;
    }
    
    public function getMethod(): string
    {
        return $this->method;
    }
    
    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }
    
    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }
    
    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }
    
    public function isPut(): bool
    {
        return $this->isMethod('PUT');
    }
    
    public function isPatch(): bool
    {
        return $this->isMethod('PATCH');
    }
    
    public function isDelete(): bool
    {
        return $this->isMethod('DELETE');
    }
    
    public function has(string $key): bool
    {
        return isset($this->data[$key]) && !empty($this->data[$key]);
    }
    
    public function filled(string $key): bool
    {
        $value = $this->data[$key] ?? null;
        return !empty($value) || $value === '0' || $value === 0;
    }
    
    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                $result[$key] = $this->data[$key];
            }
        }
        return $result;
    }
    
    public function except(array $keys): array
    {
        $result = $this->data;
        foreach ($keys as $key) {
            unset($result[$key]);
        }
        return $result;
    }
    
    public function merge(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        $this->validator->setData($this->data);
        return $this;
    }
    
    public function flash(string $key, $value): void
    {
        $this->session->set("_flash.$key", $value);
    }
    
    public function flashInput(): void
    {
        $this->session->set('_flash_input', $this->data);
    }
    
    public function old(string $key, $default = null)
    {
        return $this->session->get("_old_input.$key", $default);
    }
    
    public function flashOldInput(): void
    {
        $this->session->set('_old_input', $this->data);
    }
    
    public function withErrors(array $errors): self
    {
        $this->errors = array_merge($this->errors, $errors);
        $this->session->set('_form_errors', $this->errors);
        return $this;
    }
    
    public function withInput(array $input = null): self
    {
        $this->session->set('_old_input', $input ?? $this->data);
        return $this;
    }
    
    public function redirect(string $url, int $status = 302): void
    {
        if ($this->hasErrors()) {
            $this->withInput()->withErrors($this->errors);
        }
        
        $this->response->redirect($url, $status)->send();
        exit;
    }
    
    public function redirectBack(): void
    {
        $referer = $this->request->getReferer();
        $this->redirect($referer ?? '/');
    }
    
    public function jsonResponse(array $data = [], int $status = 200): void
    {
        $responseData = [
            'success' => empty($this->errors),
            'data' => $data,
            'errors' => $this->errors,
            'validated_data' => $this->validatedData
        ];
        
        $this->response->sendJson($responseData, $status);
    }
    
    public function successResponse(string $message = 'Success', $data = null, int $status = 200): void
    {
        $this->response->sendSuccess($data, $message, $status);
    }
    
    public function errorResponse(string $message = 'Error', int $status = 400, $errors = null): void
    {
        $this->response->sendError($message, $status);
    }
    
    public function handle(): void
    {
        if ($this->autoValidate && !$this->validate()) {
            $this->errorResponse('Validation failed', 422, $this->errors);
            return;
        }
    }
    
    public function csrfField(): string
    {
        if (!$this->csrfToken) {
            return '';
        }
        
        $token = $this->csrfToken->getToken();
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';
    }
    
    public function csrfMeta(): string
    {
        if (!$this->csrfToken) {
            return '';
        }
        
        $token = $this->csrfToken->getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }
    
    public function methodField(string $method): string
    {
        $method = strtoupper($method);
        $allowedMethods = ['PUT', 'PATCH', 'DELETE'];
        
        if (in_array($method, $allowedMethods)) {
            return '<input type="hidden" name="_method" value="' . htmlspecialchars($method) . '">';
        }
        
        return '';
    }
}