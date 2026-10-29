<?php

namespace App\Support;

/**
 * Notas rápidas de cocina ("Sin hielo", "Poco hecha"…): textos cortos, sin
 * repetir y en el orden en que se configuran.
 */
final class QuickNotes
{
    public const MAX_LENGTH = 40;

    /** @return list<string> */
    public static function normalize(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        $clean = [];
        foreach ($notes as $note) {
            $note = is_string($note) ? trim(preg_replace('/\s+/u', ' ', $note)) : '';
            if ($note === '') {
                continue;
            }
            $note = mb_substr($note, 0, self::MAX_LENGTH);
            if (! in_array(mb_strtolower($note), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $note;
            }
        }

        return $clean;
    }
}
