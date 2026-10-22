<?php

namespace App\Casts;

use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

/**
 * This class ensures that "?sfw" and "?kids" boolean type query string parameters in the url would be interpreted as "true"
 */
final class ContextualBooleanCast implements Cast
{

    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        $propertyName = $property->name;

        if (array_key_exists($propertyName, $properties) && $properties[$propertyName] === "")
        {
            return true;
        }

        return $value;
    }
}
