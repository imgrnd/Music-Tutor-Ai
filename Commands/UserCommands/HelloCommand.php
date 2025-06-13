<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class HelloCommand extends UserCommand
{
    protected $name = 'hello';
    protected $description = 'Приветствие';
    protected $usage = '/hello';
    protected $version = '1.1.0';

    public function execute(): ServerResponse
    {
        $chat_id = $this->getMessage()->getChat()->getId();
        $text = 'Привет! Я музыкальный бот. Напиши /start для меню.';

        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => $text
        ]);
    }
}