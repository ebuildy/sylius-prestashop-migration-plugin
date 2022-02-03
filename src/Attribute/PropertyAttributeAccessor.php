<?php

namespace Jgrasp\PrestashopMigrationPlugin\Attribute;

use ReflectionAttribute;
use ReflectionProperty;

class PropertyAttributeAccessor
{
    public function get(ReflectionProperty $reflectionProperty, string $attributeClass): ?ReflectionAttribute
    {
        $attributes = $reflectionProperty->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);

        /**
         * @var ReflectionAttribute|false $attribute
         */
        $attribute = reset($attributes);

        if (!$attribute) {
            return null;
        }

        return $attribute;
    }
}
