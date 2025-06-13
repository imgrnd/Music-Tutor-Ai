<?php
header('Content-Type: application/json');

try {
    $input_data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON received: ' . json_last_error_msg());
    }

    $apiKey = $input_data['api_key'] ?? null;
    if (!$apiKey) {
        throw new \Exception('API key is missing.');
    }

    // Подготовка данных запроса (ваша существующая логика)
    $contents = [];
    $generationConfig = [
        "maxOutputTokens" => $input_data['max_tokens'] ?? 1024,
        "temperature" => $input_data['temperature'] ?? 0.5,
    ];

    if (isset($input_data['image_url'])) {
        $imageData = @file_get_contents($input_data['image_url']);
        if ($imageData === false) {
            throw new \Exception('Ошибка загрузки изображения.');
        }
        $base64Image = base64_encode($imageData);

        $user_message_content = '';
        if (isset($input_data['messages'])) {
            foreach ($input_data['messages'] as $message) {
                if ($message['role'] === 'system') {
                    $user_message_content = $message['content'] . "\n\n";
                } elseif ($message['role'] === 'user') {
                    $user_message_content .= $message['content'];
                }
            }
        }

        $user_parts = [
            [
                "inline_data" => [
                    "mime_type" => "image/jpeg",
                    "data" => $base64Image
                ]
            ]
        ];

        if (!empty($user_message_content)) {
            $user_parts[] = ['text' => $user_message_content];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => $user_parts
        ];
    } elseif (isset($input_data['messages'])) {
        $combined_messages = [];
        $system_prompt_text = '';

        foreach ($input_data['messages'] as $message) {
            if ($message['role'] === 'system') {
                $system_prompt_text = $message['content'] . "\n\n";
            } else {
                if ($message['role'] === 'user') {
                    $message['content'] = $system_prompt_text . $message['content'];
                    $system_prompt_text = '';
                }
                $combined_messages[] = $message;
            }
        }

        foreach ($combined_messages as $message) {
            $contents[] = [
                'role' => $message['role'],
                'parts' => [
                    ['text' => $message['content']]
                ]
            ];
        }
    } else {
        throw new \Exception('Invalid input: missing image_url or messages.');
    }

    // Формирование конечного URL для проксирования
    $proxy_url = 'https://www.s267921.h1n.ru/proxy.php/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey);
    
    // Подготовка данных для отправки
    $postData = json_encode([
        "contents" => $contents,
        "generationConfig" => $generationConfig
    ]);

    // Отправка через прокси
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            'content' => $postData
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($proxy_url, false, $context);

    if ($response === false) {
        throw new \Exception('Ошибка запроса через прокси');
    }

    // Передаем оригинальные заголовки ответа
    foreach ($http_response_header as $header) {
        if (strpos($header, 'Content-Type:') === 0) {
            header($header);
        }
    }

    echo $response;

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => $e->getMessage()]]);
}