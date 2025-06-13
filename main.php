<?php
require __DIR__ . '/vendor/autoload.php';

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

$config = require __DIR__ . '/config.php';

try {
    $telegram = new Telegram($config['api_key'], $config['bot_username']);

    $telegram->enableMySql($config['mysql']);

    $telegram->addCommandsPath(__DIR__ . '/Commands/UserCommands/');
    $telegram->addCommandsPath(__DIR__ . '/Commands/SystemCommands/');

    $telegram->handle();
} catch (TelegramException $e) {
    echo $e->getMessage();
}