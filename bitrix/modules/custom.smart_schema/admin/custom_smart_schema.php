<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Custom\SmartSchema\Db;
use Custom\SmartSchema\Options;
use Custom\SmartSchema\Scanner;
use Custom\SmartSchema\Security;
use Custom\SmartSchema\AiClient;
use Custom\SmartSchema\Verifier;

if (!$USER->IsAdmin()) { $APPLICATION->AuthForm('Недостаточно прав'); }
if (!Loader::includeModule('custom.smart_schema')) { require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; CAdminMessage::ShowMessage('Модуль custom.smart_schema не подключён'); require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; exit; }

$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$action = (string)$request->get('action');
$message = '';
$error = '';
if ($action && check_bitrix_sessid()) {
    try {
        if ($action === 'save_settings') {
            Options::save($request->toArray());
            $message = 'Настройки сохранены.';
        } elseif ($action === 'scan') {
            $result = (new Scanner())->run();
            $message = 'Анализ завершён. Проверено URL: ' . (int)$result['checked'] . ', подготовлено пунктов: ' . (int)$result['created'] . '.';
            if ($result['errors']) { $error = implode('<br>', array_map([Security::class, 'e'], $result['errors'])); }
        } elseif ($action === 'clear') {
            Db::clearAll();
            $message = 'Результаты и журнал очищены.';
        } elseif ($action === 'rollback_all') {
            $n = Db::rollbackAllApplied();
            $message = 'Откат выполнен для всех внедрённых пунктов: ' . (int)$n . '. Динамический вывод разметки остановлен на всех страницах, данные и история сохранены.';
        } elseif ($action === 'status') {
            $id = (int)$request->get('id');
            $status = (string)$request->get('status');
            $replace = $request->get('replace') === 'Y' ? true : ($request->get('replace') === 'N' ? false : null);
            Db::setStatus($id, $status, $replace);
            $message = 'Статус обновлён.';
            if ($status === 'applied') {
                $verify = (new Verifier())->verifyProposal($id);
                $message .= ' ' . (string)$verify['message'];
            }
        } elseif ($action === 'verify') {
            $verify = (new Verifier())->verifyProposal((int)$request->get('id'));
            $message = (string)$verify['message'];
        } elseif ($action === 'test_ai') {
            $res = (new AiClient())->test();
            $message = !empty($res['ok']) ? 'AI-соединение работает.' : 'AI-соединение не выполнено: ' . Security::e((string)($res['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        $error = Security::e($e->getMessage());
    }
}

$APPLICATION->SetTitle('Smart Schema Enterprise');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($message) { CAdminMessage::ShowNote($message); }
if ($error) { CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $error, 'HTML' => true]); }

$tab = (string)$request->get('tab') ?: 'overview';
$tabs = [
    ['DIV' => 'overview', 'TAB' => 'Обзор', 'TITLE' => 'Обзор и запуск анализа'],
    ['DIV' => 'settings', 'TAB' => 'Настройки', 'TITLE' => 'URL-примеры и параметры'],
    ['DIV' => 'proposals', 'TAB' => 'Что внести', 'TITLE' => 'Подготовленные предложения'],
    ['DIV' => 'logs', 'TAB' => 'Журнал', 'TITLE' => 'Журнал действий'],
];
$tabControl = new CAdminTabControl('smart_schema_tabs', $tabs);
$tabControl->Begin();

$counts = Db::counts();
?>
<style>
.smart-schema-box{background:#fff;border:1px solid #d6d6d6;padding:16px;margin:12px 0;max-width:1200px}.smart-schema-table{width:100%;border-collapse:collapse}.smart-schema-table th,.smart-schema-table td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}.smart-schema-json{max-height:360px;overflow:auto;background:#f7f7f7;padding:10px;border:1px solid #ddd;white-space:pre-wrap}.smart-schema-actions a{margin-right:8px}.badge{display:inline-block;padding:2px 7px;border-radius:10px;background:#eee}.badge-applied{background:#cfeecf}.badge-pending{background:#ffe8a6}.badge-skipped{background:#ddd}.badge-rolled_back{background:#ffc8c8}.badge-ok{background:#cfeecf}.badge-failed{background:#ffc8c8}.badge-replace{background:#ffd6a0}</style>
<?php $tabControl->BeginNextTab(); ?>
<tr><td>
    <div class="smart-schema-box">
        <h2>Состояние</h2>
        <p>Всего пунктов: <b><?= (int)$counts['all'] ?></b>; ждёт решения: <b><?= (int)$counts['pending'] ?></b>; внесено: <b><?= (int)$counts['applied'] ?></b>; не вносить: <b><?= (int)$counts['skipped'] ?></b>; откат: <b><?= (int)$counts['rolled_back'] ?></b>.</p>
        <form method="post">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="scan">
            <input type="submit" class="adm-btn-save" value="Проанализировать типовые и ручные страницы">
            <a class="adm-btn" href="custom_smart_schema.php?action=rollback_all&<?=bitrix_sessid_get()?>" onclick="return confirm('Откатить ВСЕ внедрённые пункты (включая оставшиеся от прошлых версий модуля)? Динамический вывод разметки прекратится на всех страницах сайта. Данные и история сохранятся — при необходимости можно внести заново.')">Откатить все внедрения (<?= (int)$counts['applied'] ?>)</a>
            <a class="adm-btn" href="custom_smart_schema.php?action=clear&<?=bitrix_sessid_get()?>" onclick="return confirm('Удалить ВСЕ предложения и журнал безвозвратно? В отличие от отката, восстановить будет нельзя.')">Очистить всё</a>
        </form>
        <p><b>Принцип работы:</b> модуль анализирует по одному реальному URL для каждого типового шаблона и дополнительные URL из ручного списка. После подтверждения выводит JSON-LD динамически для страниц этого же типа или только для конкретного ручного URL. Все товары по одному не сканируются.</p>
        <p><b>Откат и переустановка:</b> вся разметка выводится динамически, поэтому «Откатить все внедрения» мгновенно убирает её со всех страниц (файлы шаблона и товары не меняются). Подтверждённые пункты и журнал хранятся в БД и переживают переустановку модуля — при удалении оставляйте включённой галочку «Сохранить таблицы и настройки», тогда после установки новой версии все прошлые внедрения снова будут видны здесь и их можно откатить.</p>
    </div>
</td></tr>
<?php $tabControl->BeginNextTab(); $opt = Options::all(); ?>
<tr><td>
<form method="post">
<?=bitrix_sessid_post()?>
<input type="hidden" name="action" value="save_settings">
<div class="smart-schema-box">
<h2>URL-примеры</h2>
<table class="smart-schema-table">
<tr><th>Тип</th><th>URL</th></tr>
<tr><td>Карточка товара</td><td><input type="text" name="product_sample_url" size="90" value="<?=Security::e($opt['product_sample_url'])?>"></td></tr>
<tr><td>Категория товаров</td><td><input type="text" name="category_sample_url" size="90" value="<?=Security::e($opt['category_sample_url'])?>"></td></tr>
<tr><td>Список блога/новостей</td><td><input type="text" name="blog_list_sample_url" size="90" value="<?=Security::e($opt['blog_list_sample_url'])?>"></td></tr>
<tr><td>Новость/пост</td><td><input type="text" name="news_detail_sample_url" size="90" value="<?=Security::e($opt['news_detail_sample_url'])?>"></td></tr>
<tr><td>Ручная проверка страниц<br><small>По одному URL в строке. Эти предложения будут привязаны к конкретному URL, а не ко всем страницам типа.</small></td><td><textarea name="manual_urls" rows="7" cols="100"><?=Security::e($opt['manual_urls'])?></textarea></td></tr>
</table>
</div>
<div class="smart-schema-box">
<h2>Вывод и организация</h2>
<label><input type="checkbox" name="output_enabled" value="Y" <?=$opt['output_enabled']==='Y'?'checked':''?>> Выводить подтверждённую разметку на сайте</label><br>
<label><input type="checkbox" name="avoid_duplicates" value="Y" <?=$opt['avoid_duplicates']==='Y'?'checked':''?>> Не выводить тип Schema.org, если он уже найден в HTML страницы</label><br>
<label><input type="checkbox" name="yandex_product_audit" value="Y" <?=$opt['yandex_product_audit']==='Y'?'checked':''?>> Проверять карточки товаров по обязательным полям Яндекс Товаров: Product + Offer/AggregateOffer</label>
<table class="smart-schema-table">
<tr><td>Название сайта</td><td><input type="text" name="site_name" size="70" value="<?=Security::e($opt['site_name'])?>"></td></tr>
<tr><td>Тип организации</td><td><select name="organization_type"><option value="Organization" <?=$opt['organization_type']==='Organization'?'selected':''?>>Organization</option><option value="LocalBusiness" <?=$opt['organization_type']==='LocalBusiness'?'selected':''?>>LocalBusiness</option><option value="Store" <?=$opt['organization_type']==='Store'?'selected':''?>>Store</option></select></td></tr>
<tr><td>Название организации</td><td><input type="text" name="organization_name" size="70" value="<?=Security::e($opt['organization_name'])?>"></td></tr>
<tr><td>Телефон</td><td><input type="text" name="organization_phone" size="40" value="<?=Security::e($opt['organization_phone'])?>"></td></tr>
<tr><td>Email</td><td><input type="text" name="organization_email" size="40" value="<?=Security::e($opt['organization_email'])?>"></td></tr>
<tr><td>Логотип URL</td><td><input type="text" name="organization_logo" size="70" value="<?=Security::e($opt['organization_logo'])?>"></td></tr>
<tr><td>sameAs, по одному URL в строке</td><td><textarea name="organization_same_as" rows="5" cols="80"><?=Security::e($opt['organization_same_as'])?></textarea></td></tr>
<tr><td>Шаблон поиска</td><td><input type="text" name="search_url_template" size="70" value="<?=Security::e($opt['search_url_template'])?>"></td></tr>
<tr><td>Fallback для brand товара<br><small>Используется только если бренд/производитель/издательство не найдены в HTML и JSON-LD.</small></td><td><select name="product_brand_fallback"><option value="organization" <?=$opt['product_brand_fallback']==='organization'?'selected':''?>>Название организации из настроек</option><option value="none" <?=$opt['product_brand_fallback']==='none'?'selected':''?>>Не подставлять автоматически</option></select></td></tr>
</table>
</div>
<div class="smart-schema-box">
<h2>AI-анализ, опционально</h2>
<label><input type="checkbox" name="ai_enabled" value="Y" <?=$opt['ai_enabled']==='Y'?'checked':''?>> Включить AI-анализ предложений</label>
<table class="smart-schema-table">
<tr><td>Модель</td><td><input type="text" name="openai_model" size="30" value="<?=Security::e($opt['openai_model'])?>"></td></tr>
<tr><td>OpenAI API key</td><td><input type="password" name="openai_api_key" size="60" value="" placeholder="оставьте пустым, чтобы не менять"></td></tr>
<tr><td>Timeout</td><td><input type="number" name="ai_timeout" value="<?=Security::e($opt['ai_timeout'])?>"></td></tr>
<tr><td>SOCKS5 proxy</td><td><label><input type="checkbox" name="proxy_enabled" value="Y" <?=$opt['proxy_enabled']==='Y'?'checked':''?>> включить</label> host <input type="text" name="proxy_host" value="<?=Security::e($opt['proxy_host'])?>"> port <input type="text" name="proxy_port" size="6" value="<?=Security::e($opt['proxy_port'])?>"></td></tr>
<tr><td>Proxy login/password</td><td><input type="text" name="proxy_login" value="<?=Security::e($opt['proxy_login'])?>"> <input type="password" name="proxy_password" value="" placeholder="оставьте пустым, чтобы не менять"></td></tr>
</table>
<a class="adm-btn" href="custom_smart_schema.php?action=test_ai&<?=bitrix_sessid_get()?>">Проверить AI-соединение</a>
</div>
<input type="submit" class="adm-btn-save" value="Сохранить настройки">
</form>
</td></tr>
<?php $tabControl->BeginNextTab(); $items = Db::proposals(); ?>
<tr><td>
<div class="smart-schema-box">
<p>Внедрено сейчас: <b><?= (int)$counts['applied'] ?></b>. <a class="adm-btn" href="custom_smart_schema.php?action=rollback_all&<?=bitrix_sessid_get()?>" onclick="return confirm('Откатить ВСЕ внедрённые пункты (включая оставшиеся от прошлых версий модуля)? Динамический вывод разметки прекратится на всех страницах. Данные и история сохранятся.')">Откатить все внедрения</a></p>
<table class="smart-schema-table">
<tr><th>ID</th><th>Тип страницы</th><th>Schema</th><th>Статус</th><th>Что внести</th><th>Действия</th></tr>
<?php foreach ($items as $item): $status = (string)$item['STATUS']; ?>
<tr>
<td><?= (int)$item['ID'] ?></td>
<td><?= Security::e($item['PAGE_KIND']) ?><br><small><?= Security::e($item['SAMPLE_URL']) ?></small></td>
<td><b><?= Security::e($item['SCHEMA_TYPE']) ?></b><br>confidence <?= Security::e($item['CONFIDENCE']) ?></td>
<td><span class="badge badge-<?=Security::e($status)?>"><?=Security::e($status)?></span><br><?php if (($item['REPLACE_EXISTING'] ?? 'N') === 'Y'): ?><span class="badge badge-replace">замена</span><?php endif; ?><?php if (($item['URL_MATCH_MODE'] ?? '') === 'exact_url'): ?><br><span class="badge">точный URL</span><?php endif; ?><?php if (!empty($item['VERIFY_STATUS'])): ?><br><span class="badge badge-<?=Security::e($item['VERIFY_STATUS'])?>">проверка: <?=Security::e($item['VERIFY_STATUS'])?></span><?php endif; ?></td>
<td>
<b><?=Security::e($item['TITLE'])?></b><br>
<?=nl2br(Security::e($item['PLAIN_DESCRIPTION']))?><br><br>
<b>Зачем:</b> <?=nl2br(Security::e($item['REASON']))?><br>
<b>Где:</b><pre class="smart-schema-json"><?=Security::e($item['TARGET_LOCATION'])?></pre>
<details><summary>Показать пример JSON-LD</summary><pre class="smart-schema-json"><?=Security::e($item['SCHEMA_JSON'])?></pre></details>
<details><summary>Что найдено на странице</summary><pre class="smart-schema-json"><?=Security::e($item['DETECTED_DATA'])?></pre></details>
<details><summary>Уже найденная разметка и аудит</summary><pre class="smart-schema-json"><?=Security::e($item['EXISTING_SCHEMA'])?>

AUDIT:
<?=Security::e($item['AUDIT_JSON'])?></pre></details>
<?php if (!empty($item['VERIFY_STATUS']) || !empty($item['VERIFY_MESSAGE'])): ?>
<details><summary>Результат реальной проверки после внесения</summary><pre class="smart-schema-json">STATUS: <?=Security::e($item['VERIFY_STATUS'])?>
MESSAGE: <?=Security::e($item['VERIFY_MESSAGE'])?>

<?=Security::e($item['VERIFY_JSON'])?></pre></details>
<?php endif; ?>
</td>
<td class="smart-schema-actions">
<a href="custom_smart_schema.php?action=status&id=<?=(int)$item['ID']?>&status=applied&replace=N&<?=bitrix_sessid_get()?>">Внести</a>
<a href="custom_smart_schema.php?action=status&id=<?=(int)$item['ID']?>&status=applied&replace=Y&<?=bitrix_sessid_get()?>" onclick="return confirm('Заменить старые JSON-LD-блоки этого типа разметкой модуля в HTML-буфере?')">Заменить своей</a>
<a href="custom_smart_schema.php?action=verify&id=<?=(int)$item['ID']?>&<?=bitrix_sessid_get()?>">Проверить</a>
<a href="custom_smart_schema.php?action=status&id=<?=(int)$item['ID']?>&status=skipped&<?=bitrix_sessid_get()?>">Не вносить</a>
<a href="custom_smart_schema.php?action=status&id=<?=(int)$item['ID']?>&status=rolled_back&<?=bitrix_sessid_get()?>">Откат</a>
</td>
</tr>
<?php endforeach; if (!$items): ?>
<tr><td colspan="6">Пока нет предложений. Заполните URL-примеры и запустите анализ.</td></tr>
<?php endif; ?>
</table>
</div>
</td></tr>
<?php $tabControl->BeginNextTab(); $logs = Db::logs(200); ?>
<tr><td>
<div class="smart-schema-box">
<table class="smart-schema-table">
<tr><th>ID</th><th>Дата</th><th>Действие</th><th>Сообщение</th><th>Данные</th></tr>
<?php foreach ($logs as $log): ?>
<tr><td><?= (int)$log['ID'] ?></td><td><?=Security::e($log['CREATED_AT'])?></td><td><?=Security::e($log['ACTION'])?></td><td><?=nl2br(Security::e($log['MESSAGE']))?></td><td><pre class="smart-schema-json"><?=Security::e($log['DATA'])?></pre></td></tr>
<?php endforeach; if (!$logs): ?><tr><td colspan="5">Журнал пуст.</td></tr><?php endif; ?>
</table>
</div>
</td></tr>
<?php
$tabControl->End();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
