<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    return false;
}

global $APPLICATION;

if (!is_object($APPLICATION) || $APPLICATION->GetGroupRight('custom.smart_schema') <= 'D') {
    return false;
}

return [
    'parent_menu' => 'global_menu_settings',
    'sort' => 500,
    'url' => 'custom_smart_schema.php?lang=' . LANGUAGE_ID,
    'more_url' => [
        'custom_smart_schema.php',
    ],
    'text' => 'Smart Schema',
    'title' => 'Smart Schema Enterprise',
    'icon' => 'sys_menu_icon',
    'page_icon' => 'sys_page_icon',
    'module_id' => 'custom.smart_schema',
    'items_id' => 'menu_custom_smart_schema',
    'items' => [],
];
