<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Error;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Support\Error\AuditDigest;
use Throwable;

final class AuditDigestTest extends TestCase
{
    public function test_truncation_is_independent_of_internal_encoding(): void
    {
        $encoding = mb_internal_encoding();
        try {
            mb_internal_encoding('ISO-8859-1');
            $digest = AuditDigest::digest(new RuntimeException(str_repeat('é', 8000)));
            self::assertTrue(mb_check_encoding($digest, 'UTF-8'));
            self::assertSame(AuditDigest::MAX_ERROR_CHARS, mb_strlen($digest, 'UTF-8'));
            self::assertStringEndsWith('…', $digest);
            $short = 'RuntimeException: '.str_repeat('é', 4000);
            self::assertSame($short, AuditDigest::digest(new RuntimeException(str_repeat('é', 4000))));
        } finally {
            mb_internal_encoding($encoding);
        }
    }

    #[Test]
    public function a_multibyte_digest_at_the_exact_character_limit_is_preserved(): void
    {
        $prefix = 'RuntimeException: ';
        $message = str_repeat('é', AuditDigest::MAX_ERROR_CHARS - mb_strlen($prefix));
        self::assertSame($prefix.$message, AuditDigest::digest(new RuntimeException($message)));
    }

    #[Test]
    public function digests_a_simple_throwable(): void
    {
        $digest = AuditDigest::digest(new RuntimeException('boom'));

        self::assertSame('RuntimeException: boom', $digest);
    }

    #[Test]
    public function joins_a_cause_chain_with_a_readable_separator(): void
    {
        $root = new RuntimeException('root');
        $mid = new RuntimeException('mid', 0, $root);
        $top = new RuntimeException('top', 0, $mid);

        $digest = AuditDigest::digest($top);

        self::assertSame(
            "RuntimeException: top\ncaused by: RuntimeException: mid\ncaused by: RuntimeException: root",
            $digest,
        );
    }

    #[Test]
    public function caps_the_chain_depth(): void
    {
        // Build a deeper chain than MAX_CAUSE_DEPTH; expect the digest to stop walking.
        $tail = new RuntimeException('depth-'.AuditDigest::MAX_CAUSE_DEPTH);
        $current = $tail;
        for ($i = AuditDigest::MAX_CAUSE_DEPTH - 1; $i >= 0; $i--) {
            $current = new RuntimeException('depth-'.$i, 0, $current);
        }

        $digest = AuditDigest::digest($current);

        // We should see depth-0 .. depth-(MAX_CAUSE_DEPTH-1), but not the tail.
        self::assertStringContainsString('depth-0', $digest);
        self::assertStringContainsString('depth-'.(AuditDigest::MAX_CAUSE_DEPTH - 1), $digest);
        self::assertStringNotContainsString('depth-'.AuditDigest::MAX_CAUSE_DEPTH, $digest);
    }

    #[Test]
    public function strips_the_nul_byte_in_anonymous_class_names(): void
    {
        // PHP's anonymous classes embed `\0` and path in their FQCN; an unfiltered string would
        // truncate to the NUL byte in a Postgres `text` column. We strip from the NUL onwards.
        $anonymous = new class('boom') extends RuntimeException {};

        $digest = AuditDigest::digest($anonymous);

        self::assertStringNotContainsString("\0", $digest);
        self::assertStringEndsWith(': boom', $digest);
        self::assertStringStartsWith('RuntimeException@anonymous', $digest);
        self::assertStringNotContainsString(__FILE__, $digest);
    }

    #[Test]
    public function strips_a_nul_byte_in_the_exception_message(): void
    {
        // a NUL in the message, not only in an anonymous-class name, would truncate the digest in a
        // PostgreSQL text column and drop the cause chain after it
        $digest = AuditDigest::digest(new RuntimeException("before\0after"));

        self::assertStringNotContainsString("\0", $digest);
        self::assertStringContainsString('beforeafter', $digest);
    }

    #[Test]
    public function caps_the_total_length_with_an_ellipsis(): void
    {
        $longMessage = str_repeat('x', AuditDigest::MAX_ERROR_CHARS * 2);

        $digest = AuditDigest::digest(new RuntimeException($longMessage));

        self::assertSame(AuditDigest::MAX_ERROR_CHARS, mb_strlen($digest));
        self::assertStringStartsWith('RuntimeException: ', $digest);
        self::assertStringEndsWith('…', $digest);
    }

    #[Test]
    public function caps_by_characters_not_bytes(): void
    {
        // PostgreSQL `varchar(n)` and `text` bound length in characters, not bytes, so the cap uses
        // mb_strlen and mb_substr to match, and must never split a character mid-byte
        $multibyte = str_repeat('é', AuditDigest::MAX_ERROR_CHARS * 2); // 'é' is 2 UTF-8 bytes

        $digest = AuditDigest::digest(new RuntimeException($multibyte));

        self::assertSame(AuditDigest::MAX_ERROR_CHARS, mb_strlen($digest), 'capped to N characters');
        self::assertGreaterThan(AuditDigest::MAX_ERROR_CHARS, strlen($digest), 'byte length exceeds the char cap → char-based, not byte-based');
        self::assertTrue(mb_check_encoding($digest, 'UTF-8'), 'no character split mid-byte');
        self::assertStringEndsWith('…', $digest);
    }

    #[Test]
    public function returns_a_short_digest_unchanged(): void
    {
        $short = 'tiny';
        $digest = AuditDigest::digest(new RuntimeException($short));

        self::assertSame('RuntimeException: tiny', $digest);
        self::assertLessThan(AuditDigest::MAX_ERROR_CHARS, mb_strlen($digest));
    }

    #[Test]
    #[Group('adversarial')]
    public function invalid_bytes_are_scrubbed_to_valid_utf8(): void
    {
        // a Throwable message need not be UTF-8: one stray 0xFF would fail the dead-letter INSERT with
        // 22021 while recording an error, turning a recordable poison into a worker crash-loop
        $digest = AuditDigest::digest(new RuntimeException("bad\xFFbytes"));

        self::assertTrue(mb_check_encoding($digest, 'UTF-8'), 'persist-safe means VALID UTF-8, for every Throwable');
        self::assertStringContainsString('bad', $digest);
        self::assertStringContainsString('bytes', $digest);
    }

    #[Test]
    public function control_sequences_are_neutralised_but_the_cause_separator_survives(): void
    {
        // ANSI escapes and C0 controls would ride into console output and ApiOps responses; the
        // cause chain's own newline separator is the one control that must stay
        $digest = AuditDigest::digest(new RuntimeException(
            "red\x1b[31malert",
            previous: new RuntimeException('the cause'),
        ));

        self::assertStringNotContainsString("\x1b", $digest);
        self::assertStringContainsString(AuditDigest::CAUSE_SEPARATOR, $digest);
        self::assertStringContainsString('the cause', $digest);
    }

    #[Test]
    public function outlines_each_level_as_its_class_and_code_without_its_message(): void
    {
        $outline = AuditDigest::outline(new RuntimeException('quotes FR76 3000', 42, new LogicException('quotes ada@example.com', 7)));

        self::assertSame("RuntimeException (code 42)\ncaused by: LogicException (code 7)", $outline);
    }

    #[Test]
    public function outlines_the_sqlstate_a_dbal_driver_reports(): void
    {
        // the DBAL wrapper reports the driver's code, useless on pgsql, so the SQLSTATE rides beside it;
        // the DETAIL line names the conflicting value and stays out
        $detail = "ERROR:  duplicate key value\nDETAIL:  Key (iban)=(FR76 3000) already exists.";
        $violation = new UniqueConstraintViolationException(new class($detail, '23505', 7) extends AbstractException {}, null);

        self::assertSame(
            UniqueConstraintViolationException::class.' (code 7, SQLSTATE 23505)'
                ."\ncaused by: Doctrine\\DBAL\\Driver\\AbstractException@anonymous (code 7, SQLSTATE 23505)",
            AuditDigest::outline($violation),
        );
    }

    #[Test]
    public function a_driver_level_without_a_sqlstate_keeps_its_code_alone(): void
    {
        self::assertSame(
            'Doctrine\\DBAL\\Driver\\AbstractException@anonymous (code 3)',
            AuditDigest::outline(new class('no state', null, 3) extends AbstractException {}),
        );
    }

    #[Test]
    public function keeps_the_message_of_the_levels_its_predicate_picks(): void
    {
        $outline = AuditDigest::outline(
            new LogicException('the engine refused', 0, new RuntimeException('quotes FR76 3000')),
            static fn (Throwable $level): bool => $level instanceof LogicException,
        );

        self::assertSame("LogicException: the engine refused\ncaused by: RuntimeException (code 0)", $outline);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outline_is_as_persist_safe_as_a_digest(): void
    {
        // a kept message still carries controls, invalid bytes and length; an anonymous class still
        // carries its NUL and path
        $keepAll = static fn (Throwable $level): bool => true;

        $kept = AuditDigest::outline(new RuntimeException("red\x1b[31m\xFF".str_repeat('x', AuditDigest::MAX_ERROR_CHARS)), $keepAll);
        self::assertStringNotContainsString("\x1b", $kept);
        self::assertTrue(mb_check_encoding($kept, 'UTF-8'));
        self::assertSame(AuditDigest::MAX_ERROR_CHARS, mb_strlen($kept));
        self::assertStringEndsWith('…', $kept);

        $anonymous = AuditDigest::outline(new class('boom') extends RuntimeException {});
        self::assertSame('RuntimeException@anonymous (code 0)', $anonymous);
    }

    #[Test]
    public function an_outline_walks_the_same_depth_as_a_digest(): void
    {
        $current = new RuntimeException('tail', AuditDigest::MAX_CAUSE_DEPTH);
        for ($i = AuditDigest::MAX_CAUSE_DEPTH - 1; $i >= 0; $i--) {
            $current = new RuntimeException('level', $i, $current);
        }

        $outline = AuditDigest::outline($current);

        self::assertSame(AuditDigest::MAX_CAUSE_DEPTH - 1, substr_count($outline, AuditDigest::CAUSE_SEPARATOR));
        self::assertStringEndsWith('(code '.(AuditDigest::MAX_CAUSE_DEPTH - 1).')', $outline);
    }
}
