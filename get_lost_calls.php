<?php
/** отдает json пропущенных звонков по ид юзера амоцрм за определенную дату
 * Created by PhpStorm.
 * User: root
 * Date: 25.03.16
 * Time: 0:20
 */

if ($_GET['token'] != '4d446e931b6be11ecd93d8fdb3fec0e99b2f0bf9') {
    die('WRONG TOKEN!');
} #проверка токена, тут любая строка, которая меняется и в амо и тут, для того, чтобы левые люди не смогли зайти
require_once $_SERVER['DOCUMENT_ROOT']."/config.php";
// Подключение к серверу MySQL
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    printf("Подключение к серверу MySQL невозможно. Код ошибки: %s\n", mysqli_connect_error());
    exit;
}
$mysqli->set_charset('utf8');

// Подключаем библиотеку
require_once '../../clases/Calls.php';

// герерируем дату для поиска
if (isset($_GET['date_from']) && $_GET['date_from']!='') { // если задан интервал
    if (isset($_GET['date_to']) && $_GET['date_to']!=''){
        $day_to = explode('.',$_GET['date_to']);
        $vecher  = mktime(23,59,59,$day_to[1],$day_to[0],$day_to[2]); // если задана дата по, то берем ее
    }
    else {
        $vecher = mktime(23,59,59); // сегодня
    }

    $day_from = explode('.',$_GET['date_from']);
    $utro = mktime(0,0,0,$day_from[1],$day_from[0],$day_from[2]);
}
else {
    if (!isset($_GET['date']) || $_GET['date'] == '') { // если нет даты, то за сегодня
        $utro = mktime(0,0,0);
        $vecher = mktime(23,59,59);
    }
    else {
        $day = explode('.',$_GET['date']); // если установлена дата
        $utro = mktime(0,0,0,$day[1],$day[0],$day[2]);
        $vecher = mktime(23,59,59,$day[1],$day[0],$day[2]);
    }
}
//получаем стоимость пропущенного звонка
$result = $mysqli->query("SELECT start_hour, start_min, end_hour, end_min, lost_call_price FROM settings");
if ($result!== false) {
    $settings = $result->fetch_assoc();
    $lost_call_price=(int)$settings['lost_call_price'];
}
else {
    printf("Не получил настройки. Код ошибки: %s\n", mysqli_connect_error());
    exit;
}

$result = $mysqli->query("SELECT * FROM `users` WHERE is_responsible_for_calls=1"); // get calls managers
while ($data = $result->fetch_assoc()) {
    $all_managers[] = $data['onpbx_phone'];
}
// получаем пользователя для поиска
if (!isset($_GET['manager_amo_id']) || $_GET['manager_amo_id'] == '') { // если нет менеджера, то искать для всех
    $managers = $all_managers;
}
else {
    $man_id = (int)$_GET['manager_amo_id'];
    $result = $mysqli->query("SELECT * FROM `users` WHERE `is_responsible_for_calls`=1 AND `amocrm_id`=$man_id"); // get manager by amocrm_id
    while ($data = $result->fetch_assoc()) {
        $managers[] = $data['onpbx_phone'];
    }
}
$onpbx = new Calls(ONPBX_DOMAIN, ONPBX_APIKEY, false, LAST_UPDATED_CALLS_FILE, LAST_UPDATED_PENALTIES_FILE);

$result = $onpbx->loadNewPenalties($mysqli,$all_managers, $settings);

$json = array();

foreach ($managers as $manager) {
    $single_penalties =  $onpbx->getPenaltyForSingleManager($mysqli, (int)$manager, $lost_call_price, $utro, $vecher);
    $sum=0;
    foreach ($single_penalties as $penalty) {
        $sum += $penalty['penalty'];
    }
    $single_penalties['sum'] = $sum;
    $json[$manager] = $single_penalties;
}

$json = json_encode($json);

echo $json;
