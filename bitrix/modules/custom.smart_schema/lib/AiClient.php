<?php
namespace Custom\SmartSchema;

class AiClient
{
    public function isReady(): bool
    {
        return Options::get('ai_enabled') === 'Y' && Options::get('openai_api_key') !== '';
    }

    public function analyze(array $context): array
    {
        if (!$this->isReady()) {
            return ['ok' => false, 'error' => 'AI-анализ выключен или API-ключ не задан.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP-расширение cURL недоступно.'];
        }
        $payload = [
            'model' => Options::get('openai_model', 'gpt-5.5'),
            'instructions' => $this->instructions(),
            'input' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'max_output_tokens' => 8000,
            'text' => ['format' => ['type' => 'json_object']],
        ];
        if (preg_match('/^(gpt-5|o\d)/i', $payload['model'])) {
            $payload['reasoning'] = ['effort' => 'low'];
        }
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . Options::get('openai_api_key')],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)Options::get('ai_timeout', '90'),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if (Options::get('proxy_enabled') === 'Y') {
            $host = Options::get('proxy_host');
            $port = Options::get('proxy_port');
            if ($host === '' || $port === '') {
                curl_close($ch);
                return ['ok' => false, 'error' => 'Прокси включён, но хост или порт не заполнены.'];
            }
            curl_setopt($ch, CURLOPT_PROXY, $host . ':' . $port);
            if (defined('CURLPROXY_SOCKS5_HOSTNAME')) { curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME); }
            elseif (defined('CURLPROXY_SOCKS5')) { curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5); }
            $login = Options::get('proxy_login');
            $password = Options::get('proxy_password');
            if ($login !== '' || $password !== '') { curl_setopt($ch, CURLOPT_PROXYUSERPWD, $login . ':' . $password); }
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $err) { return ['ok' => false, 'error' => 'Ошибка запроса: ' . $err, 'code' => $code]; }
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) { return ['ok' => false, 'error' => 'OpenAI вернул не JSON-ответ.', 'raw' => mb_substr((string)$raw, 0, 2000), 'code' => $code]; }
        if ($code < 200 || $code >= 300) { return ['ok' => false, 'error' => $body['error']['message'] ?? ('HTTP ' . $code), 'body' => $body, 'code' => $code]; }
        $text = $this->outputText($body);
        $parsed = json_decode($text, true);
        return ['ok' => is_array($parsed), 'raw' => $text, 'items' => $parsed['items'] ?? [], 'summary' => $parsed['summary'] ?? '', 'body' => $body];
    }

    public function test(): array
    {
        return $this->analyze(['test' => true, 'instruction' => 'Return valid JSON.']);
    }

    private function outputText(array $body): string
    {
        if (!empty($body['output_text']) && is_string($body['output_text'])) { return $body['output_text']; }
        $text = '';
        foreach ((array)($body['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $content) {
                if (isset($content['text'])) { $text .= (string)$content['text']; }
            }
        }
        return $text;
    }

    private function instructions(): string
    {
        return 'Ты проверяешь предложения Schema.org для сайта на 1C-Битрикс. Верни только JSON вида {"summary":"...","items":[]}. Нельзя придумывать факты. Учитывай, что модуль должен работать по типовым шаблонам страниц, а не сканировать все товары.';
    }
}
