<?php
namespace Custom\SmartSchema;

use Bitrix\Main\Application;

class Db
{
    public const PROPOSALS = 'b_custom_smart_schema_proposals';
    public const LOGS = 'b_custom_smart_schema_logs';

    private static bool $installed = false;

    public static function install(): void
    {
        // Проверка/миграция схемы (isTableExists + SHOW COLUMNS) не должна выполняться на каждый
        // вызов: activeForKind()/activeForRequest() дёргаются на каждой странице сайта через
        // main:OnEndBufferContent. Достаточно один раз за запрос.
        if (self::$installed) { return; }
        $connection = Application::getConnection();
        if (!$connection->isTableExists(self::PROPOSALS)) {
            $connection->queryExecute("CREATE TABLE " . self::PROPOSALS . " (
                ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                PAGE_KIND VARCHAR(80) NOT NULL DEFAULT '',
                TEMPLATE_KEY VARCHAR(255) NOT NULL DEFAULT '',
                URL_MATCH_MODE VARCHAR(30) NOT NULL DEFAULT 'page_kind',
                SAMPLE_URL TEXT NULL,
                SAMPLE_PATH TEXT NULL,
                SCHEMA_TYPE VARCHAR(80) NOT NULL DEFAULT '',
                TITLE TEXT NULL,
                PLAIN_DESCRIPTION MEDIUMTEXT NULL,
                REASON MEDIUMTEXT NULL,
                SEARCH_PREVIEW MEDIUMTEXT NULL,
                TARGET_LOCATION MEDIUMTEXT NULL,
                INSERTION_MODE VARCHAR(80) NOT NULL DEFAULT 'dynamic_buffer_injection',
                REPLACE_EXISTING CHAR(1) NOT NULL DEFAULT 'N',
                DETECTED_DATA LONGTEXT NULL,
                EXISTING_SCHEMA LONGTEXT NULL,
                SCHEMA_JSON LONGTEXT NULL,
                AI_NOTES MEDIUMTEXT NULL,
                AI_RAW_RESPONSE LONGTEXT NULL,
                AUDIT_JSON LONGTEXT NULL,
                CONFIDENCE DECIMAL(5,2) NOT NULL DEFAULT 0,
                VERIFY_STATUS VARCHAR(30) NOT NULL DEFAULT '',
                VERIFY_MESSAGE MEDIUMTEXT NULL,
                VERIFY_JSON LONGTEXT NULL,
                VERIFIED_AT DATETIME NULL,
                STATUS VARCHAR(30) NOT NULL DEFAULT 'pending',
                APPLIED_AT DATETIME NULL,
                ROLLED_BACK_AT DATETIME NULL,
                CREATED_AT DATETIME NULL,
                UPDATED_AT DATETIME NULL,
                PRIMARY KEY (ID),
                KEY ix_kind_type_status (PAGE_KIND, SCHEMA_TYPE, STATUS),
                KEY ix_template (TEMPLATE_KEY),
                KEY ix_status (STATUS),
                KEY ix_sample_path (SAMPLE_PATH(191)),
                KEY ix_updated (UPDATED_AT)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            self::ensureColumn('URL_MATCH_MODE', "VARCHAR(30) NOT NULL DEFAULT 'page_kind'", 'TEMPLATE_KEY');
            self::ensureColumn('SAMPLE_PATH', 'TEXT NULL', 'SAMPLE_URL');
            self::ensureColumn('REPLACE_EXISTING', "CHAR(1) NOT NULL DEFAULT 'N'", 'INSERTION_MODE');
            self::ensureColumn('VERIFY_STATUS', "VARCHAR(30) NOT NULL DEFAULT ''", 'CONFIDENCE');
            self::ensureColumn('VERIFY_MESSAGE', 'MEDIUMTEXT NULL', 'VERIFY_STATUS');
            self::ensureColumn('VERIFY_JSON', 'LONGTEXT NULL', 'VERIFY_MESSAGE');
            self::ensureColumn('VERIFIED_AT', 'DATETIME NULL', 'VERIFY_JSON');
        }
        if (!$connection->isTableExists(self::LOGS)) {
            $connection->queryExecute("CREATE TABLE " . self::LOGS . " (
                ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                PROPOSAL_ID INT UNSIGNED NULL,
                ACTION VARCHAR(80) NOT NULL DEFAULT '',
                MESSAGE MEDIUMTEXT NULL,
                DATA LONGTEXT NULL,
                USER_ID INT UNSIGNED NULL,
                CREATED_AT DATETIME NULL,
                PRIMARY KEY (ID),
                KEY ix_proposal (PROPOSAL_ID),
                KEY ix_action (ACTION),
                KEY ix_created (CREATED_AT)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        self::$installed = true;
    }

    private static function ensureColumn(string $name, string $definition, string $after = ''): void
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists(self::PROPOSALS)) { return; }
        $helper = $connection->getSqlHelper();
        $exists = $connection->query("SHOW COLUMNS FROM " . self::PROPOSALS . " LIKE '" . $helper->forSql($name) . "'")->fetch();
        if ($exists) { return; }
        $sql = "ALTER TABLE " . self::PROPOSALS . " ADD " . $name . " " . $definition;
        if ($after !== '') { $sql .= " AFTER " . $after; }
        $connection->queryExecute($sql);
    }

    public static function uninstall(): void
    {
        $connection = Application::getConnection();
        if ($connection->isTableExists(self::PROPOSALS)) { $connection->dropTable(self::PROPOSALS); }
        if ($connection->isTableExists(self::LOGS)) { $connection->dropTable(self::LOGS); }
        Options::deleteAll();
    }

    public static function upsertProposal(array $data): int
    {
        self::install();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $templateKey = (string)($data['TEMPLATE_KEY'] ?? '');
        $sql = "SELECT ID, STATUS, SCHEMA_JSON FROM " . self::PROPOSALS . " WHERE PAGE_KIND='" . $helper->forSql((string)$data['PAGE_KIND']) . "' AND SCHEMA_TYPE='" . $helper->forSql((string)$data['SCHEMA_TYPE']) . "' AND TEMPLATE_KEY='" . $helper->forSql($templateKey) . "' LIMIT 1";
        $existing = $connection->query($sql)->fetch();
        $now = date('Y-m-d H:i:s');
        $sampleUrl = Security::normalizeUrl((string)($data['SAMPLE_URL'] ?? ''));
        $row = [
            'PAGE_KIND' => (string)($data['PAGE_KIND'] ?? ''),
            'TEMPLATE_KEY' => $templateKey,
            'URL_MATCH_MODE' => (string)($data['URL_MATCH_MODE'] ?? 'page_kind'),
            'SAMPLE_URL' => $sampleUrl,
            'SAMPLE_PATH' => self::urlPath($sampleUrl),
            'SCHEMA_TYPE' => (string)($data['SCHEMA_TYPE'] ?? ''),
            'TITLE' => (string)($data['TITLE'] ?? ''),
            'PLAIN_DESCRIPTION' => (string)($data['PLAIN_DESCRIPTION'] ?? ''),
            'REASON' => (string)($data['REASON'] ?? ''),
            'SEARCH_PREVIEW' => (string)($data['SEARCH_PREVIEW'] ?? ''),
            'TARGET_LOCATION' => (string)($data['TARGET_LOCATION'] ?? ''),
            'INSERTION_MODE' => (string)($data['INSERTION_MODE'] ?? 'dynamic_buffer_injection'),
            'REPLACE_EXISTING' => ((string)($data['REPLACE_EXISTING'] ?? 'N') === 'Y') ? 'Y' : 'N',
            'DETECTED_DATA' => self::json($data['DETECTED_DATA'] ?? []),
            'EXISTING_SCHEMA' => self::json($data['EXISTING_SCHEMA'] ?? []),
            'SCHEMA_JSON' => self::json($data['SCHEMA_JSON'] ?? []),
            'AI_NOTES' => (string)($data['AI_NOTES'] ?? ''),
            'AI_RAW_RESPONSE' => (string)($data['AI_RAW_RESPONSE'] ?? ''),
            'AUDIT_JSON' => self::json($data['AUDIT_JSON'] ?? []),
            'CONFIDENCE' => (float)($data['CONFIDENCE'] ?? 0),
            'VERIFY_STATUS' => '',
            'VERIFY_MESSAGE' => '',
            'VERIFY_JSON' => self::json([]),
            'VERIFIED_AT' => null,
            'UPDATED_AT' => $now,
        ];
        if ($existing) {
            $status = (string)$existing['STATUS'];
            if (in_array($status, ['approved', 'applied'], true)) {
                unset($row['SCHEMA_JSON'], $row['TITLE'], $row['PLAIN_DESCRIPTION'], $row['REASON'], $row['SEARCH_PREVIEW'], $row['TARGET_LOCATION'], $row['REPLACE_EXISTING']);
                self::log((int)$existing['ID'], 'locked_update', 'Подтверждённый пункт не перезаписан: обновлены только данные анализа.', ['page_kind' => $row['PAGE_KIND'], 'schema_type' => $row['SCHEMA_TYPE'], 'template_key' => $templateKey]);
            } else {
                $newStatus = (string)($data['STATUS'] ?? 'pending');
                if (!in_array($newStatus, ['pending', 'skipped', 'rolled_back'], true)) { $newStatus = 'pending'; }
                if ($status !== $newStatus) {
                    self::log((int)$existing['ID'], 'reactivated', 'Пункт снова актуален после повторного анализа и возвращён в статус: ' . $newStatus, ['old_status' => $status, 'new_status' => $newStatus, 'schema_type' => $row['SCHEMA_TYPE']]);
                }
                $row['STATUS'] = $newStatus;
            }
            $sets = [];
            foreach ($row as $k => $v) {
                if ($v === null) { $sets[] = $k . '=NULL'; }
                else { $sets[] = $k . "='" . $helper->forSql((string)$v) . "'"; }
            }
            $connection->queryExecute("UPDATE " . self::PROPOSALS . " SET " . implode(',', $sets) . " WHERE ID=" . (int)$existing['ID']);
            return (int)$existing['ID'];
        }
        $row['STATUS'] = (string)($data['STATUS'] ?? 'pending');
        $row['CREATED_AT'] = $now;
        $cols = array_keys($row);
        $vals = [];
        foreach (array_values($row) as $v) {
            $vals[] = $v === null ? 'NULL' : "'" . $helper->forSql((string)$v) . "'";
        }
        $connection->queryExecute("INSERT INTO " . self::PROPOSALS . " (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
        $id = (int)$connection->getInsertedId();
        self::log($id, 'created', 'Подготовлен новый пункт: ' . $row['SCHEMA_TYPE'], ['page_kind' => $row['PAGE_KIND'], 'template_key' => $templateKey]);
        return $id;
    }

    public static function setStatus(int $id, string $status, ?bool $replaceExisting = null): bool
    {
        self::install();
        $allowed = ['pending', 'applied', 'skipped', 'rolled_back'];
        if (!in_array($status, $allowed, true)) { return false; }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sets = ["STATUS='" . $helper->forSql($status) . "'", 'UPDATED_AT=NOW()'];
        if ($status === 'applied') { $sets[] = 'APPLIED_AT=NOW()'; }
        if ($status === 'rolled_back') { $sets[] = 'ROLLED_BACK_AT=NOW()'; }
        if ($replaceExisting !== null) { $sets[] = "REPLACE_EXISTING='" . ($replaceExisting ? 'Y' : 'N') . "'"; }
        $sets[] = "VERIFY_STATUS=''";
        $sets[] = "VERIFY_MESSAGE=''";
        $sets[] = "VERIFY_JSON=''";
        $sets[] = "VERIFIED_AT=NULL";
        $connection->queryExecute("UPDATE " . self::PROPOSALS . " SET " . implode(',', $sets) . " WHERE ID=" . (int)$id);
        self::log($id, $status, self::statusMessage($status) . ($replaceExisting ? ' Включена замена существующей JSON-LD-разметки этого типа.' : ''));
        return true;
    }

    public static function setVerification(int $id, bool $ok, string $message, array $data = []): void
    {
        self::install();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $connection->queryExecute("UPDATE " . self::PROPOSALS . " SET VERIFY_STATUS='" . ($ok ? 'ok' : 'failed') . "', VERIFY_MESSAGE='" . $helper->forSql($message) . "', VERIFY_JSON='" . $helper->forSql(self::json($data)) . "', VERIFIED_AT=NOW(), UPDATED_AT=NOW() WHERE ID=" . (int)$id);
        self::log($id, $ok ? 'verify_ok' : 'verify_failed', $message, $data);
    }

    public static function getProposal(int $id): ?array
    {
        self::install();
        $row = Application::getConnection()->query("SELECT * FROM " . self::PROPOSALS . " WHERE ID=" . (int)$id)->fetch();
        return $row ?: null;
    }

    public static function proposals(string $status = '', string $kind = ''): array
    {
        self::install();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $where = [];
        if ($status !== '') { $where[] = "STATUS='" . $helper->forSql($status) . "'"; }
        if ($kind !== '') { $where[] = "PAGE_KIND='" . $helper->forSql($kind) . "'"; }
        $sql = "SELECT * FROM " . self::PROPOSALS . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY FIELD(STATUS,'pending','applied','skipped','rolled_back'), PAGE_KIND, URL_MATCH_MODE, SAMPLE_PATH, SCHEMA_TYPE";
        $rows = [];
        $res = $connection->query($sql);
        while ($row = $res->fetch()) { $rows[] = $row; }
        return $rows;
    }

    public static function activeForKind(string $kind): array
    {
        self::install();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $kindSql = $helper->forSql($kind);
        $rows = [];
        $res = $connection->query("SELECT * FROM " . self::PROPOSALS . " WHERE PAGE_KIND='" . $kindSql . "' AND STATUS IN ('applied','approved') AND (URL_MATCH_MODE='' OR URL_MATCH_MODE='page_kind' OR URL_MATCH_MODE='template') ORDER BY ID ASC");
        while ($row = $res->fetch()) { $rows[] = $row; }
        return $rows;
    }

    public static function activeForRequest(string $kind, string $url): array
    {
        self::install();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $normalized = Security::normalizeUrl($url);
        $path = self::urlPath($normalized);
        $kindSql = $helper->forSql($kind);
        $urlSql = $helper->forSql($normalized);
        $pathSql = $helper->forSql($path);
        $sql = "SELECT * FROM " . self::PROPOSALS . " WHERE STATUS IN ('applied','approved') AND ((PAGE_KIND='" . $kindSql . "' AND (URL_MATCH_MODE='' OR URL_MATCH_MODE='page_kind' OR URL_MATCH_MODE='template')) OR (URL_MATCH_MODE='exact_url' AND (SAMPLE_PATH='" . $pathSql . "' OR SAMPLE_URL='" . $urlSql . "'))) ORDER BY URL_MATCH_MODE='exact_url' DESC, ID ASC";
        $rows = [];
        $res = $connection->query($sql);
        while ($row = $res->fetch()) { $rows[] = $row; }
        return $rows;
    }

    public static function counts(): array
    {
        self::install();
        $counts = ['pending' => 0, 'applied' => 0, 'skipped' => 0, 'rolled_back' => 0, 'all' => 0];
        $res = Application::getConnection()->query("SELECT STATUS, COUNT(*) CNT FROM " . self::PROPOSALS . " GROUP BY STATUS");
        while ($row = $res->fetch()) {
            $counts[(string)$row['STATUS']] = (int)$row['CNT'];
            $counts['all'] += (int)$row['CNT'];
        }
        return $counts;
    }

    public static function clearAll(): void
    {
        self::install();
        $connection = Application::getConnection();
        $connection->queryExecute("TRUNCATE TABLE " . self::PROPOSALS);
        $connection->queryExecute("TRUNCATE TABLE " . self::LOGS);
    }

    public static function skipPendingNotIn(string $kind, array $schemaTypes, string $message = '', string $templateKey = ''): void
    {
        self::install();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $where = ["PAGE_KIND='" . $helper->forSql($kind) . "'", "STATUS='pending'"];
        if ($templateKey !== '') { $where[] = "TEMPLATE_KEY='" . $helper->forSql($templateKey) . "'"; }
        $schemaTypes = array_values(array_filter(array_map('strval', $schemaTypes)));
        if ($schemaTypes) {
            $escaped = array_map(static fn($t) => "'" . $helper->forSql($t) . "'", $schemaTypes);
            $where[] = "SCHEMA_TYPE NOT IN (" . implode(',', $escaped) . ")";
        }
        $connection->queryExecute("UPDATE " . self::PROPOSALS . " SET STATUS='skipped', UPDATED_AT=NOW() WHERE " . implode(' AND ', $where));
        self::log(null, 'stale_skipped', $message ?: 'Устаревшие pending-предложения помечены как «не вносить».', ['page_kind' => $kind, 'template_key' => $templateKey, 'actual_types' => $schemaTypes]);
    }

    public static function skipPendingForTemplateExcept(string $templateKey, string $currentKind, array $schemaTypes, string $message = ''): void
    {
        self::install();
        if ($templateKey === '') { return; }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $where = [
            "TEMPLATE_KEY='" . $helper->forSql($templateKey) . "'",
            "STATUS='pending'",
            "PAGE_KIND<>'" . $helper->forSql($currentKind) . "'",
        ];
        $connection->queryExecute("UPDATE " . self::PROPOSALS . " SET STATUS='skipped', UPDATED_AT=NOW() WHERE " . implode(' AND ', $where));
        self::log(null, 'manual_reclassified', $message ?: 'Старые pending-предложения для ручного URL помечены как «не вносить».', ['template_key' => $templateKey, 'current_kind' => $currentKind, 'actual_types' => array_values($schemaTypes)]);
    }

    public static function log(?int $proposalId, string $action, string $message = '', array $data = []): void
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists(self::LOGS)) { return; }
        global $USER;
        $userId = is_object($USER) ? (int)$USER->GetID() : 0;
        $row = [
            'PROPOSAL_ID' => $proposalId ?: 'NULL',
            'ACTION' => "'" . $connection->getSqlHelper()->forSql($action) . "'",
            'MESSAGE' => "'" . $connection->getSqlHelper()->forSql($message) . "'",
            'DATA' => "'" . $connection->getSqlHelper()->forSql(self::json($data)) . "'",
            'USER_ID' => $userId ?: 'NULL',
            'CREATED_AT' => 'NOW()',
        ];
        $connection->queryExecute("INSERT INTO " . self::LOGS . " (PROPOSAL_ID,ACTION,MESSAGE,DATA,USER_ID,CREATED_AT) VALUES (" . implode(',', $row) . ")");
    }

    public static function logs(int $limit = 200): array
    {
        self::install();
        $limit = max(1, min(1000, $limit));
        $rows = [];
        $res = Application::getConnection()->query("SELECT * FROM " . self::LOGS . " ORDER BY ID DESC LIMIT " . $limit);
        while ($row = $res->fetch()) { $rows[] = $row; }
        return $rows;
    }

    public static function json($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) { $value = $decoded; }
            else { return $value; }
        }
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function urlPath(string $url): string
    {
        $path = (string)(parse_url(Security::normalizeUrl($url), PHP_URL_PATH) ?: '/');
        if ($path === '') { $path = '/'; }
        return rtrim($path, '/') . '/';
    }

    private static function statusMessage(string $status): string
    {
        return [
            'pending' => 'Пункт возвращён в ожидание решения.',
            'applied' => 'Пункт подтверждён: разметка будет выводиться модулем на страницах этого типа.',
            'skipped' => 'Пункт помечен как не вносить.',
            'rolled_back' => 'Откат выполнен: разметка больше не выводится.',
        ][$status] ?? $status;
    }
}
