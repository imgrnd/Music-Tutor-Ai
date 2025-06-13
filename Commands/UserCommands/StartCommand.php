<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Запуск бота и основное меню';
    protected $usage = '/start';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();

        // Сброс состояния клиента
        $pdo = DB::getPdo();
        $stmt = $pdo->prepare('DELETE FROM user_states WHERE user_id = ?');
        $stmt->execute([$user_id]);

        $text = 'Привет! Выбери действие:';

        $keyboard = new Keyboard(
            ['📚 Домашние задания'],
            ['🤖 АИ репетитор']
        );
        $keyboard
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);

        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => $text,
            'reply_markup' => $keyboard,
        ]);
    }
} 