<?php

namespace Mlangeni\Machinjiri\Testing;

trait Mocks
{
    protected function mock(string $class)
    {
        $mock = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->singleton($class, $mock);
        return $mock;
    }

    protected function stub(string $class, array $methods = [])
    {
        $stub = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->getMock();

        foreach ($methods as $method => $returnValue) {
            $stub->method($method)->willReturn($returnValue);
        }

        $this->singleton($class, $stub);
        return $stub;
    }
}