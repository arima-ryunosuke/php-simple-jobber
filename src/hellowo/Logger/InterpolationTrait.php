<?php

namespace ryunosuke\hellowo\Logger;

trait InterpolationTrait
{
    private function interpolate(string $message, array $context): string
    {
        $main = function ($message, array $context, array $parents) use (&$main) {
            foreach ($context as $key => $value) {
                if (preg_match('#\A[a-zA-Z0-9_.]+\z#u', $key)) {
                    $keys   = $parents;
                    $keys[] = $key;

                    if (is_object($value)) {
                        if (method_exists($value, '__toString')) {
                            $value = (string) $value;
                        }
                        else {
                            $value = get_object_vars($value);
                        }
                    }
                    if (is_array($value)) {
                        $message = $main($message, $value, $keys);
                        continue;
                    }

                    if (is_null($value) || is_bool($value)) {
                        $value = var_export($value, true);
                    }

                    $message = str_replace("{" . implode('.', $keys) . "}", $value, $message);
                }
            }
            return $message;
        };
        return $main($message, $context, []);
    }
}
