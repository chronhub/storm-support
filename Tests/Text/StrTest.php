<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Text;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Support\Text\Str;

final class StrTest extends TestCase
{
    #[Test]
    public function a_nbsp_only_value_is_blank(): void
    {
        // the value an ASCII trim keeps as present while every reader renders it empty
        self::assertTrue(Str::isBlank("\u{00A0}"));
        self::assertTrue(Str::isBlank("\u{00A0}\u{2003} \u{3000}"));
    }

    #[Test]
    public function empty_and_ascii_whitespace_values_are_blank(): void
    {
        self::assertTrue(Str::isBlank(''));
        self::assertTrue(Str::isBlank(" \t"));
        self::assertTrue(Str::isBlank("\r\n"));
    }

    #[Test]
    public function a_value_with_a_visible_character_is_not_blank(): void
    {
        self::assertFalse(Str::isBlank('a'));
        self::assertFalse(Str::isBlank("\u{00A0}a\u{00A0}"));
    }

    #[Test]
    public function a_format_character_is_not_blank(): void
    {
        // U+200B is a format character, not a separator: the identity rule that owns the value decides
        self::assertFalse(Str::isBlank("\u{200B}"));
    }

    #[Test]
    public function an_ill_formed_value_is_not_blank(): void
    {
        self::assertFalse(Str::isBlank("\xFF"));
    }

    #[Test]
    public function printable_escapes_c1_and_format_characters(): void
    {
        $printable = Str::printable("a\u{202E}b");

        self::assertSame('a\u{202E}b', $printable);
        self::assertStringNotContainsString("\u{202E}", $printable);
        self::assertSame('\u{0085}', Str::printable("\u{0085}"));
        self::assertSame('\u{200B}', Str::printable("\u{200B}"));
    }

    #[Test]
    public function printable_escapes_c0_controls_and_del_as_uppercase_hex(): void
    {
        self::assertSame('\x00', Str::printable("\x00"));
        self::assertSame('\x1F', Str::printable("\x1F"));
        self::assertSame('\x7F', Str::printable("\x7F"));
        self::assertSame('line\x0Abreak', Str::printable("line\nbreak"));
    }

    #[Test]
    public function printable_escapes_each_ill_formed_byte(): void
    {
        self::assertSame('\xFF', Str::printable("\xFF"));
        // a lead byte without its continuation: only that byte is escaped, the ASCII after it stays
        self::assertSame('a\xC3(b', Str::printable("a\xC3(b"));
        self::assertSame('\xE2\x82', Str::printable("\xE2\x82"));
        // a surrogate is never well-formed UTF-8, so its encoded bytes are escaped one by one
        self::assertSame('\xED\xA0\x80', Str::printable("\xED\xA0\x80"));
    }

    #[Test]
    public function printable_keeps_ordinary_text_unchanged(): void
    {
        self::assertSame('order-42', Str::printable('order-42'));
        self::assertSame('café façade', Str::printable('café façade'));
        self::assertSame("a\u{00A0}b", Str::printable("a\u{00A0}b"));
    }

    #[Test]
    public function excerpt_keeps_the_ellipsis_inside_a_code_point_budget(): void
    {
        // one budget, code points, the ellipsis counted: a 61 renders 59 plus the ellipsis, a 60 renders whole
        self::assertSame(str_repeat('é', 59).'…', Str::excerpt(60)(str_repeat('é', 61)));
        self::assertSame(str_repeat('é', 60), Str::excerpt(60)(str_repeat('é', 60)));
        self::assertSame('ab...', Str::excerpt(5, '...')('abcdefgh'));
        self::assertSame('short', 'short' |> Str::excerpt(60));
        self::assertSame('a…', Str::excerpt(2, '…')('abc'));
    }

    #[Test]
    public function excerpt_refuses_a_budget_the_ellipsis_alone_would_exceed(): void
    {
        // at the ellipsis length the cut is empty and the excerpt is the ellipsis alone; below it
        // `mb_substr` gets a negative length, cuts from the end and LENGTHENS the value; both are
        // refused at construction, before any value
        foreach ([[1, '…'], [3, '...'], [2, '...'], [0, '…']] as [$budget, $ellipsis]) {
            try {
                Str::excerpt($budget, $ellipsis);
                self::fail(sprintf('budget %d with ellipsis %s must be refused', $budget, $ellipsis));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString((string) $budget, $e->getMessage());
                self::assertStringContainsString($ellipsis, $e->getMessage());
            }
        }
    }

    #[Test]
    public function one_line_collapses_every_whitespace_run_to_one_space(): void
    {
        self::assertSame('a b c', Str::oneLine("a\n\t b\r\n\n  c"));
        self::assertSame('flat', Str::oneLine('flat'));
    }

    #[Test]
    public function short_class_keeps_the_segment_after_the_last_separator(): void
    {
        self::assertSame('Str', Str::shortClass(Str::class));
        self::assertSame('Unqualified', Str::shortClass('Unqualified'));
    }

    #[Test]
    public function non_empty_or_null_keeps_only_a_non_empty_string(): void
    {
        self::assertSame('x', Str::nonEmptyOrNull('x'));
        self::assertSame(' ', Str::nonEmptyOrNull(' '));
        self::assertNull(Str::nonEmptyOrNull(''));
        self::assertNull(Str::nonEmptyOrNull(null));
        self::assertNull(Str::nonEmptyOrNull(false));
        self::assertNull(Str::nonEmptyOrNull(1));
    }

    #[Test]
    public function trim_removes_a_bom_and_a_nbsp_at_both_borders(): void
    {
        self::assertSame('x', Str::trim("\u{FEFF}\u{00A0} \t\n\r\0\x0B\fx\f\x0B\0\r\n\t \u{00A0}\u{FEFF}"));
        // an em space is a separator, not a border character: it is content here
        self::assertSame("\u{2003}x", Str::trim("\u{2003}x "));
        self::assertSame('', Str::trim("\u{00A0}"));
    }

    #[Test]
    public function lower_folds_multibyte_letters(): void
    {
        self::assertSame('élan', Str::lower('ÉLAN'));
        self::assertSame("\u{00A0}a", Str::lower("\u{00A0}A"));
    }

    #[Test]
    public function is_well_formed_refuses_a_truncated_sequence_and_a_lone_continuation_byte(): void
    {
        self::assertTrue(Str::isWellFormed('élan'));
        self::assertTrue(Str::isWellFormed(''));
        self::assertFalse(Str::isWellFormed("order-\xC3\x28"));
        self::assertFalse(Str::isWellFormed("\xFE"));
    }

    #[Test]
    public function pascal_matches_the_pinned_unicode_string_outputs(): void
    {
        // the outputs of \Symfony\Component\String\UnicodeString::camel()->title(), pinned
        foreach ([
            'card_payment' => 'CardPayment',
            'card-payment' => 'CardPayment',
            'cardPayment' => 'CardPayment',
            'CardPayment' => 'CardPayment',
            'HTTPServer' => 'HTTPServer',
            'account' => 'Account',
            'Account.php' => 'AccountPhp',
            ' foo  bar ' => 'FooBar',
            'foo2bar' => 'Foo2bar',
            '2fa' => '2fa',
            'élan' => 'Élan',
            'iPhone' => 'IPhone',
            'hTTP' => 'HTTP',
            'eBook' => 'EBook',
            'A B' => 'AB',
            'HTTP server' => 'HTTPServer',
            'ABc d' => 'ABcD',
            'İstanbul' => "I\u{0307}stanbul",
        ] as $input => $expected) {
            self::assertSame($expected, Str::pascal($input), $input);
        }
    }

    #[Test]
    public function snake_matches_the_pinned_unicode_string_outputs(): void
    {
        // the outputs of \Symfony\Component\String\UnicodeString::snake(), pinned
        foreach ([
            'card_payment' => 'card_payment',
            'card-payment' => 'card_payment',
            'cardPayment' => 'card_payment',
            'CardPaymentId' => 'card_payment_id',
            'HTTPServer' => 'http_server',
            'Account.php' => 'account_php',
            ' foo  bar ' => 'foo_bar',
            'foo2bar' => 'foo2bar',
            '2fa' => '2fa',
            'élan' => 'élan',
            'iPhone' => 'i_phone',
            'hTTP' => 'h_ttp',
            'eBook' => 'e_book',
            'A B' => 'a_b',
            'HTTP server' => 'http_server',
            'ABc d' => 'a_bc_d',
            'İstanbul' => "i\u{0307}stanbul",
        ] as $input => $expected) {
            self::assertSame($expected, Str::snake($input), $input);
        }
    }

    #[Test]
    public function canonical_composes_a_decomposed_letter_and_keeps_a_composed_one(): void
    {
        self::assertSame("\u{00E9}", Str::canonical("e\u{0301}"));
        self::assertSame("\u{00E9}", Str::canonical("\u{00E9}"));
        self::assertSame('', Str::canonical(''));
    }

    #[Test]
    public function pascal_title_cases_a_multibyte_initial_of_a_later_word(): void
    {
        // the first word is lowercased then title-cased again; only a LATER word reaches the
        // title mapping directly, and only a multibyte initial tells it apart from ucwords
        self::assertSame("A\u{00C9}lan", Str::pascal("a \u{00E9}lan"));
        self::assertSame("a_\u{00E9}lan", Str::snake("a \u{00E9}lan"));
    }

    #[Test]
    public function snake_keeps_a_long_uppercase_run_whole(): void
    {
        // a boundary pattern that backtracks exhausts the PCRE limit on such a run and returns
        // null, which the cast would turn into an empty identifier
        self::assertSame(str_repeat('a', 2000), Str::snake(str_repeat('A', 2000)));
        self::assertSame(str_repeat('a', 2000).'_server', Str::snake(str_repeat('A', 2000).'Server'));
    }

    #[Test]
    public function pascal_refuses_an_ill_formed_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pascal');

        Str::pascal("a\xC3\x28");
    }

    #[Test]
    public function snake_refuses_an_ill_formed_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('\xC3(');

        Str::snake("a\xC3\x28");
    }
}
