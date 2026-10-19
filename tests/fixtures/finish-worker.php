<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use BlindTyper\Repository\Database;
use BlindTyper\Repository\UserRepository;
use BlindTyper\Repository\TrainingRepository;
use BlindTyper\Domain\Scoring;
use BlindTyper\Service\TrainingService;
use BlindTyper\Support\Config;
use BlindTyper\Tests\FrozenClock;

$config = Config::fromEnvironment(dirname(__DIR__, 2));
if ($config->environment !== 'test' || !str_ends_with($config->dbName, '_test')) {
    throw new RuntimeException('Test database required');
}
$db = new Database($config->connect());
$clock = new FrozenClock();
$clock->advance('+1 minute');
$service = new TrainingService($db, new UserRepository($db), new TrainingRepository($db), new Scoring(), $clock);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode($service->finish($input['user_id'], $input['public_id'], ['events' => $input['events']]), JSON_THROW_ON_ERROR);
