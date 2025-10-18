<?php

namespace ryunosuke\hellowo\Logger;

trait InterpolationTrait
{
    private function interpolate(string $message, array $context): string
    {
        $array_is_list = function (array $array): bool {
            if (function_exists('array_is_list')) {
                return array_is_list($array); // @codeCoverageIgnore
            }

            $i = 0;
            foreach ($array as $k => $v) {
                if ($k !== $i++) {
                    return false;
                }
            }
            return true;
        };

        $main = function ($message, array $context, array $parents) use (&$main, $array_is_list) {
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
                        if (count($value) === count($value, COUNT_RECURSIVE) && $array_is_list($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        }
                        else {
                            $message = $main($message, $value, $keys);
                            continue;
                        }
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
