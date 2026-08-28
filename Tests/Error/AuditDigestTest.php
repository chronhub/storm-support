<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Error;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Support\Error\AuditDigest;

final class AuditDigestTest extends TestCase
{
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

        // We should see depth-0 .. depth-(MAX_CAUSE_DEPTH-1), but NOT the tail.
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
    }

    #[Test]
    public function strips_a_nul_byte_in_the_exception_message(): void
    {
        // a NUL in the MESSAGE, not just the anon-class name, would truncate the digest in a Postgres
        // text column, swallowing the cause chain after it.
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
        self::assertStringEndsWith('…', $digest);
    }

    #[Test]
    public function caps_by_characters_not_bytes(): void
    {
        // Postgres `varchar(n)` / `text` bound length in CHARACTERS, not bytes; the cap uses
        // mb_strlen / mb_substr to match, and must never split a character mid-byte.
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
        // a Throwable message is not contracted UTF-8; one stray 0xFF would fail the
        // dead-letter INSERT with 22021 while HANDLING an error, turning a recordable poison into a
        // worker crash-loop
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
}
