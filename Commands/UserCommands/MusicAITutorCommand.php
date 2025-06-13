<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\Keyboard;

class MusicAITutorCommand extends UserCommand
{
    protected $name = 'musicaitutor';
    protected $description = 'Переход в режим репетитора'; 
    protected $usage = '🤖 АИ репетитор';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();

        // Устанавливаем состояние пользователя в 'ai_tutor'
        $pdo = DB::getPdo();
        $stmt = $pdo->prepare('INSERT INTO user_states (user_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state)');
        $stmt->execute([$user_id, 'ai_tutor']);

        $text = "🤖 Вы перешли в режим АИ репетитора. Теперь каждое ваше сообщение будет отправлено нейросети.\n\nЧтобы выйти из этого режима и вернуться в главное меню, нажмите кнопку ниже или отправьте команду /start.";


        $keyboard = new Keyboard(
            ['Выйти из АИ режима']
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