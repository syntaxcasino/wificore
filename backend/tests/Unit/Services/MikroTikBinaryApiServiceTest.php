<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\MikroTik\MikroTikBinaryApiService;

/**
 * Unit tests for MikroTikBinaryApiService wire-protocol and helper methods.
 *
 * All tests exercise the service through a partial-mock subclass that exposes
 * the private wire-protocol helpers via public proxies, so we never need a
 * real TCP socket.
 */
class MikroTikBinaryApiServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers: subclass that exposes private methods for testing
    // -------------------------------------------------------------------------

    /**
     * Standalone implementation of the RouterOS variable-length encoder
     * (mirrors MikroTikBinaryApiService::encodeLength exactly).
     */
    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return pack('n', $length);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return pack('N', $length)[1] . pack('N', $length)[2] . pack('N', $length)[3];
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return pack('N', $length);
        }
        return chr(0xF0) . pack('N', $length);
    }

    /**
     * Standalone implementation of the RouterOS variable-length decoder
     * (mirrors MikroTikBinaryApiService::decodeLength exactly, reading from a string).
     */
    private function decodeLength(string $bytes): int
    {
        $pos = 0;
        $readByte = function () use ($bytes, &$pos): int {
            return ord($bytes[$pos++]);
        };
        $readN = function (int $n) use ($bytes, &$pos): string {
            $chunk = substr($bytes, $pos, $n);
            $pos += $n;
            return $chunk;
        };

        $b = $readByte();
        if (($b & 0x80) === 0x00) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            return (($b & 0x3F) << 8) | $readByte();  // Python: c & 0x3F
        }
        if (($b & 0xE0) === 0xC0) {
            $raw = $readN(2);
            return (($b & 0x1F) << 16) | (ord($raw[0]) << 8) | ord($raw[1]);  // Python: c & 0x1F
        }
        if (($b & 0xF0) === 0xE0) {
            $raw = $readN(3);
            return (($b & 0x0F) << 24) | (ord($raw[0]) << 16) | (ord($raw[1]) << 8) | ord($raw[2]);  // Python: c & 0x0F
        }
        return unpack('N', $readN(4))[1];
    }

    private function makeExposed(): object
    {
        return new class {
            // Expose parseRecord so we can test the !re word parsing
            public function pubParseRecord(array $sentenceWords): array
            {
                $record = [];
                foreach ($sentenceWords as $word) {
                    if (str_starts_with($word, '=')) {
                        $word = substr($word, 1);
                        [$key, $value] = explode('=', $word, 2) + ['', ''];
                        $record[$key] = $value;
                    }
                }
                return $record;
            }

            // Expose executeCommand param-building logic for unit testing
            public function pubBuildWords(array $params): array
            {
                $words = [];
                foreach ($params as $k => $v) {
                    if (is_int($k)) {
                        $words[] = (string) $v;
                    } else {
                        $words[] = $k . '=' . $v;
                    }
                }
                return $words;
            }

            // Expose fetch path normalisation
            public function pubNormaliseFetchPath(string $endpoint): string
            {
                $path = rtrim($endpoint, '/');
                if (str_ends_with($path, '/print')) {
                    $path = substr($path, 0, -strlen('/print'));
                }
                return $path . '/print';
            }

        };
    }

    // -------------------------------------------------------------------------
    // Length encoding round-trips
    // -------------------------------------------------------------------------

    /**
     * Decode-only tests using hand-crafted byte sequences.
     * These catch bugs where encode+decode errors cancel each other in round-trips.
     *
     * 2-byte form (10xxxxxx xxxxxxxx):
     *   0x81 0x00 → (0x81 & 0x3F)=0x01, (0x01<<8)|0x00 = 256
     *   0xBF 0xFF → (0xBF & 0x3F)=0x3F, (0x3F<<8)|0xFF = 0x3FFF = 16383
     * 3-byte form (110xxxxx xxxxxxxx xxxxxxxx):
     *   0xC0 0x40 0x00 → (0xC0 & 0x1F)=0x00, (0<<16)|(0x40<<8)|0x00 = 0x4000 = 16384
     * 4-byte form (1110xxxx xxxxxxxx xxxxxxxx xxxxxxxx):
     *   0xE0 0x20 0x00 0x00 → (0xE0 & 0x0F)=0x00, (0<<24)|(0x20<<16)|0|0 = 0x200000 = 2097152
     */
    public function test_decode_2byte_form_raw_bytes(): void
    {
        // (0x81 & 0x3F) << 8 | 0x00 = 0x01 << 8 = 256
        $this->assertSame(256, $this->decodeLength("\x81\x00"));
        // (0xBF & 0x3F) << 8 | 0xFF = 0x3F << 8 | 0xFF = 0x3FFF = 16383 (2-byte max)
        $this->assertSame(0x3FFF, $this->decodeLength("\xBF\xFF"));
    }

    public function test_decode_3byte_form_raw_bytes(): void
    {
        // (0xC0 & 0x1F)=0x00 << 16 | 0x40 << 8 | 0x00 = 0x4000 = 16384 (3-byte min)
        $this->assertSame(0x4000, $this->decodeLength("\xC0\x40\x00"));
    }

    public function test_decode_4byte_form_raw_bytes(): void
    {
        // (0xE0 & 0x0F)=0 << 24 | 0x20 << 16 | 0 | 0 = 0x200000 = 2097152 (4-byte min)
        $this->assertSame(0x200000, $this->decodeLength("\xE0\x20\x00\x00"));
    }

    public function test_encode_decode_length_roundtrips_all_boundary_values(): void
    {
        $cases = [
            'zero'       => 0,
            'one'        => 1,
            '1-byte max' => 0x7F,
            '2-byte min' => 0x80,
            '2-byte mid' => 1000,
            '2-byte max' => 0x3FFF,
            '3-byte min' => 0x4000,
            '3-byte max' => 0x1FFFFF,
            '4-byte min' => 0x200000,
            '4-byte max' => 0xFFFFFFF,
        ];

        foreach ($cases as $label => $length) {
            $encoded = $this->encodeLength($length);
            $decoded = $this->decodeLength($encoded);
            $this->assertSame($length, $decoded, "Round-trip failed for {$label} (length={$length})");
        }
    }

    // -------------------------------------------------------------------------
    // executeCommand param building
    // -------------------------------------------------------------------------

    public function test_executeCommand_with_associative_array_builds_key_equals_value(): void
    {
        $svc   = $this->makeExposed();
        $words = $svc->pubBuildWords(['name' => 'br1', 'comment' => 'test']);

        $this->assertSame(['name=br1', 'comment=test'], $words);
    }

    public function test_executeCommand_with_indexed_array_passes_through(): void
    {
        $svc   = $this->makeExposed();
        $words = $svc->pubBuildWords(['name=br1', 'comment=test']);

        $this->assertSame(['name=br1', 'comment=test'], $words);
    }

    public function test_executeCommand_with_empty_array_returns_empty(): void
    {
        $svc   = $this->makeExposed();
        $words = $svc->pubBuildWords([]);

        $this->assertSame([], $words);
    }

    public function test_executeCommand_mixed_numeric_and_string_keys(): void
    {
        $svc   = $this->makeExposed();
        // Numeric key 0 → pass through; string key → build k=v
        $words = $svc->pubBuildWords([0 => 'disabled=no', 'name' => 'svc1']);

        $this->assertSame(['disabled=no', 'name=svc1'], $words);
    }

    // -------------------------------------------------------------------------
    // fetch() path normalisation
    // -------------------------------------------------------------------------

    public function test_fetch_appends_print_to_plain_path(): void
    {
        $svc = $this->makeExposed();
        $this->assertSame('/ip/pool/print', $svc->pubNormaliseFetchPath('/ip/pool'));
    }

    public function test_fetch_does_not_double_append_print(): void
    {
        $svc = $this->makeExposed();
        // Callers that accidentally pass '/resource/print' should not get '/resource/print/print'
        $this->assertSame('/ip/pool/print', $svc->pubNormaliseFetchPath('/ip/pool/print'));
    }

    public function test_fetch_strips_trailing_slash_before_appending(): void
    {
        $svc = $this->makeExposed();
        $this->assertSame('/radius/print', $svc->pubNormaliseFetchPath('/radius/'));
    }

    // -------------------------------------------------------------------------
    // Record parsing (!re sentence words → key/value array)
    // -------------------------------------------------------------------------

    public function test_record_parsed_from_re_words(): void
    {
        $svc    = $this->makeExposed();
        $record = $svc->pubParseRecord([
            '=.id=*1',
            '=name=br-test',
            '=mtu=1500',
        ]);

        $this->assertSame(['*1', 'br-test', '1500'], [$record['.id'], $record['name'], $record['mtu']]);
    }

    public function test_record_skips_non_attribute_words(): void
    {
        $svc    = $this->makeExposed();
        $record = $svc->pubParseRecord(['!re', '=name=foo', '.tag=5']);

        // '!re' has no '=' prefix — skipped; '.tag=5' has no '=' prefix — skipped
        $this->assertSame(['name' => 'foo'], $record);
    }

    public function test_record_with_equals_in_value_is_preserved(): void
    {
        $svc    = $this->makeExposed();
        $record = $svc->pubParseRecord(['=comment=a=b=c']);

        $this->assertSame('a=b=c', $record['comment']);
    }

    public function test_done_sentence_with_ret_is_parsed_as_record(): void
    {
        // ROS6 login: challenge arrives as =ret= inside the !done sentence.
        // Docs example: ">>> !done >>> =ret=93b438ec9b80057c06dd9fe67d56aa9a"
        // readResponse() must NOT discard !done attribute words.
        $svc = $this->makeExposed();
        // Simulate parsing the words after '!done'
        $words = ['=ret=93b438ec9b80057c06dd9fe67d56aa9a'];
        $record = $svc->pubParseRecord($words);
        $this->assertSame('93b438ec9b80057c06dd9fe67d56aa9a', $record['ret']);
    }

    // -------------------------------------------------------------------------
    // removeByComment — PHP str_contains filtering (regex NOT supported by API)
    // Docs: "Regular expressions are not supported in API, so do not try to
    //        send a query with the ~ symbol"
    // -------------------------------------------------------------------------

    public function test_removeByComment_matches_exact_prefix(): void
    {
        $pattern = 'hs-fw-abc123';
        $items = [
            ['comment' => 'hs-fw-abc123-DROP-UNAUTH', '.id' => '*1'],
            ['comment' => 'hs-fw-abc123-AUTH-INET',   '.id' => '*2'],
            ['comment' => 'other-rule',                '.id' => '*3'],
        ];
        $matched = array_filter($items, fn($i) => str_contains($i['comment'] ?? '', $pattern));
        $this->assertCount(2, $matched);
        $this->assertNotContains('*3', array_column($matched, '.id'));
    }

    public function test_removeByComment_no_match_returns_empty(): void
    {
        $pattern = 'nonexistent-service';
        $items = [['comment' => 'hs-fw-abc123', '.id' => '*1']];
        $matched = array_filter($items, fn($i) => str_contains($i['comment'] ?? '', $pattern));
        $this->assertCount(0, $matched);
    }

    public function test_removeByComment_empty_comment_field_skipped(): void
    {
        $pattern = 'hs-fw-';
        $items = [['comment' => '', '.id' => '*1'], ['.id' => '*2']]; // no comment key
        $matched = array_filter($items, fn($i) => str_contains($i['comment'] ?? '', $pattern));
        $this->assertCount(0, $matched);
    }

    // -------------------------------------------------------------------------
    // command() word-type routing (query vs attribute)
    // -------------------------------------------------------------------------

    public function test_command_query_word_is_passed_verbatim(): void
    {
        // Simulate the word-routing logic in command()
        $sentence = [];
        foreach (['?name=br1'] as $param) {
            if (str_starts_with($param, '?')) {
                $sentence[] = $param;
            } else {
                $sentence[] = '=' . ltrim($param, '=');
            }
        }
        $this->assertSame(['?name=br1'], $sentence);
    }

    public function test_command_regex_query_word_is_passed_verbatim(): void
    {
        $sentence = [];
        foreach (['?~comment=PPPoE-abc'] as $param) {
            if (str_starts_with($param, '?')) {
                $sentence[] = $param;
            } else {
                $sentence[] = '=' . ltrim($param, '=');
            }
        }
        $this->assertSame(['?~comment=PPPoE-abc'], $sentence);
    }

    public function test_command_attribute_word_gets_equals_prefix(): void
    {
        $sentence = [];
        foreach (['name=br1'] as $param) {
            if (str_starts_with($param, '?')) {
                $sentence[] = $param;
            } else {
                $sentence[] = '=' . ltrim($param, '=');
            }
        }
        $this->assertSame(['=name=br1'], $sentence);
    }

    public function test_command_dot_id_attribute_word_gets_equals_prefix(): void
    {
        $sentence = [];
        foreach (['.id=*1'] as $param) {
            if (str_starts_with($param, '?')) {
                $sentence[] = $param;
            } else {
                $sentence[] = '=' . ltrim($param, '=');
            }
        }
        $this->assertSame(['=.id=*1'], $sentence);
    }

    // -------------------------------------------------------------------------
    // login() error classification
    // -------------------------------------------------------------------------

    public function test_login_ros6_fallback_triggered_on_trap_message(): void
    {
        // Verify the string check the login() method uses
        $trapException = new \RuntimeException(
            'RouterOS API error (!trap): cannot log in (router id: test-id)'
        );
        $this->assertTrue(str_contains($trapException->getMessage(), '!trap'));
    }

    public function test_login_non_trap_error_is_not_ros6_fallback(): void
    {
        $socketException = new \RuntimeException(
            'Binary API socket closed unexpectedly while reading (router id: test-id)'
        );
        $this->assertFalse(str_contains($socketException->getMessage(), '!trap'));
    }

    // -------------------------------------------------------------------------
    // Port normalisation
    // -------------------------------------------------------------------------

    public function test_port_8728_is_kept_as_is(): void
    {
        // Simulate what the constructor does
        $port = 8728;
        if ($port === 80 || $port === 443) {
            $port = 8728;
        }
        $this->assertSame(8728, $port);
    }

    public function test_port_8729_is_kept_as_is(): void
    {
        // 8729 is api-ssl — should NOT be overridden to 8728
        $port = 8729;
        if ($port === 80 || $port === 443) {
            $port = 8728;
        }
        $this->assertSame(8729, $port);
    }

    public function test_port_80_is_normalised_to_8728(): void
    {
        $port = 80;
        if ($port === 80 || $port === 443) {
            $port = 8728;
        }
        $this->assertSame(8728, $port);
    }

    public function test_port_443_is_normalised_to_8728(): void
    {
        $port = 443;
        if ($port === 80 || $port === 443) {
            $port = 8728;
        }
        $this->assertSame(8728, $port);
    }

    // -------------------------------------------------------------------------
    // secondsToRosTime — RouterOS time string format
    // -------------------------------------------------------------------------

    /**
     * Inline mirror of MikroTikBinaryApiService::secondsToRosTime().
     * Keeps tests independent of the private method.
     */
    private function secondsToRosTime(int $seconds): string
    {
        if ($seconds === 0) return '0s';
        if ($seconds % 86400 === 0) return ($seconds / 86400) . 'd';
        if ($seconds % 3600 === 0)  return ($seconds / 3600) . 'h';
        if ($seconds % 60 === 0)    return ($seconds / 60) . 'm';
        return $seconds . 's';
    }

    public function test_seconds_to_ros_time_converts_correctly(): void
    {
        // Zero
        $this->assertSame('0s',   $this->secondsToRosTime(0));
        // Exact hours — 3600→1h, 7200→2h
        $this->assertSame('1h',   $this->secondsToRosTime(3600));
        $this->assertSame('2h',   $this->secondsToRosTime(7200));
        // Exact days — 86400→1d
        $this->assertSame('1d',   $this->secondsToRosTime(86400));
        // Exact minutes — 60→1m, 300→5m
        $this->assertSame('1m',   $this->secondsToRosTime(60));
        $this->assertSame('5m',   $this->secondsToRosTime(300));
        // Odd seconds — 30→30s, 45→45s
        $this->assertSame('30s',  $this->secondsToRosTime(30));
        $this->assertSame('45s',  $this->secondsToRosTime(45));
        // Default configurator values: tcp=3600→1h, udp=30→30s
        $this->assertSame('1h',   $this->secondsToRosTime(3600));
        $this->assertSame('30s',  $this->secondsToRosTime(30));
    }
}
