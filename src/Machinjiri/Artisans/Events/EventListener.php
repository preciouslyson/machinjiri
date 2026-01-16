<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class EventListener
{
    /**
     * @var array Registered event listeners
     */
    protected $listeners = [];

    /**
     * @var Logger Logger instance for recording events
     */
    protected $logger;

    /**
     * Constructor
     *
     * @param Logger $logger Logger instance for event recording
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register an event listener
     *
     * @param string $event Event name
     * @param callable $listener Callback function
     * @param int $priority Listener priority (higher executes first)
     */
    public function on(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][$priority][] = $listener;
        $this->logger->info("Registered listener for event: {$event}", [
            'priority' => $priority
        ]);
    }

    /**
     * Trigger an event
     *
     * @param string $event Event name
     * @param mixed $payload Event data
     * @param bool $halt Whether to stop after first non-null response
     * @return array|mixed|null Response from listeners
     */
    public function trigger(string $event, $payload = null, bool $halt = false)
    {
        $responses = [];
        $hasListeners = isset($this->listeners[$event]);

        // Log event triggering
        $this->logger->info("Event triggered: {$event}", [
            'payload' => $payload,
            'has_listeners' => $hasListeners
        ]);

        if (!$hasListeners) {
            return $halt ? null : [];
        }

        // Sort listeners by priority (higher first)
        krsort($this->listeners[$event]);

        foreach ($this->listeners[$event] as $priority => $listeners) {
            foreach ($listeners as $listener) {
                $response = call_user_func($listener, $payload, $event);
                
                if ($halt && !is_null($response)) {
                    $this->logger->debug("Event halted: {$event}", [
                        'response' => $response,
                        'priority' => $priority
                    ]);
                    return $response;
                }

                if ($response !== null) {
                    $responses[] = $response;
                }
            }
        }

        return $halt ? null : $responses;
    }

    /**
     * Trigger an event and halt after first response
     *
     * @param string $event Event name
     * @param mixed $payload Event data
     * @return mixed Response from the first listener that returns non-null
     */
    public function until(string $event, $payload = null)
    {
        return $this->trigger($event, $payload, true);
    }

    /**
     * Remove specific listener from an event
     *
     * @param string $event Event name
     * @param callable $removeListener Listener to remove
     * @return bool True if listener was removed, false otherwise
     */
    public function removeListener(string $event, callable $removeListener): bool
    {
        if (!isset($this->listeners[$event])) {
            return false;
        }

        foreach ($this->listeners[$event] as $priority => $listeners) {
            foreach ($listeners as $key => $listener) {
                if ($listener === $removeListener) {
                    unset($this->listeners[$event][$priority][$key]);
                    
                    // Clean up empty priorities
                    if (empty($this->listeners[$event][$priority])) {
                        unset($this->listeners[$event][$priority]);
                    }
                    
                    $this->logger->info("Removed listener from event: {$event}", [
                        'priority' => $priority
                    ]);
                    
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove all listeners for an event
     *
     * @param string $event Event name
     */
    public function removeAllListeners(string $event): void
    {
        if (isset($this->listeners[$event])) {
            unset($this->listeners[$event]);
            $this->logger->info("Removed all listeners for event: {$event}");
        }
    }

    /**
     * Get all registered events
     *
     * @return array List of event names
     */
    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Get listeners for a specific event
     *
     * @param string $event Event name
     * @return array Event listeners
     */
    public function getListeners(string $event): array
    {
        if (!isset($this->listeners[$event])) {
            return [];
        }

        // Flatten the listeners array while preserving priority order
        $listeners = [];
        krsort($this->listeners[$event]);
        
        foreach ($this->listeners[$event] as $priorityListeners) {
            $listeners = array_merge($listeners, $priorityListeners);
        }
        
        return $listeners;
    }

    /**
     * Check if an event has listeners
     *
     * @param string $event Event name
     * @return bool True if event has listeners, false otherwise
     */
    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * Display information about registered events and listeners
     */
    public function displayEvents(): void
    {
        $events = $this->getEvents();
        
        if (empty($events)) {
            echo "No events registered.\n";
            return;
        }

        echo "Registered Events:\n";
        echo "=================\n";
        
        foreach ($events as $event) {
            $listeners = $this->getListeners($event);
            $count = count($listeners);
            
            echo "Event: {$event} ({$count} listener(s))\n";
            
            foreach ($this->listeners[$event] as $priority => $priorityListeners) {
                foreach ($priorityListeners as $index => $listener) {
                    $listenerType = is_array($listener) 
                        ? (is_object($listener[0]) 
                            ? get_class($listener[0]) . '::' . $listener[1] 
                            : $listener[0] . '::' . $listener[1])
                        : (is_string($listener) ? $listener : 'Closure');
                    
                    echo "  - Priority: {$priority}, Listener: {$listenerType}\n";
                }
            }
            echo "\n";
        }
    }
}