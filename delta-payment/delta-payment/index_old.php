<?php

/*
    Plugin Name: delta-payment
    Description: Плагин является заготовкой для разработчиков плагинов определяется шорткодом [delta-payment], имеет страницу настроек, создает в БД таблицу для дальнейшей работы, использует собственный файл локали, свой  CSS и JS скрипы.
    Author: Avdeev Mark
    Version: 1.0
 */

new DeltaPayment;

class DeltaPayment{

    private static $pluginName = ''; // название плагина (соответствует названию папки)
    private static $lang = array(); // массив с переводом плагина 
    private static $path = '';
    private static $options = '';
    private static $apiUrl = 'http://cabinet.ddelivery.ru';
    private static $packageParams = array();
    public static $companyList = array();

    public function __construct() {
        mgActivateThisPlugin(__FILE__, array(__CLASS__, 'activate')); //Инициализация  метода выполняющегося при активации
        //mgDeactivateThisPlugin(__FILE__, array(__CLASS__, 'deactivate'));
        mgAddAction(__FILE__, array(__CLASS__, 'pageSettingsPlugin')); //Инициализация  метода выполняющегося при нажатии на кнопку настроект плагина
        mgAddShortcode('delta-payment', array(__CLASS__, 'addDeliveryParam'));
        mgAddAction('models_order_isvaliddata', array(__CLASS__, 'getDeliveryPrice'),1);       
        mgAddAction('controllers_order_getpaymentbydeliveryid', array(__CLASS__, 'getDeliveryPrice'),1);   
        mgAddAction('Models_Order_addOrder', array(__CLASS__, 'addOrderDeliveryInfo'), 1);
        mgAddAction('Models_Order_updateOrder', array(__CLASS__, 'addOrderDeliveryInfo'), 1);
        mgAddAction('mg_start', array(__CLASS__, 'getDeliveryParamForm'));
        
        self::$pluginName = PM::getFolderPlugin(__FILE__);
        self::$path = PLUGIN_DIR.self::$pluginName;
        self::$lang = PM::plugLocales(self::$pluginName);
        self::$options = unserialize(stripcslashes(MG::getSetting(self::$pluginName.'-option')));
             
        if(!URL::isSection('mg-admin')){
            mgAddMeta('<script type="text/javascript" src='.SITE.'/'.self::$path.'/js/script.js></script>', 'order');
            mgAddMeta('<script type="text/javascript" src='.SITE.'/'.self::$path.'/js/dd.js></script>');
        } 
                
        mgAddMeta('<link rel="stylesheet" href="'.SITE.'/'.self::$path.'/css/style.css" type="text/css" />');
    }
    
    public static function getCityAutocomplete($q){
        $url = self::$apiUrl.'/daemon/?_action=autocomplete&q='.$q;
        $result = self::sendCurl($url);         
        
        return $result->options;
    }

    public static function getDeliveryParamForm(){  
        if(!URL::isSection('ddelivery-form')){
            return;
        }
            
        MG::disableTemplate();        
        
        if(empty($_POST['cityInfo'])){
            $cityInfo = self::getCityByIP();
        }else{
            $cityInfo = (object) $_POST['cityInfo'];
        }    
        
        if($cityId['error']){
            
        }
        
        self::$packageParams = self::getPackageParams();
        
        $dCompany1List = self::getDCompanyList($cityInfo->city_id, 1);  //Компании доставки самовывозом
        $dCompany2List = self::getDCompanyList($cityInfo->city_id, 2);  //Компании доставки курьером        
        
        $minCompany1Price = 0;
        $delivery1List = '';
        
        foreach($dCompany1List as $company){      
            if($minCompany1Price == 0){
                $minCompany1Price = $company->client_price;
                continue;
            }
            
            if($company->client_price < $minCompany1Price){
                $minCompany1Price = $company->client_price;
            }
                        
            self::$companyList[$company->delivery_company] = $company;
            $delivery1List .= $company->delivery_company.',';
        }
        
        $delivery1List = substr($delivery1List, 0, -1);      
        
        ob_start();
        include($realDocumentRoot.PLUGIN_DIR.self::$pluginName.'/views/form.php');
        $form = ob_get_contents();
        ob_end_clean();
        
        echo $form;
        exit();
    }
    
    static function addDeliveryParam(){    
        $deliveryId = self::$options['delivery_id'];    
        $selectedDeliveryId = empty($_POST['delivery']) ? 0 : $_POST['delivery'];
        $deliveryInfo = '';
        $show = 0;    
        
        if($selectedDeliveryId == $deliveryId){
            $show = 1;            
        }
        
        if(is_array($_SESSION['delivery'][$deliveryId]['result'])){      
            $arDeliveryInfo = $_SESSION['delivery'][$deliveryId]['result'];
            $type = ($arDeliveryInfo['type'] == 1) ? 'Самовывоз' : 'Курьерская доставка';
//      $deliveryInfo = $type.': '.$arDeliveryInfo['city_name'].', '.$arDeliveryInfo['company_name'].', '.$arDeliveryInfo['address'];
        }
        
//    $dDeliveryForm = self::getDeliveryParamForm();
        
        $result = '      
            <span class="delivery-addition-info delivery'.$deliveryId.'" style="display:none;">
                <div class="ddelivery-popup-select" style="display:none;">
                    <div class="map-loader"><img src="'.SITE.'/'.self::$path.'/images/loader-2.gif" width=200px" height="200px" /></div>
                    <a href="javascript:void(0);" id="close_popup_ddelivery">&#10006;</a>
                    <div id="ddelivery_container_place" style="background: #fff;"></div>
                    <a href="javascript:void(0);" id="send_order_ddelivery" class="custom-btn"><span>Выбрать</span></a>
                </div>        
                <input type="hidden" name="sdk_id" id="sdk_id" value="" />
                <input type="hidden" value="'.$deliveryId.'" name="dd_delivery_id" />              
                <a href="javascript:void(0);" id="ddelivery_select_params">выбрать</a>
                <div class="deliveryInfo" show="'.$show.'">'.$arDeliveryInfo['info'].'</div>
            </span>      
        ';
        
        return $result;
    }
    
    static function activate(){
        USER::AccessOnly('1,4','exit()');
        self::setDefultPluginOption();    
    }
    
    /**
     * Вывод страницы плагина в админке
     */
    static function pageSettingsPlugin() {
        USER::AccessOnly('1,4','exit()');
        unset($_SESSION['payment']);
        echo '
            <link rel="stylesheet" href="'.SITE.'/'.self::$path.'/css/style.css" type="text/css" />
            <script type="text/javascript">
                includeJS("'.SITE.'/'.self::$path.'/js/script.js");          
            </script> ';

        $lang = self::$lang;
        $pluginName = self::$pluginName;
        $options = self::$options;
        
        $data['propList'] = self::getPropList();
        
        // подключаем view для страницы плагина
        include 'pageplugin.php';
    }
    
    private static function getPropList(){
        $arResult = array();
        $sql = '
            SELECT `id`, `name` 
            FROM `'.PREFIX.'property` 
            WHERE `activity` = 1 AND `type` = \'string\'';
        
        if($dbRes = DB::query($sql)){
            while($result = DB::fetchAssoc($dbRes)){
                $arResult[$result['id']] = $result['name'];
            }
        }
        
        return $arResult;
    }
    
    private static function setDefultPluginOption(){
        USER::AccessOnly('1,4','exit()');        
        
        $deliveryId = self::getDeliveryForPlugin();
        
        if(MG::getSetting(self::$pluginName.'-option') == null || empty($deliveryId)){            
            
            if(empty($deliveryId)){
                $deliveryId = self::setDeliveryForPlugin();        
            }
            
            $arPluginParams = array(        
                'delivery_id' => $deliveryId,
                'api_key' => '852af44bafef22e96d8277f3227f0998',                
                'lengthPropId' => '',
                'widthPropId' => '',        
                'depthPropId' => '',
                'test_mode' => 1,       
            );      
            
            MG::setOption(array('option' => self::$pluginName.'-option', 'value' => addslashes(serialize($arPluginParams))));
        }
        
        $sql = 'CREATE TABLE IF NOT EXISTS `mg-ddelivery-price-order` (
            `sdk_id` INT(11) NOT NULL,
            `sdk_price` DOUBLE NOT NULL
            )';
        DB::query($sql);
    }
    
    static function addOrderDeliveryInfo($args){
        $deliveryIdPlugin = self::getDeliveryForPlugin();    
        $deliveryId = 0;
        $admin = URL::isSection('mg-admin');  
        
        if($admin){
            $orderId = $args['args'][0]['id'];
            
            if(empty($orderId)){
                $orderId = $args['result']['id'];
            }
        }else{
            $orderId = $args['result']['id'];
        }
        
        if($dbRes = DB::query('
            SELECT `delivery_id` 
            FROM `'.PREFIX.'order` 
            WHERE `id` = '.DB::quote($orderId, true))){
            
            if($res = DB::fetchAssoc($dbRes)){
                $deliveryId = $res['delivery_id'];
            }
        } 
        
        if(!empty($deliveryId) && $deliveryIdPlugin == $deliveryId){
            if($admin){        
                $options = $_SESSION['deliveryAdmin'][$deliveryId];
            }else{
                $options = $_SESSION['delivery'][$deliveryId];
            }            

            $sql = '
                UPDATE `'.PREFIX.'order` 
                SET `delivery_options` = '.DB::quote(addslashes(serialize($options))).' 
                WHERE `id` = '.DB::quote($orderId, true);

            DB::query($sql);
        }
        
        if($admin){
            unset($_SESSION['deliveryAdmin']);
        }else{
            unset($_SESSION['delivery']);
        }       
        
        return $args['result'];
    }


    /**
     * Возвращает идентификатор записи доставки из БД для плагина, по полю 'name'
     */
    static function getDeliveryForPlugin(){
        $result = array();
        $dbRes = DB::query('
            SELECT id
            FROM `'.PREFIX.'delivery`
            WHERE `plugin` = \'delta-payment\'');
        
        if($result = DB::fetchAssoc($dbRes)){
            $sql = '
                UPDATE `'.PREFIX.'delivery` 
                SET `activity` = 1 
                WHERE `plugin` = \'delta-payment\'';
            DB::query($sql);
            
            return $result['id'];
        }    
    }

    /**
     * Добавляет в бд запись с типом доставки для плагина и возвращает её идентификатор
     */
    static function setDeliveryForPlugin(){
        USER::AccessOnly('1,4','exit()');
        
        $sql = '
            INSERT INTO '.PREFIX.'delivery (`name`,`cost`,`description`,`activity`,`free`, `plugin`) VALUES
            (\''.self::$lang['DELIVERY_NAME'].'('.self::$lang['FROM_PLUGIN'].')\', 0, \''.self::$lang['DELIVERY_NAME'].'\', 1, 0, \'delta-payment\')';
        
        if(DB::query($sql)){
            return DB::insertId();
        }    
    } 
    
    
}
