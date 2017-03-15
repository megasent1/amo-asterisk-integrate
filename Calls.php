<?php

/** класс для работы с онлайн пбх, поиском и обработкой звонков
 * Created by PhpStorm.
 * User: MeGa
 * Date: 16.03.2016
 * Time: 21:07
 */
class Calls
{
    private $secret_key;
    private $key_id;
    private $domain;
    private $last_updated;
    private $last_updated_file;
    public $last_updated_penalty_file;
    public $last_updated_penalty;

    public function __construct($domain, $apikey, $new = false, $last_updated_file = 'last_updated_calls.txt', $last_updated_penalty_file = 'last_updated_penalties.txt' ) {
        $this->domain = $domain;
        $this->last_updated_file = $last_updated_file;
        $this->last_updated = (int)file_get_contents($last_updated_file);
        $this->last_updated_penalty_file = $last_updated_penalty_file;
        $this->last_updated_penalty = (int)file_get_contents($last_updated_penalty_file);
        $data = $this->onpbx_get_secret_key($domain, $apikey, $new);
        $this->secret_key = $data['data']['key'];
        $this->key_id = $data['data']['key_id'];
    }

    public function onpbx_get_secret_key($domain, $apikey, $new=false){
        $data = array('auth_key'=>$apikey);
        if ($new){$data['new'] ='true';}

        $ch = curl_init('http://api.onlinepbx.ru/'.$domain.'/auth.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $res = json_decode(curl_exec($ch), true);
        if ($res){return $res;}else{return false;}
    }

    public function onpbx_api_query($url, $post=array(), $opt=array()){
        $method = 'POST';
        $date = @date('r');

        if (is_array($post)){
            foreach ($post as $key => $val){
                if (is_string($key) && preg_match('/^@(.+)/', $val, $m)){
                    $post[$key] = array('name'=>basename($m[1]), 'data'=>base64_encode(file_get_contents($m[1])));
                }
            }
        }
        $post = http_build_query($post);
        $content_type = 'application/x-www-form-urlencoded';
        $content_md5 = hash('md5', $post);
        $signature = base64_encode(hash_hmac('sha1', $method."\n".$content_md5."\n".$content_type."\n".$date."\n".$url."\n", $this->secret_key, false));
        $headers = array('Date: '.$date, 'Accept: application/json', 'Content-Type: '.$content_type, 'x-pbx-authentication: '.$this->key_id.':'.$signature, 'Content-MD5: '.$content_md5);

        if (isset($opt['secure']) && $opt['secure']){
            $proto = 'https';
        }else{
            $proto = 'http';
        }
        $ch = curl_init($proto.'://'.$url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $res = json_decode(curl_exec($ch), true);
        if ($res){return $res;}else{return false;}
    }

    /** Функция получает звонки за указанную дату
     * @param null|int $date дата, за которую получать звонки
     * @return bool| mixed возвращает данные по звонкам в случае успеха, иначе false
     */
    public function loadCallsByDate($date=null) {
        if ($date==null)
            $date = new DateTime();
        else
            $date = new DateTime($date);

        $post = array('date_from' => $date->format('j M Y').'00:00:01 GMT', 'date_to'=>$date->format('j M Y').'23:59:59 GMT');             // Указываем POST данные запроса получаем звонки за дату
        $data = $this->onpbx_api_query('api.onlinepbx.ru/'.$this->domain.'/history/search.json', $post);   // Получаем готовый массив с ответом

        if ($data['status'] == 1) {
            return $data['data'];
        }
        else
            return false;
    }

    /** Добавляет звонки в базу данных
     * @param mysqli $mysqli соединение
     * @param string $table_name название таблицы со звонками
     * @param array $calls массив звонков от ПБХ
     * @return bool|mysqli_result при успехе возвращает результат mysqli, иначе false
     */
    public  function sendCallsToDB(mysqli $mysqli, $calls, $table_name='calls') {
        if (!$mysqli){
            return false;
        }
        $mysqli->set_charset('utf8');
        $prepared = array();
        foreach ($calls as $call) {
            $prep = '"'.implode('","', $call).'"';
            $prepared[] = $prep;
        }
        $to= ceil(count($prepared)/500);
        for ($j = 0; $j < $to; $j++) {
            $to2 = count($prepared);
            if ($to2 >=500) $to2 = 500;
            $toSql = array_slice($prepared,0,$to2);
            $sql ='('. implode('),(',$toSql).')';
            array_splice($prepared,0,$to2);
            $result = $mysqli->query("INSERT INTO `$table_name` (uuid, caller, caller_name, from_domain, to_who, to_domain, gateway, date, duration, billsec, hangup_cause, type) VALUES $sql");
        }
        if ($result != false) {
            return $result;
        }
        else {
            return false;
        }
    }

    /**Получает новые звонки, пишет время обновления в $last_updated_file
     * тут внимательнее с правами записи в файл $last_updated_file, файл должен быть записываемым для пхп-воркера!
     * @return bool| mixed возвращает данные по звонкам в случае успеха, иначе false
     */
    public function loadNewCalls() {
        $now = time();
        $post = array('date_from' => gmdate("j M Y H:i:s \G\M\T", $this->last_updated), 'date_to'=>gmdate("j M Y H:i:s \G\M\T", $now));             // Указываем POST данные запроса получаем звонки от последнего обновления
        $data = $this->onpbx_api_query('api.onlinepbx.ru/'.$this->domain.'/history/search.json', $post);
        if ($data['status'] == 1) {
            $this->last_updated = $now;
            $p = file_put_contents($this->last_updated_file, $this->last_updated);
            if (!$p) return false;
            return $data['data'];
        }
        else
            return false;
    }

    /** Получает звонки из локальной базы
     * @param mysqli $mysqli соединение
     * @param int $date_from UNIX-время, с которого искать
     * @param bool|false|int $date_to UNIX-время, по которое искать
     * @param string $table_name название таблицы со звонками
     * @return array|bool false при неудаче на любом из этапов, при успехе возвращает массив звонков
     */
    public  function getCallsByDate(mysqli $mysqli, $date_from, $date_to = false, $table_name='calls') {
        if (!$mysqli){
            return false;
        }
        $mysqli->set_charset('utf8');
        if (!$date_to) {$date_to = time();}
        $result = $mysqli->query("SELECT * FROM `$table_name` WHERE `date` >= $date_from AND `date` <= $date_to");
        if ($result!== false) {
            while ($call = $result->fetch_assoc()) {
                $data[] = $call;
            }
            return $data;
        }
        else {
            return false;
        }

    }

    /** Находит пропущенные вызовы из локальной базы за заданный промежуток
     * @param mysqli $mysqli соединение
     * @param array $manager_numbers
     * @param bool|false|int $date_from UNIX-время, с которого искать
     * @param bool|false|int $date_to UNIX-время, по которое искать
     * @param string $table_name название таблицы со звонками
     * @return array|bool false при неудаче на любом из этапов, при успехе возвращает массив пропущенных звонков
     */
    public  function locateLostCalls(mysqli $mysqli, array $manager_numbers, $date_from = false, $date_to = false, $table_name='calls' ) {
        if (!$mysqli){
            return false;
        }
        $mysqli->set_charset('utf8');
        if (!$manager_numbers) return false;
        if (!$date_from) {$date_from = 0;}
        if (!$date_to) {$date_to = time();}

        $query = "SELECT *
FROM `$table_name`
WHERE (
  (`caller` NOT IN ('".implode("', '",$manager_numbers). "') AND `to_who` NOT IN ('".implode("', '",$manager_numbers). "'))
AND
  (`date` >= $date_from AND `date` <= $date_to )
)";
        $result = $mysqli->query($query);
        if ($result!== false) {
            while ($call = $result->fetch_assoc()) {
                $data[] = $call;
            }
            if (isset($data)) {
                return $data;
            }
            else {
                return false;
            }
        }
        else {
            return false;
        }
    }

    /** Находит принятые вызовы из локальной базы за заданный промежуток
     * @param mysqli $mysqli соединение
     * @param array $manager_numbers
     * @param bool|false|int $date_from UNIX-время, с которого искать
     * @param bool|false|int $date_to UNIX-время, по которое искать
     * @param string $table_name название таблицы со звонками
     * @return array|bool false при неудаче на любом из этапов, при успехе возвращает массив пропущенных звонков
     */
    public  function locateDoneCalls (mysqli $mysqli, array $manager_numbers, $date_from = false, $date_to = false, $table_name='calls' ) {
        if (!$mysqli){
            return false;
        }
        $mysqli->set_charset('utf8');
        if (!$manager_numbers) return false;
        if (!$date_from) {$date_from = 0;}
        if (!$date_to) {$date_to = time();}

        $query = "SELECT *
FROM `$table_name`
WHERE (
  (`caller` IN ('".implode("', '",$manager_numbers). "') OR `to_who` IN ('".implode("', '",$manager_numbers). "'))
AND
  (`date` >= $date_from AND `date` <= $date_to )
)";
        $result = $mysqli->query($query);
        if ($result!== false) {
            while ($call = $result->fetch_assoc()) {
                $data[] = $call;
            }
            if (isset($data)) {
                return $data;
            }
            else {
                return false;
            }
        }
        else {
            return false;
        }
    }

    /** Функция, получающая массив ответственных за пропущенные звонки за определенное время
     * @param mysqli $mysqli соединение
     * @param array $manager_numbers массив номеров менеджеров в системе онпбх
     * @param bool|false $date_from юникс-время начала поиска
     * @param bool|false $date_to юникс-время конца поиска
     * @param int $check_time время от начала пропущенного, за которое просматривать звонки
     * @param string $table_name название таблицы звонков на локальном сервере
     * @return array|bool массив ответственных в случае успеха, иначе false
     */
    public  function getLostCallResponsibleManagers(mysqli $mysqli, array $manager_numbers, $date_from = false, $date_to = false, $check_time=600, $table_name='calls') {
        if (!$mysqli){
            return false;
        }
        if (!$manager_numbers) return false;
        if (!$date_from) {$date_from = 0;}
        if (!$date_to) {$date_to = time();}
        $responsible = array(); // массив, в котором каждое значение - массив ID, call_uuid, call_date, responsible_number,
        $lost_calls = $this->locateLostCalls($mysqli, $manager_numbers, $date_from, $date_to, $table_name); // получаем пропущенные звонки
        if (isset($lost_calls) && count($lost_calls)) {
            foreach ($lost_calls as $call) {
                // начинается магия
                $find_till = (int)$call['date'];
                $find_from = $find_till-$check_time;
                $responsible_for_this = $manager_numbers;
                $done_calls = $this->locateDoneCalls($mysqli, $manager_numbers, $find_from, $find_till, $table_name);
                if ($done_calls) {
                    foreach ($done_calls as $done_call) {
                        if ( ((int)$done_call['date'])+((int)$done_call['duration']) > $find_till ) { // если менеджер был занят в момент пропущенного звонка, т.е. звонок длился после начала пропущенного звонка
                            // убераем его из массива ответственных
                            if(($key = array_search($done_call['caller'], $responsible_for_this)) !== false) { // если обрабатывал входящий звонок
                                unset($responsible_for_this[$key]);
                            }
                            elseif (($key = array_search($done_call['to_who'], $responsible_for_this)) !== false) { // если обрабатывал исходящий звонок
                                unset($responsible_for_this[$key]);
                            }
                        }
                    }
                }
                $responsible_for_this = json_encode($responsible_for_this);
                $responsible[] = array(
                    'call_uuid' => $call['uuid'],
                    'call_date' => $call['date'],
                    'responsible_numbers' => $responsible_for_this
                );
            }
            return $responsible; // вернет массив, в котором каждое значение - массив ID, call_uuid, call_date, responsible_numbers,
        }
        else {
            return "[]";
        }
    }

    /** Обрабатывает массив данных, полученных функцией getLostCallResponsibleManagers, каждому пользователю присваивается коэффициент штрафа
     * @param array $penalties штрафы из функции getLostCallResponsibleManagers
     * @return array массив данных об ответсвенных пользователях и штрафов для них
     */
    public  function getPenaltiesMatchedUsersArray(array $penalties) {
        $data = array();
        foreach ($penalties as $penalty) {
            if ($penalty['responsible_numbers']!='[]') {
                $users = json_decode($penalty['responsible_numbers']); // 100, 101
                foreach ($users as $user) {
                    $koef = 1/count($users);
                    $data[] = array(
                        'responsible' => $user,
                        'call_uuid' => $penalty['call_uuid'],
                        'call_date' => $penalty['call_date'],
                        'penalty_multiplier' => $koef
                    );
                }
            }
        }
        return $data;
    }

    /** Отправляет данные из функции getPenaltiesMatchedUsersArray в БД, предварительно обрабатывая их
     * @param mysqli $mysqli соединение
     * @param array $penalties_matched массив из getPenaltiesMatchedUsersArray
     * @param string $table_name таблица в БД
     * @return bool|mysqli_result результат
     */
    public  function sendPenaltiesToDB(mysqli $mysqli, array $penalties_matched,$settings, $table_name= 'penalties') {
        if (!$mysqli){
            return false;
        }
        $mysqli->set_charset('utf8');
        $penalties_set = array();
        $date = new DateTime();
        if (!count($penalties_matched)) {
            return true;
        }
        foreach ($penalties_matched as $penalty) { // если звонок не в рабочее время - то не считаем его
            $date->setTimestamp($penalty['call_date']);
            $time_from = mktime($settings['start_hour'],$settings['start_min'], 0, $date->format('n'),$date->format('j'), $date->format('Y'));
            $time_to = mktime($settings['end_hour'],$settings['end_min'], 0, $date->format('n'),$date->format('j'), $date->format('Y'));
            if ($penalty['call_date'] >= $time_from && $penalty['call_date']<= $time_to) { //если после начала и до конца раб.дня
                $penalties_set[] = $penalty;
            }
        }
        foreach ($penalties_set as $penalty) {
            $prep = '"'.implode('","', $penalty).'"';
            $prepared[] = $prep;
        }
        $sql = '('. implode('),(',$prepared).')';
        $result = $mysqli->query("INSERT INTO `$table_name` (responsible, call_uuid, call_date, penalty_multiplier) VALUES $sql");
        if ($result!== false) {
            return $result;
        }
        else {
            return false;
        }
    }

    /** Функция получает новые штрафы и отправляет их в БД, одна из важнейших функций класса!
     * @param mysqli $mysqli соединение
     * @param array $manager_numbers номера менеджеров
     * @return bool|mysqli_result
     */
    public function loadNewPenalties(mysqli $mysqli, array $manager_numbers, $settings) {
        $calls = $this->loadNewCalls(); // обновили звонки
        $result = $this->sendCallsToDB($mysqli, $calls);        // пишем звонки в БД
        if (!$result) return false; // если нет новых звонков - выходим
        $now = time();
        $responsible = $this->getLostCallResponsibleManagers($mysqli, $manager_numbers, $this->last_updated_penalty, time());
        $penalties = $this->getPenaltiesMatchedUsersArray($responsible);
        $result = $this->sendPenaltiesToDB($mysqli, $penalties, $settings);
        $this->last_updated_penalty = $now;
        $p = file_put_contents($this->last_updated_penalty_file, $this->last_updated_penalty);
        if (!$p) return false;
        return $result;
    }

    /** Получает звонки и штрафы за них за определенный промежуток времени из локальной базы
     * @param mysqli $mysqli соединение
     * @param int $manager_number номер менеджера в онпбх
     * @param bool|false $date_from юникс-время начала поиска
     * @param bool|false $date_to юникс-время конца поиска
     * @param string $table_name таблица штрафов
     * @return bool|array массив штрафов по менеджеру, или false в случае неудачи
     */
    public  function getPenaltyForSingleManager(mysqli $mysqli, $manager_number, $penalty_price, $date_from = false, $date_to = false, $table_name='penalties') {
        if (!$mysqli){
            return false;
        }
        $mysqli->set_charset('utf8');
        if (!$manager_number) return false;
        if (!$date_from) {$date_from = 0;}
        if (!$date_to) {$date_to = time();}
        $query = "SELECT * FROM `$table_name` WHERE ((`responsible` = $manager_number) AND (`call_date` >= $date_from AND `call_date` <= $date_to ) )";
        $result = $mysqli->query($query);
        if ($result!== false) {
            while ($data = $result->fetch_assoc()) {
                $data['penalty'] = $data['penalty_multiplier']*$penalty_price;
                $penalty[] = $data;
            }
            return $penalty;
        }
        else {
            return false;
        }
    }
}