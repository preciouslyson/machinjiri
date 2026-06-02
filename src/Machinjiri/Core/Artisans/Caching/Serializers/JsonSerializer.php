<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class JsonSerializer implements SerializerInterface
{
    public function serialize(mixed $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if ($json === false) {
            throw new MachinjiriException("JSON serialization failed", 500);
        }
        return $json;
    }

    public function unserialize(string $data): mixed
    {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}