<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Abstract Base Job
 */
abstract class BaseJob implements JobInterface
{
    protected string $id;
    protected string $name;
    protected array $payload;
    protected int $attempts = 0;
    protected int $maxAttempts = 3;
    protected string $queue = 'default';
    protected int $delay = 0;
    protected int $timeout = 60;
    protected int $retryDelay = 60;
    protected array $metadata = [];
    
    protected bool $compressPayload = false;
    
    /**
     * Create a new job instance
     */
    public function __construct(array $payload = [], array $options = [])
    {
        $this->id = $options['id'] ?? uniqid('job_', true);
        $this->name = $options['name'] ?? static::class;
        $this->payload = $payload;
        
        if (isset($options['maxAttempts'])) {
            $this->maxAttempts = $options['maxAttempts'];
        }
        
        if (isset($options['queue'])) {
            $this->queue = $options['queue'];
        }
        
        if (isset($options['delay'])) {
            $this->delay = $options['delay'];
        }
        
        if (isset($options['timeout'])) {
            $this->timeout = $options['timeout'];
        }
        
        if (isset($options['retryDelay'])) {
            $this->retryDelay = $options['retryDelay'];
        }
        
        if (isset($options['metadata'])) {
            $this->metadata = $options['metadata'];
        }
        
        if (isset($options['compressPayload'])) {
          $this->compressPayload = $options['compressPayload'];
          if ($this->compressPayload) {
              $this->payload = $this->compressPayload($payload);
          }
      }
      
    }
    
    /**
     * Get the job ID
     */
    public function getId(): string
    {
        return $this->id;
    }
    
    /**
     * Get the job name/class
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * Get the job payload
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
    
    /**
     * Get the number of attempts
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }
    
    /**
     * Increment the number of attempts
     */
    public function incrementAttempts(): void
    {
        $this->attempts++;
    }
    
    /**
     * Get the maximum number of attempts
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
    
    /**
     * Get the queue name
     */
    public function getQueue(): string
    {
        return $this->queue;
    }
    
    /**
     * Get the job delay in seconds
     */
    public function getDelay(): int
    {
        return $this->delay;
    }
    
    /**
     * Get the job timeout in seconds
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }
    
    /**
     * Get the job retry delay in seconds
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }
    
    /**
     * Get job metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    /**
     * Set job metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }
    
    /**
     * Add metadata
     */
    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }
    
    /**
     * Called when the job fails
     */
    public function failed(MachinjiriException $exception): void
    {
        // Default implementation - can be overridden
        error_log(sprintf(
            'Job %s failed after %d attempts: %s',
            $this->getName(),
            $this->getAttempts(),
            $exception->getMessage()
        ));
    }
    
    /**
     * Serialize the job for storage
     */
    public function serialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'queue' => $this->queue,
            'delay' => $this->delay,
            'timeout' => $this->timeout,
            'retryDelay' => $this->retryDelay,
            'metadata' => $this->metadata,
            'created_at' => time(),
        ];
    }
    
    /**
     * Create a job from serialized data
     */
    public static function unserialize(array $data): self
    {
        $job = new static($data['payload'] ?? [], [
            'id' => $data['id'] ?? uniqid('job_', true),
            'name' => $data['name'] ?? static::class,
            'maxAttempts' => $data['maxAttempts'] ?? 3,
            'queue' => $data['queue'] ?? 'default',
            'delay' => $data['delay'] ?? 0,
            'timeout' => $data['timeout'] ?? 60,
            'retryDelay' => $data['retryDelay'] ?? 60,
            'metadata' => $data['metadata'] ?? [],
        ]);
        
        if (isset($data['attempts'])) {
            $job->attempts = $data['attempts'];
        }
        
        return $job;
    }
    
    protected function compressPayload(array $payload): string
    {
        return gzcompress(serialize($payload), 1); // Level 1 compression
    }
    
    protected function decompressPayload(string $compressed): array
    {
        return unserialize(gzuncompress($compressed));
    }
    
    

}