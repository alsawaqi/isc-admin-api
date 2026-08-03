<?php

namespace App\Support;

class HierarchyName
{
    public static function display(?string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], [' ', '', ''], (string) $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        return $value;
    }

    public static function key(?string $value): string
    {
        return mb_strtolower(self::display($value));
    }

    public static function fingerprint(?string $value): string
    {
        return hash('sha256', self::key($value));
    }
}
