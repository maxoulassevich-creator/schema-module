<?php
namespace Custom\SmartSchema;

class Logger
{
    public static function info(string $message, array $data = [], ?int $proposalId = null): void
    {
        Db::log($proposalId, 'info', $message, $data);
    }

    public static function error(string $message, array $data = [], ?int $proposalId = null): void
    {
        Db::log($proposalId, 'error', $message, $data);
    }
}
