<?php
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }

class custom_smart_schema extends CModule
{
    public $MODULE_ID = 'custom.smart_schema';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'Smart Schema Enterprise для 1C-Битрикс';
    public $MODULE_DESCRIPTION = 'Анализ реальных типовых страниц, подготовка предложений и динамический вывод Schema.org JSON-LD для шаблонов Bitrix/Аспро без сканирования всех товаров.';
    public $PARTNER_NAME = 'Custom';
    public $PARTNER_URI = '';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '1.0.0');
        $this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? date('Y-m-d H:i:s'));
    }

    public function DoInstall()
    {
        global $APPLICATION;
        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);
        $this->InstallDB();
        $this->InstallEvents();
        $this->InstallFiles();
        $APPLICATION->IncludeAdminFile('Установка модуля Smart Schema Enterprise', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION, $step;
        $step = (int)$step;
        if ($step < 2) {
            $APPLICATION->IncludeAdminFile('Удаление модуля Smart Schema Enterprise', __DIR__ . '/unstep1.php');
            return;
        }
        $request = Application::getInstance()->getContext()->getRequest();
        $saveData = $request->getPost('savedata') === 'Y';
        Loader::includeModule($this->MODULE_ID);
        $this->UnInstallEvents();
        if (!$saveData) {
            $this->UnInstallDB();
        }
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
        $APPLICATION->IncludeAdminFile('Удаление модуля Smart Schema Enterprise', __DIR__ . '/unstep2.php');
    }

    public function InstallDB()
    {
        \Custom\SmartSchema\Db::install();
        return true;
    }

    public function UnInstallDB()
    {
        \Custom\SmartSchema\Db::uninstall();
        return true;
    }

    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnEndBufferContent',
            $this->MODULE_ID,
            '\\Custom\\SmartSchema\\EventHandler',
            'onEndBufferContent',
            200
        );
        return true;
    }

    public function UnInstallEvents()
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnEndBufferContent',
            $this->MODULE_ID,
            '\\Custom\\SmartSchema\\EventHandler',
            'onEndBufferContent'
        );
        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
        return true;
    }
}
