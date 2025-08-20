<?php

// Load composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use Mlangeni\Machinjiri\Core\Process\Kameza;
use Mlangeni\Machinjiri\Core\Process\DatabaseQueueDriver;

// Set up the database queue driver
$driver = new DatabaseQueueDriver();
Kameza::setDriver($driver);

// Process jobs indefinitely
echo "Queue worker started. Press Ctrl+C to stop.
";

while (true) {
    try {
        $job = $driver->pop();
        
        if ($job) {
            echo "Processing job: " . $job['id'] . "
";
            
            try {
                Kameza::process($job['payload']);
                $driver->delete($job['id']);
                echo "Job processed successfully: " . $job['id'] . "
";
            } catch (Exception $e) {
                echo "Job failed: " . $e->getMessage() . "
";
                // The job will be handled by Kameza::process which will move it to failed jobs
            }
        } else {
            echo "No jobs in queue. Sleeping for 5 seconds...
";
            sleep(5);
        }
    } catch (Exception $e) {
        echo "Error in worker: " . $e->getMessage() . "
";
        sleep(5); // Wait before continuing
    }
}