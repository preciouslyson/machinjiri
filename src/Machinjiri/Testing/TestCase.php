<?php

namespace Mlangeni\Machinjiri\Testing;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Machinjiri;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithApplication;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithContainer;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithHttp;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithDatabase;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithSession;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithAuthentication;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithConsole;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithException;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithCoverage;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithMail;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithQueue;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithEvents;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithMocks;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithFactories;
use Mlangeni\Machinjiri\Testing\Concerns\SnapshotAssertions;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithTime;
use Mlangeni\Machinjiri\Testing\Traits\RefreshDatabase;
use Mlangeni\Machinjiri\Testing\Traits\WithoutMiddleware;
use Mlangeni\Machinjiri\Testing\Traits\WithFaker;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithApplication,
        InteractsWithContainer,
        InteractsWithHttp,
        InteractsWithDatabase,
        InteractsWithSession,
        InteractsWithAuthentication,
        InteractsWithConsole,
        InteractsWithException,
        InteractsWithCoverage,
        InteractsWithMail,
        InteractsWithQueue,
        InteractsWithEvents,
        InteractsWithMocks,
        InteractsWithFactories,
        SnapshotAssertions,
        InteractsWithTime,
        RefreshDatabase,
        WithoutMiddleware,
        WithFaker;

    /**
     * The application instance.
     */
    protected Machinjiri $app;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Start coverage if Xdebug is enabled
        if ($this->shouldCollectCoverage()) {
            $this->startCoverage();
        }

        $this->setUpApplication();
        $this->setUpFaker();
        $this->setUpDatabase();
        $this->setUpSession();
        $this->setUpMailFake();
        $this->setUpQueueFake();
        $this->setUpEventFake();
    }

    /**
     * Boot the application for testing.
     */
    protected function setUpApplication(): void
    {
        $basePath = dirname(__DIR__, 3); // Project root
        $this->app = Machinjiri::App($basePath . '/src', true);
        $this->app->initialize();

        // Bind test-specific services
        Container::setInstance($this->app);
    }

    /**
     * Refresh the entire application (for repeated runs in same process).
     */
    protected function refreshApplication(): void
    {
        $this->tearDown();
        $this->setUp();
    }

    /**
     * Tear down after each test.
     */
    protected function tearDown(): void
    {
        $this->tearDownDatabase();
        $this->tearDownMailFake();
        $this->tearDownQueueFake();
        $this->tearDownEventFake();

        // Stop coverage and report if needed
        if ($this->shouldCollectCoverage()) {
            $this->stopCoverage();
        }

        parent::tearDown();
    }

    /**
     * Determine if coverage should be collected.
     */
    protected function shouldCollectCoverage(): bool
    {
        return function_exists('xdebug_start_code_coverage') 
            && getenv('COLLECT_COVERAGE') !== 'false';
    }

    /**
     * Get the application instance.
     */
    public function app(): Machinjiri
    {
        return $this->app;
    }
}