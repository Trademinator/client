<?php

declare(strict_types=1);

// TypePHP treats std::ref() as a compile-time reference wrapper. In ordinary
// PHP (CLI/tests), provide the equivalent reference-returning shim. This file
// is embedded, not compiled as a TypePHP source directory.
if (!class_exists('std', false)) {
    class std
    {
        public static function &ref(mixed &$value): mixed
        {
            return $value;
        }
    }
}
