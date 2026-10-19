<?php

declare(strict_types=1);

namespace BlindTyper\Tests\Integration;

use BlindTyper\Http\ApiException;

final class TrainingTest extends DatabaseTestCase
{
    public function testFinishIsIdempotentAndAchievementsAreUnique(): void
    {
        $id = $this->player();
        $s = $this->create($id);
        $result = $this->complete($id, $s);
        $again = $this->training->finish($id, $s['public_id'], ['events' => self::events($s['text'])]);
        self::assertEquals($result, $again);
        self::assertTrue($result['result']['ranked']);
        self::assertSame($result['result']['xp_earned'], (int) $this->auth->user($id)['total_xp']);
        self::assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM user_achievements')['n']);
        self::assertSame(mb_strlen($s['text']), (int) $this->db->one('SELECT SUM(attempts) n FROM session_character_stats')['n']);
        $events = self::events($s['text']);
        $events[0]['at_ms']++;
        try {
            $this->training->finish($id, $s['public_id'], ['events' => $events]);
            self::fail('Expected conflict');
        } catch (ApiException $e) {
            self::assertSame('RESULT_CONFLICT', $e->errorCode);
        }
    }

    public function testOwnershipAndLockedModes(): void
    {
        $id = $this->player();
        $other = $this->player('other@example.org');
        $s = $this->create($id);
        try {
            $this->training->start($other, $s['public_id']);
            self::fail('Expected 404');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        try {
            $this->create($id, ['mode' => 'code', 'language' => 'en', 'code_language' => 'python']);
            self::fail('Expected locked');
        } catch (ApiException $e) {
            self::assertSame('MODE_LOCKED', $e->errorCode);
        }
        $this->db->execute('UPDATE users SET total_xp=900 WHERE id=?', [$id]);
        $code = $this->create($id, ['mode' => 'code', 'language' => 'en', 'code_language' => 'python']);
        self::assertStringContainsString("\n", $code['text']);
        $this->complete($id, $code);
        self::assertSame('finished', $this->sessions->find($code['public_id'], $id)['status']);
    }

    public function testAllModeLanguageDifficultyAndCodeSelectionsHaveContent(): void
    {
        $id = $this->player();
        $this->db->execute('UPDATE users SET total_xp=900 WHERE id=?', [$id]);
        foreach (['words', 'sentences', 'symbols', 'code'] as $mode) {
            foreach ($mode === 'code' ? ['en'] : ['ru', 'en'] as $language) {
                foreach (['easy', 'medium', 'hard'] as $difficulty) {
                    foreach ([120, 240, 1200] as $length) {
                        foreach ($mode === 'code' ? ['python', 'javascript', 'html', 'css'] : [null] as $code) {
                            try {
                                $s = $this->create($id, ['mode' => $mode, 'language' => $language, 'difficulty' => $difficulty, 'length' => $length, 'code_language' => $code]);
                            } catch (ApiException $e) {
                                self::fail(json_encode([$mode, $language, $difficulty, $length, $code]) . ': ' . $e->getMessage());
                            }
                            self::assertGreaterThanOrEqual($length === 1200 ? 1000 : (int) floor($length * .8), $s['text_length']);
                            self::assertLessThanOrEqual((int) ceil($length * 1.2), $s['text_length']);
                        }
                    }
                }
            }
        }
    }

    public function testAbandonStartRetryExpiryAndDeliveryGrace(): void
    {
        $id = $this->player();
        $old = $this->create($id);
        $s = $this->create($id);
        self::assertSame('abandoned', $this->sessions->find($old['public_id'], $id)['status']);
        $start = $this->training->start($id, $s['public_id']);
        $this->clock->advance('+1 minute');
        self::assertSame($start, $this->training->start($id, $s['public_id']));
        $new = $this->create($id);
        $this->clock->advance('+11 minutes');
        try {
            $this->training->start($id, $new['public_id']);
            self::fail('Expected expired');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame('expired', $this->sessions->find($new['public_id'], $id)['status']);
        $s = $this->create($id);
        $this->training->start($id, $s['public_id']);
        $this->clock->advance('+30 minutes 30 seconds');
        self::assertSame('finished', $this->training->finish($id, $s['public_id'], ['events' => self::events($s['text'], 1800000)])['status']);
        $s = $this->create($id);
        $this->training->start($id, $s['public_id']);
        $this->clock->advance('+31 minutes 1 second');
        try {
            $this->training->finish($id, $s['public_id'], ['events' => self::events($s['text'])]);
            self::fail('Expected expired');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame('expired', $this->sessions->find($s['public_id'], $id)['status']);
    }

    public function testFailureRollsBackAllProgressAndAllowsRetry(): void
    {
        $id = $this->player();
        $s = $this->create($id);
        $this->training->start($id, $s['public_id']);
        $this->clock->advance('+1 minute');
        $this->db->pdo->exec("ALTER TABLE training_sessions ADD CONSTRAINT test_reject_finished CHECK (status <> 'finished')");
        try {
            try {
                $this->training->finish($id, $s['public_id'], ['events' => self::events($s['text'])]);
                self::fail('Expected DB error');
            } catch (\PDOException $e) {
                self::assertSame(3819, $e->errorInfo[1]);
            }
            self::assertSame(0, (int) $this->auth->user($id)['total_xp']);
            self::assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM session_character_stats')['n']);
            self::assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM user_achievements')['n']);
            self::assertSame('started', $this->sessions->find($s['public_id'], $id)['status']);
        } finally {
            $this->db->pdo->exec('ALTER TABLE training_sessions DROP CHECK test_reject_finished');
        }
        self::assertSame('finished', $this->training->finish($id, $s['public_id'], ['events' => self::events($s['text'])])['status']);
    }

    public function testConcurrentFinishesAwardOnce(): void
    {
        $id = $this->player();
        $s = $this->create($id);
        $this->training->start($id, $s['public_id']);
        $payload = json_encode(['user_id' => $id, 'public_id' => $s['public_id'], 'events' => self::events($s['text'])], JSON_THROW_ON_ERROR);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/finish-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $responses = [];
        foreach ($workers as [$process,$pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err . $out);
            $responses[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        self::assertEquals($responses[0], $responses[1]);
        self::assertSame($responses[0]['result']['xp_earned'], (int) $this->auth->user($id)['total_xp']);
    }

    public function testWeightedStatsLeaderboardFiltersAndDemoExclusion(): void
    {
        $id = $this->player();
        $s = $this->create($id);
        $first = $this->complete($id, $s, 60000);
        $this->clock->advance('+1 day');
        $s = $this->create($id);
        $second = $this->complete($id, $s, 30000);
        $other = $this->player('demo@example.org');
        $this->db->execute('UPDATE users SET is_demo=1 WHERE id=?', [$other]);
        $demo = $this->create($other);
        $this->complete($other, $demo, 10000);
        $stats = $this->read->stats($id, 3);
        self::assertSame(2, $stats['totals']['training_count']);
        self::assertSame(90000, $stats['totals']['training_time_ms']);
        self::assertCount(3, $stats['daily']);
        self::assertNull($stats['daily'][0]['cpm']);
        foreach (['cpm', 'accuracy', 'xp'] as $metric) {
            $board = $this->read->leaderboard($metric, 'all', null, null, 1, 20);
            self::assertSame(1, $board['total']);
            self::assertSame($id, $board['items'][0]['user']['id']);
        }
        $today = $this->read->leaderboard('xp', 'today', 'words', 'ru', 1, 20);
        self::assertSame($second['result']['xp_earned'], $today['items'][0]['value']);
        self::assertSame(0, $this->read->leaderboard('cpm', 'week', 'code', null, 1, 20)['total']);
        self::assertCount(0, $this->read->leaderboard('cpm', 'all', null, null, 2, 20)['items']);
        self::assertCount(2, $this->sessions->history($id, 1, 20)['items']);
    }

    public function testMalformedFinishLeavesStartedAndLongStreakAwardsAchievement(): void
    {
        $id = $this->player();
        $s = $this->create($id, ['length' => 1200]);
        $this->training->start($id, $s['public_id']);
        try {
            $this->training->finish($id, $s['public_id'], ['events' => [['seq' => 1, 'at_ms' => 1, 'char' => 'X']]]);
            self::fail('Expected incomplete');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame('started', $this->sessions->find($s['public_id'], $id)['status']);
        $this->clock->advance('+10 minutes');
        $r = $this->training->finish($id, $s['public_id'], ['events' => self::events($s['text'], 600000)]);
        self::assertContains('streak_1000', array_column($r['new_achievements'], 'code'));
    }

    public function testWeightedMetricsAndExpectedCharacterAnalysis(): void
    {
        $id = $this->player();
        $first = $this->create($id);
        $this->training->start($id, $first['public_id']);
        $this->clock->advance('+1 minute');
        $events = self::events($first['text']);
        array_unshift($events, ['seq' => 0, 'at_ms' => 0, 'char' => '!']);
        foreach ($events as $i => &$event) {
            $event['seq'] = $i + 1;
        }
        unset($event);
        $this->training->finish($id, $first['public_id'], ['events' => $events]);
        $second = $this->create($id);
        $this->complete($id, $second, 30000);
        $correct = $first['text_length'] + $second['text_length'];
        $stats = $this->read->stats($id, 1);
        self::assertSame(round($correct * 60000 / 90000, 2), $stats['daily'][0]['cpm']);
        self::assertSame(round(100 * $correct / ($correct + 1), 2), $stats['totals']['accuracy']);
        $expected = mb_substr($first['text'], 0, 1);
        $byChar = array_column($stats['difficult_characters'], null, 'char');
        self::assertSame(1, $byChar[$expected]['errors']);
        self::assertSame(round(100 / $byChar[$expected]['attempts'], 2), $byChar[$expected]['error_rate']);
        self::assertSame($correct + 1, (int) $this->db->one('SELECT SUM(attempts) n FROM session_character_stats')['n']);
    }

    public function testLeaderboardsChooseBestSessionAndResolveUserTies(): void
    {
        $first = $this->player();
        $second = $this->player('second@example.org');
        $best = $this->complete($first, $this->create($first), 60000);
        $this->complete($first, $this->create($first), 120000);
        $this->complete($second, $this->create($second), 60000);
        foreach (['cpm', 'accuracy'] as $metric) {
            $board = $this->read->leaderboard($metric, 'all', 'words', 'ru', 1, 20);
            self::assertSame([$first, $second], array_column(array_column($board['items'], 'user'), 'id'));
            self::assertSame($best['result']['cpm'], $board['items'][0]['cpm']);
        }
        $page = $this->read->leaderboard('cpm', 'all', null, null, 2, 1);
        self::assertSame(2, $page['total']);
        self::assertSame(2, $page['items'][0]['rank']);
        self::assertSame($second, $page['items'][0]['user']['id']);
    }
}
