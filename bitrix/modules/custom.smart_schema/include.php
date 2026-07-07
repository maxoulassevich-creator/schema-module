<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('custom.smart_schema', [
    'Custom\\SmartSchema\\Db' => 'lib/Db.php',
    'Custom\\SmartSchema\\Options' => 'lib/Options.php',
    'Custom\\SmartSchema\\Logger' => 'lib/Logger.php',
    'Custom\\SmartSchema\\Security' => 'lib/Security.php',
    'Custom\\SmartSchema\\HtmlAnalyzer' => 'lib/HtmlAnalyzer.php',
    'Custom\\SmartSchema\\Auditor' => 'lib/Auditor.php',
    'Custom\\SmartSchema\\TemplateDetector' => 'lib/TemplateDetector.php',
    'Custom\\SmartSchema\\SchemaBuilder' => 'lib/SchemaBuilder.php',
    'Custom\\SmartSchema\\Scanner' => 'lib/Scanner.php',
    'Custom\\SmartSchema\\AiClient' => 'lib/AiClient.php',
    'Custom\\SmartSchema\\Output' => 'lib/Output.php',
    'Custom\\SmartSchema\\Verifier' => 'lib/Verifier.php',
    'Custom\\SmartSchema\\EventHandler' => 'lib/EventHandler.php',
]);
