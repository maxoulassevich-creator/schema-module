<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
?>
<form action="<?=$APPLICATION->GetCurPage()?>" method="get">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="lang" value="<?=LANGUAGE_ID?>">
    <input type="hidden" name="id" value="custom.smart_schema">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <p><label><input type="checkbox" name="savedata" value="Y" checked> Сохранить таблицы предложений, журнал и настройки</label></p>
    <input type="submit" class="adm-btn-save" value="Удалить модуль">
</form>
