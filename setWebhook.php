<?php
require __DIR__ . '/vendor/autoload.php';

use Longman\TelegramBot\Telegram;

$api_key = '7152289410:AAFEYVmWcfzS4JENBXOWguBoqcryaubxgM0';
$bot_username = 'MusicTutor_ai_bot';
$hook_url = 'https://bot.egosmoke.shop/music-teacher/main.php';

try {
    $telegram = new Telegram($api_key, $bot_username);
    $result = $telegram->setWebhook($hook_url);
    if ($result->isOk()) {
        echo $result->getDescription();
    } else {
        echo $result->getDescription();
    }
} catch (Exception $e) {
    echo $e->getMessage();
}