<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers;

interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function unserialize(string $data): mixed;
}