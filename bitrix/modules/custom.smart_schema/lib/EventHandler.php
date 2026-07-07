<?php
namespace Custom\SmartSchema;

class EventHandler
{
    public static function onEndBufferContent(&$content): void
    {
        try {
            (new Output())->inject($content);
        } catch (\Throwable $e) {
            try { Db::log(null, 'output_error', 'Ошибка вывода JSON-LD: ' . $e->getMessage()); } catch (\Throwable $ignored) {}
        }
    }
}
