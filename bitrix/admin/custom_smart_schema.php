<?php
$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$moduleAdminFiles = [
    $documentRoot . '/bitrix/modules/custom.smart_schema/admin/custom_smart_schema.php',
    $documentRoot . '/local/modules/custom.smart_schema/admin/custom_smart_schema.php',
];

foreach ($moduleAdminFiles as $moduleAdminFile) {
    if (is_file($moduleAdminFile)) {
        require $moduleAdminFile;
        return;
    }
}

require_once $documentRoot . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $documentRoot . '/bitrix/modules/main/include/prolog_admin_after.php';

CAdminMessage::ShowMessage([
    'TYPE' => 'ERROR',
    'MESSAGE' => 'Не найдена административная страница модуля custom.smart_schema',
    'DETAILS' => implode("\n", $moduleAdminFiles),
    'HTML' => false,
]);

require_once $documentRoot . '/bitrix/modules/main/include/epilog_admin.php';
