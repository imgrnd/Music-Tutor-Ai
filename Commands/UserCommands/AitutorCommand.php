<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\UserState; // Используем UserState

class AitutorCommand extends UserCommand
{
    protected $name = 'aitutor'; // Название команды, которое будем вызывать
    protected $description = 'Режим AI Репетитора';
    protected $usage = 'Нажмите кнопку "📚 AI Репетитор"';
    protected $version = '1.0.0';
    protected $private_only = true; // Только для личных чатов

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();

        // Устанавливаем состояние пользователя в 'aitutor'
        UserState::set($user_id, $chat_id, $this->getName()); // this->getName() вернет 'aitutor'

        // Специальная клавиатура для режима AI Репетитора
        $aitutor_keyboard = new Keyboard(
            ['🔙 Выйти из режима AI'] // Кнопка выхода
        );
        $aitutor_keyboard
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(false);

        $text = "Вы вошли в режим AI Репетитора. Теперь можете задавать мне любые вопросы по музыке.";

        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text'    => $text,
            'reply_markup' => $aitutor_keyboard, // Показываем клавиатуру режима AI
        ]);
    }
}
