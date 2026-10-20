<?php

declare(strict_types=1);

namespace BlindTyper\Tests\Unit;

use BlindTyper\Domain\Rules;
use BlindTyper\Domain\Scoring;
use BlindTyper\Http\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoringTest extends TestCase
{
    public static function comboCases(): array
    {
        return [[19, 19], [20, 21], [49, 79], [50, 82], [99, 229], [100, 234]];
    }

    #[DataProvider('comboCases')]
    public function testComboThreshold(int $count, int $expected): void
    {
        $s = new Scoring();
        $events = [];
        for ($i = 1; $i <= $count; $i++) {
            $events[] = ['seq' => $i, 'at_ms' => $i * 1000, 'char' => 'а'];
        }
        $r = $s->calculate(str_repeat('а', $count), $s->events($events), [], 100000)['result'];
        self::assertSame($expected, $r['xp_earned']);
        self::assertEquals(60, $r['cpm']);
        self::assertEquals(12, $r['wpm']);
    }

    public function testUnicodeErrorsAndErrorAttribution(): void
    {
        $s = new Scoring();
        $chars = ['x', 'Ё', 'ё', 'A', 'a', '1', ' ', '\n'];
        $events = [];
        foreach ($chars as $i => $char) {
            $events[] = ['seq' => $i + 1, 'at_ms' => ($i + 1) * 1000, 'char' => $char === '\n' ? "\n" : $char];
        }
        $score = $s->calculate("ЁёAa1 \n", $s->events($events), [], 10000);
        self::assertSame(7, $score['result']['correct']);
        self::assertSame(1, $score['result']['errors']);
        self::assertEquals(87.5, $score['result']['accuracy']);
        self::assertSame(['char' => 'Ё', 'attempts' => 2, 'errors' => 1], $score['character_stats'][0]);
        self::assertSame('1', $score['character_stats'][4]['char']);
    }

    public function testErrorResetsCombo(): void
    {
        $s = new Scoring();
        $events = [];
        foreach (mb_str_split(str_repeat('а', 20) . 'xа') as $i => $char) {
            $events[] = ['seq' => $i + 1, 'at_ms' => ($i + 1) * 1000, 'char' => $char];
        }
        $r = $s->calculate(str_repeat('а', 21), $s->events($events), [], 30000)['result'];
        self::assertSame(22, $r['xp_earned']);
        self::assertSame(20, $r['max_combo']);
        self::assertSame(1, $r['errors']);
    }

    public function testBonusIntervalIsHalfOpen(): void
    {
        $s = new Scoring();
        $e = $s->events([['seq' => 1, 'at_ms' => 999, 'char' => 'a'], ['seq' => 2, 'at_ms' => 1000, 'char' => 'a'], ['seq' => 3, 'at_ms' => 2000, 'char' => 'a']]);
        $r = $s->calculate('aaa', $e, [['type' => 'double_xp', 'starts_at_ms' => 1000, 'ends_at_ms' => 2000]], 3000);
        self::assertSame(4, $r['result']['xp_earned']);
        self::assertSame(['double_xp'], $r['result']['bonuses_applied']);
    }

    public function testSpeedBonusAddsHalfXpButDoesNotAlterMeasuredSpeed(): void
    {
        $scoring = new Scoring();
        $events = $scoring->events([
            ['seq' => 1, 'at_ms' => 1000, 'char' => 'a'],
            ['seq' => 2, 'at_ms' => 2000, 'char' => 'a'],
            ['seq' => 3, 'at_ms' => 3000, 'char' => 'a'],
        ]);
        $bonus = [['type' => 'speed_boost', 'starts_at_ms' => 1000, 'ends_at_ms' => 3000]];
        $result = $scoring->calculate('aaa', $events, $bonus, 3000)['result'];
        self::assertSame(4, $result['xp_earned']);
        self::assertSame(['speed_boost'], $result['bonuses_applied']);
        self::assertSame(60.0, $result['cpm']);
        self::assertSame(12.0, $result['wpm']);
    }

    public static function invalidEvents(): array
    {
        return [[null], [[]], [(object) ['1' => ['seq' => 1, 'at_ms' => 1, 'char' => 'a']]], [[['seq' => 2, 'at_ms' => 1, 'char' => 'a']]],
            [[['seq' => 1, 'at_ms' => -1, 'char' => 'a']]], [[['seq' => 1, 'at_ms' => 0, 'char' => 'a']]],
            [[['seq' => 1, 'at_ms' => 1.1, 'char' => 'a']]], [[['seq' => 1, 'at_ms' => 1, 'char' => 'ab']]],
            [[['seq' => 1, 'at_ms' => 1, 'char' => "\t"]]], [[['seq' => 1, 'at_ms' => 1800001, 'char' => 'a']]],
            [[['seq' => 1, 'at_ms' => 2, 'char' => 'a'], ['seq' => 2, 'at_ms' => 1, 'char' => 'b']]]];
    }

    #[DataProvider('invalidEvents')]
    public function testRejectsMalformedEvents(mixed $events): void
    {
        $this->expectException(ApiException::class);
        (new Scoring())->events($events);
    }

    public function testIncompleteTextRejected(): void
    {
        $s = new Scoring();
        $this->expectException(ApiException::class);
        $s->calculate('ab', $s->events([['seq' => 1, 'at_ms' => 1, 'char' => 'a']]), [], 10);
    }

    public function testExtraInputRejected(): void
    {
        $s = new Scoring();
        $this->expectException(ApiException::class);
        $s->calculate('a', $s->events([['seq' => 1, 'at_ms' => 1, 'char' => 'a'], ['seq' => 2, 'at_ms' => 2, 'char' => 'b']]), [], 10);
    }

    public function testFutureClientTimeRejected(): void
    {
        $s = new Scoring();
        $this->expectException(ApiException::class);
        $s->calculate('a', $s->events([['seq' => 1, 'at_ms' => 2001, 'char' => 'a']]), [], 0);
    }

    public function testPropertyOrderDoesNotAffectHash(): void
    {
        $s = new Scoring();
        self::assertSame($s->hash($s->events([['seq' => 1, 'at_ms' => 1, 'char' => 'a']])), $s->hash($s->events([(object) ['char' => 'a', 'at_ms' => 1, 'seq' => 1]])));
    }

    public static function levels(): array
    {
        return [[0, 1, 100], [99, 1, 100], [100, 2, 400], [399, 2, 400], [400, 3, 900], [900, 4, 1600]];
    }

    #[DataProvider('levels')]
    public function testLevels(int $xp, int $level, int $next): void
    {
        self::assertSame(['total_xp' => $xp, 'level' => $level, 'next_level_xp' => $next], Rules::progress($xp));
    }

    public function testCatalogReportsModeRequirements(): void
    {
        $newPlayer = Rules::catalog(0)['modes'];
        self::assertSame([1, 2, 3, 4], array_column($newPlayer, 'required_level'));
        self::assertSame([true, false, false, false], array_column($newPlayer, 'unlocked'));
        self::assertSame([true, true, true, true], array_column(Rules::catalog(900)['modes'], 'unlocked'));
    }

    public function testOverspeedIsUnranked(): void
    {
        $s = new Scoring();
        $events = [];
        for ($i = 1; $i <= 300; $i++) {
            $events[] = ['seq' => $i, 'at_ms' => 10000, 'char' => 'a'];
        }
        $r = $s->calculate(str_repeat('a', 300), $s->events($events), [], 10000)['result'];
        self::assertFalse($r['ranked']);
        self::assertSame('SPEED_LIMIT', $r['unranked_reason']);
    }

    public function testCodeRequiresEnglishLayout(): void
    {
        $this->expectException(ApiException::class);
        Rules::options(['mode' => 'code', 'language' => 'ru', 'difficulty' => 'easy', 'length' => 120, 'code_language' => 'python']);
    }
}
