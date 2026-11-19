<?php
declare(strict_types=1);

namespace Hub;

/** Text checks shared by the validators. */
final class Text
{
    /**
     * True when the value holds a control character: C0 (U+0000-U+001F), DEL (U+007F) or C1 (U+0080-U+009F),
     * or is not valid UTF-8 (fail closed). With $allowNewlines, LF and CR are allowed (multi-line notes only).
     * Call it on the raw value, before trim, so "Flour\n" is refused rather than silently trimmed.
     */
    public static function hasControl(string $value, bool $allowNewlines = false): bool
    {
        $pattern = $allowNewlines
            ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]|[\x{0080}-\x{009F}]/u'
            : '/[\x00-\x1F\x7F]|[\x{0080}-\x{009F}]/u';
        return preg_match($pattern, $value) !== 0;
    }
}
