<?php

declare(strict_types=1);

namespace Storm\Support\Text;

use Closure;
use InvalidArgumentException;
use Normalizer;

/**
 * The string rules several packages must apply identically, written once so their copies cannot
 * drift apart.
 *
 * An operation that takes only the string is called directly and serves as a pipe stage as is,
 * `$value |> Str::printable(...)`. An operation that takes parameters returns a
 * `Closure(string): string`, so the call itself is the stage.
 */
final class Str
{
    /**
     * One unit `printable()` spells out: a well-formed multibyte character, whose category decides,
     * or a single byte that is a C0 control, DEL, or no part of a well-formed UTF-8 sequence.
     */
    private const string UNIT = '/(?<char>[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|[\x00-\x1F\x7F-\xFF]/';

    /**
     * Whether the value is empty, or made only of whitespace and Unicode separators.
     *
     * A value made only of no-break spaces renders empty wherever it is read, yet survives an ASCII
     * `trim()` as present; as an identifier it would poison deduplication. A format character such
     * as U+200B is not a separator and keeps the value non-blank, and so does a byte that is not
     * valid UTF-8.
     */
    public static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\p{Z}]*$/u', $value) === 1;
    }

    /**
     * The value with every control, format or ill-formed byte spelled out, so a refused value can
     * be echoed into a message without reaching a log or a terminal raw.
     *
     * - A C0 control, DEL, or a byte outside a well-formed UTF-8 sequence becomes `\xNN`
     * - A non-ASCII control or format character, such as U+0085 or U+202E, becomes `\u{XXXX}`
     * - Every other character is kept as is
     *
     * Hexadecimal digits are uppercase. A surrogate cannot occur in well-formed UTF-8, so its encoded
     * bytes fall to the byte rule.
     */
    public static function printable(string $value): string
    {
        // a byte-level alternation of fixed width, matched without the u modifier: no input makes
        // PCRE fail, so the cast never empties a value
        return (string) preg_replace_callback(
            self::UNIT,
            static fn (array $unit): string => match (true) {
                $unit['char'] === null => sprintf('\x%02X', ord($unit[0])),
                preg_match('/^[\p{Cc}\p{Cf}]\z/u', $unit['char']) === 1 => sprintf('\u{%04X}', mb_ord($unit['char'], 'UTF-8')),
                default => $unit['char'],
            },
            $value,
            flags: PREG_UNMATCHED_AS_NULL,
        );
    }

    /**
     * The value cut to `$maxCodePoints` code points, the ellipsis counted inside that one budget: a
     * value within the budget is returned whole, a longer one keeps `$maxCodePoints` minus the
     * ellipsis length, then the ellipsis.
     *
     * Code points, not graphemes: the sites that consume it fill a bounded column or a table cell,
     * neither of which measures what a terminal draws. The budget must leave room for at least one
     * code point beside the ellipsis; a smaller one is refused when the closure is built, so a
     * misconfigured column fails at wiring and never at the first long value.
     *
     * @return Closure(string): string
     *
     * @throws InvalidArgumentException when the budget does not exceed the ellipsis length
     */
    public static function excerpt(int $maxCodePoints, string $ellipsis = '…'): Closure
    {
        // the encoding is named: a process that set another internal encoding must not change the cut
        $ellipsisLength = mb_strlen($ellipsis, 'UTF-8');

        if ($maxCodePoints <= $ellipsisLength) {
            throw new InvalidArgumentException(sprintf(
                'Str::excerpt() needs a budget above the ellipsis length, got %d for "%s" of %d code points.',
                $maxCodePoints,
                self::printable($ellipsis),
                $ellipsisLength,
            ));
        }

        return static fn (string $value): string => mb_strlen($value, 'UTF-8') <= $maxCodePoints
            ? $value
            : mb_substr($value, 0, $maxCodePoints - $ellipsisLength, 'UTF-8').$ellipsis;
    }

    /**
     * The value with every run of whitespace, line breaks included, folded to one space, so a
     * multi-line error keeps a table one row tall.
     */
    public static function oneLine(string $value): string
    {
        // ASCII whitespace by design: a no-break space is content, and no input makes PCRE fail here
        return (string) preg_replace('/\s+/', ' ', $value);
    }

    /**
     * The segment after the last namespace separator; a name without one is returned whole.
     */
    public static function shortClass(string $fqcn): string
    {
        $separator = strrpos($fqcn, '\\');

        return $separator === false ? $fqcn : substr($fqcn, $separator + 1);
    }

    /**
     * The value when it is a string with at least one byte, null for everything else, the shape a
     * console option or a filter takes when absence and the empty string mean the same thing.
     *
     * A string of blanks is kept: the caller decides what blank means for it.
     */
    public static function nonEmptyOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The border characters `trim()` removes: ASCII whitespace and NUL, the no-break space and the
     * byte order mark.
     */
    private const string BORDER = " \t\n\r\0\x0B\f\u{00A0}\u{FEFF}";

    /**
     * Whether the value is well-formed UTF-8, the question every multibyte operation here assumes
     * answered: a caller that owns an encoding boundary asks it first and names its own refusal.
     */
    public static function isWellFormed(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /**
     * The value in Unicode canonical composition, NFC, so a letter and its combining accent become
     * the one precomposed character every other reader stores and compares. The value must be
     * well-formed UTF-8, the question `isWellFormed()` answers first: an ill-formed value comes
     * back empty, never refused, so a caller that owns an identity guards before it composes.
     */
    public static function canonical(string $value): string
    {
        // NFC of well-formed UTF-8 never fails; the cast keeps the signature total
        return (string) Normalizer::normalize($value, Normalizer::NFC);
    }

    /**
     * The value without its border characters, the no-break space and the byte order mark among
     * them; a Unicode separator such as an em space is content and stays.
     */
    public static function trim(string $value): string
    {
        return mb_trim($value, self::BORDER, 'UTF-8');
    }

    /**
     * The value folded to lowercase, multibyte letters included.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * The value as a PascalCase identifier: runs of characters that are neither letters nor digits
     * split words, each word opens on a title-cased character, a word already opening on an
     * uppercase letter is kept whole, so `HTTPServer` survives as is, and the first character is
     * always title-cased, so `iPhone` yields `IPhone`.
     *
     * @throws InvalidArgumentException when the value is not well-formed UTF-8
     */
    public static function pascal(string $value): string
    {
        self::requireWellFormed($value, 'pascal');

        return (string) preg_replace_callback(
            '/^./u',
            static fn (array $m): string => mb_convert_case($m[0], MB_CASE_TITLE, 'UTF-8'),
            self::joinedWords($value),
        );
    }

    /**
     * The value as a snake_case identifier: the joined words, an underscore before each uppercase
     * run that opens a word, then lowercase, so `HTTPServer` becomes `http_server`, `hTTP` becomes
     * `h_ttp` and `A B` becomes `a_b`.
     *
     * @throws InvalidArgumentException when the value is not well-formed UTF-8
     */
    public static function snake(string $value): string
    {
        self::requireWellFormed($value, 'snake');

        // both patterns are linear, a one-character match with a lookahead: a run of uppercase
        // letters of any length never backtracks, so no well-formed input makes PCRE fail here
        $boundaries = (string) preg_replace(
            ['/(\p{Lu})(?=\p{Lu}\p{Ll})/u', '/([\p{Ll}0-9])(?=\p{Lu})/u'],
            '\1_',
            self::joinedWords($value),
        );

        return self::lower($boundaries);
    }

    /**
     * The words of the value joined without separators: the first character the word rule
     * matches is lowercased, every later one is title-cased, and a character followed by an
     * uppercase letter is never matched, so `A B` becomes `aB` while `HTTPServer` stays whole.
     */
    private static function joinedWords(string $value): string
    {
        $words = (string) preg_replace('/[^\pL0-9]++/u', ' ', $value);
        $first = true;

        return str_replace(' ', '', (string) preg_replace_callback(
            '/\b.(?!\p{Lu})/u',
            static function (array $m) use (&$first): string {
                if ($first) {
                    $first = false;

                    return mb_strtolower($m[0], 'UTF-8');
                }

                return mb_convert_case($m[0], MB_CASE_TITLE, 'UTF-8');
            },
            $words,
        ));
    }

    /**
     * The guard an identifier operation runs first: an ill-formed value would otherwise come out
     * of a `preg_replace` in `u` mode as an empty string, an identity erased without a word.
     *
     * @throws InvalidArgumentException when the value is not well-formed UTF-8
     */
    private static function requireWellFormed(string $value, string $operation): void
    {
        if (! self::isWellFormed($value)) {
            throw new InvalidArgumentException(sprintf(
                'Str::%s() needs well-formed UTF-8, got "%s".',
                $operation,
                self::printable($value),
            ));
        }
    }
}
