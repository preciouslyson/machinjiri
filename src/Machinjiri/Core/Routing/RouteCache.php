<?php

namespace Mlangeni\Machinjiri\Core\Routing;

class RouteCache
{
    public function __construct(
        protected string $cacheFile,
        protected RouteCollection $collection
    ) {}

    public function generate(): void
    {
        $data = serialize($this->collection);
        file_put_contents($this->cacheFile, $data, LOCK_EX);
    }

    public function load(): ?RouteCollection
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }
        $data = file_get_contents($this->cacheFile);
        if ($data === false) {
            return null;
        }
        /** @var RouteCollection|null $collection */
        $collection = unserialize($data);
        return $collection;
    }
}