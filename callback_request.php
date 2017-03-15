<?php
/**
 * Крон передачи данных о коллбэках в амоцрм
 * ALTER TABLE  `callback_request` ADD  `location_search` VARCHAR( 4095 ) CHARACTER SET utf8 COLLATE utf8_general_ci NULL AFTER  `id` ;

 */
use Amo\Utils\Client as AmoClient;
use Amo\Entity\LoadStatus as AmoLoadStatus;
use Symfony\Component\Filesystem\LockHandler;

//ERROR_REPORTING(E_ALL);
//ini_set('display_errors', 'On');


require_once __DIR__.'/../../wp-config.php';
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../amocrm/src/Utils/AmoClient.php';
require_once __DIR__.'/../../amocrm/src/Entity/LoadStatus.php';

//это нужно чтобы не стартовало больше одной кронджобы
$lockHandler = new LockHandler('callback_request.lock', __DIR__);
//if (!$lockHandler->lock()) {
//process is already running
//    exit;
//}

$cookiePath = __DIR__.'/cookie_'.md5(uniqid(time())).'.txt';
$client = new AmoClient($cookiePath);

register_shutdown_function(function() use($cookiePath) {

    if (file_exists($cookiePath)) {
        unlink($cookiePath);
    }
});

//$link = mysqli_connect('localhost', 'allre197_bmw', '4786C3gjSi', 'allregdata_bmw'); // CHANGE FOR EVERY SITE!!
$link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
//сначала старые
$resource = mysqli_query($link, "SELECT id, number, promo_code, location_search, city FROM callback_request WHERE processed = 0 ORDER BY id ASC LIMIT 0,1000")
or die(mysqli_error($link));

if (mysqli_num_rows($resource)) {
    /**
     * это нужно чтобы прикрепить к сущностям администраторов
     */
    if(!$admin = $client->getAdminUser()) {
        throw new Exception("Responsible user was not found");
    }
    /**
     * получаем данные о кастомных полях
     */
    $tag = 'mercedes-benz.есть-запчасти.рф'; // TODO! CHANGE FOR EVERY SITE!!
    $subdomain = 'estzapchasti'; #Наш аккаунт - поддомен
    $zadacha_text = 'Связаться с клиентом, от которого пришёл обратный звонок';

    $roistatFieldInfo = $client->getCustomField('leads', array('name'=>'roistat'));
    $roistatFieldInfo = array_shift($roistatFieldInfo);
    $phoneFieldInfo = $client->getCustomField('contacts', array('code'=>'PHONE'));
    $phoneFieldInfo = array_shift($phoneFieldInfo);
    $istochnik_enums = array(
        '[сайт] на-все-авто.есть-запчасти.рф' => 4103308,
        '[сайт] audi.есть-запчасти.рф' => 4103310,
        '[сайт] bmw.есть-запчасти.рф' => 4103312,
        '[сайт] mercedes-benz.есть-запчасти.рф' => 4103314,
        '[сайт] land-rover.есть-запчасти.рф' => 4103316,
        '[сайт] porsche.есть-запчасти.рф' => 4103318,
        '[сайт] volkswagen.есть-запчасти.рф' => 4104197,
        '[сайт] volvo.есть-запчасти.рф' =>4104199,
        '[сайт] skoda.есть-запчасти.рф  ' =>4104201,
        '[сайт] bentley.есть-запчасти.рф' =>4104203,
        '[сайт] chevrolet.есть-запчасти.рф' =>4104205,
        '[сайт] lexus.есть-запчасти.рф' => 4104207,
        '[сайт] cadillac.есть-запчасти.рф' => 4104209,
        '[сайт] jaguar.есть-запчасти.рф' => 4104211,
        '[сайт] infiniti.есть-запчасти.рф' => 4104213,
    );
    $istochnik = $istochnik_enums['[сайт] '.$tag];
    $tag = 'Есть Запчасти';
}

while($data = mysqli_fetch_assoc($resource)) {
    $str = $data['location_search'];
    $ga_utm = $str;
    if (strlen($str)>0) {
        $str = ltrim($str, '?');
        parse_str($str, $output);
        $rs_levels = explode('_',$output['rs']); #$rs_levels[0] = direct6; $rs_levels[1] = context ...
        if ($rs_levels[0]==''|| $rs_levels==''|| empty($rs_levels) || !count($rs_levels))
            $rs_levels = explode('_',$output['roistat']);
    }
    else {
        $rs_levels = false;
    }

    $phone = $data['number'];
    $phone = str_replace("+", "", $phone);
    $promoCode = $data['promo_code'];
    $city = $data['city'];
    $log = array();

    if (!$contact = $client->getContactByPhone($phone)) {
        $log[] = array('message' => 'Contact does not exits', 'ts' => time());
        /**
         * создаем контакт
         */
        $contactId = $client->addContact(array(
            'name' => 'Новый контакт',
            'tags' => $tag,
            'custom_fields' => array(
                array(
                    'id' => $phoneFieldInfo['id'],
                    'values' => array(
                        array(
                            'value' => $phone,
                            'enum' => 'OTHER'
                        )
                    )
                ),
                array(
                    #город
                    'id' => 1747626,
                    'values' => array(
                        array(
                            'value' => $city
                        )
                    )
                )
            )
        ));
        $sdelka_name = 'Новая заявка [колбэк]';
        $log[] = array('message' => 'Contact created', 'ts' => time(), 'id' => $contactId);
    } else {
        $sdelka_name = 'Новая заявка [колбэк] повтор';
        $contactId = $contact['id'];
        $log[] = array('message' => 'Contact found', 'ts' => time(), 'id' => $contactId);
    }
    #create sdelka
    $leadId = $client->addLead(array(
        'name' => $sdelka_name,
        'status_id' => AmoLoadStatus::ID_UNSORTED,
        'tags' => $tag,
        'price' => 0,
        'responsible_user_id' => $admin['id'],
        'custom_fields' => array(
            array(
                'id' => $roistatFieldInfo['id'],
                'values' => array(
                    array('value' => $promoCode)
                )
            ),
            array(
                'id' => 1748322,
                'values' => array(
                    array(
                        'value' => $ga_utm
                    )
                )
            ),
            array(
                'id'=>1759586,
                'values'=> array(
                    array(
                        'value'=> $istochnik
                    )
                )
            ),
            array(
                'id'=>1759614,
                'values'=>array(
                    array(
                        'value'=>$rs_levels[0]
                    )
                )
            ),
            array(
                'id'=>1759616,
                'values'=>array(
                    array(
                        'value'=>$rs_levels[1]
                    )
                )
            ),
            array(
                'id'=>1759618,
                'values'=>array(
                    array(
                        'value'=>$rs_levels[2]
                    )
                )
            ),
            array(
                'id'=>1759620,
                'values'=>array(
                    array(
                        'value'=>$rs_levels[3]
                    )
                )
            ),
            array(
                'id'=>1759624,
                'values'=>array(
                    array(
                        'value'=>$rs_levels[4]
                    )
                )
            ),
            array(
                'id'=> 1759626,
                'values'=>array(
                    array(
                        'value'=>$rs_levels[5]
                    )
                )
            ),
        )
    ));


    $log[] = array('message'=>'Lead created', 'ts' => time(), 'id'=>$leadId);

    $contact = $client->getContact($contactId);

    $client->updateContact(array(
        'id' => $contactId,
        'last_modified' => $contact['last_modified'],
        'linked_leads_id' => array($leadId),
        'custom_fields' => array(
            array(
                #город
                'id' => 1747626,
                'values' => array(
                    array(
                        'value' => $city
                    )
                )
            )
        )
    ));
    $log[] = array('message'=>'Contact updated', 'ts' => time());


    /* Добавляем задачу */
    $deadline = strtotime('now', time() + 60*30);
    $tasks['request']['tasks']['add']=array(
        #Привязываем к сделке
        array(
            'element_id'=>$leadId, #ID сделки
            'element_type'=>2, #Показываем, что это - сделка, а не контакт
            'task_type'=>1, #Звонок
            'responsible_user_id'=>$admin['id'], #ID ответственного
            'text'=>$zadacha_text,
            'complete_till'=>$deadline
        )
    );
    $link1='https://'.$subdomain.'.amocrm.ru/private/api/v2/json/tasks/set';
    $curl=curl_init(); #Сохраняем дескриптор сеанса cURL
#Устанавливаем необходимые опции для сеанса cURL
    curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($curl,CURLOPT_USERAGENT,'amoCRM-API-client/1.0');
    curl_setopt($curl,CURLOPT_URL,$link1);
    curl_setopt($curl,CURLOPT_CUSTOMREQUEST,'POST');
    curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($tasks));
    curl_setopt($curl,CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
    curl_setopt($curl,CURLOPT_HEADER,false);
    curl_setopt($curl,CURLOPT_COOKIEFILE, $cookiePath);
    curl_setopt($curl,CURLOPT_COOKIEJAR, $cookiePath);
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,0);

    $out=curl_exec($curl); #Инициируем запрос к API и сохраняем ответ в переменную
    $code=curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl); #Завершаем сеанс cURL
    /*Конец добавления задачи */


    $query = sprintf("UPDATE callback_request SET processed = 1, amocrm_log = '%s' WHERE id = %u",
        mysqli_real_escape_string($link, json_encode($log)),
        $data['id']
    );

    mysqli_query($link, $query)or die(mysqli_error($link));

}
