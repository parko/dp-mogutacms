<?php

/*
  Plugin Name: delta-payment
  Description: Плагин для оплаты через Delta Processing. Добавляет соответствующий метод в настройки магазина, а так же, при выбранной пользователем доставки этого типа, показывает кнопку Оплатить, которая переводит на платежную страницу Delta Processing.
  Author: *** ***
  Version: 1.
 */

new DeltaPayment;

class DeltaPayment{
    private static $pluginName = ''; // название плагина (соответствует названию папки)
    private static $lang = array(); // массив с переводом плагина 
    private static $path = '';
    private static $options = '';

    public function __construct() {
        mgActivateThisPlugin(__FILE__, array(__CLASS__, 'activate')); //Инициализация  метода выполняющегося при активации
        //mgDeactivateThisPlugin(__FILE__, array(__CLASS__, 'deactivate'));
        mgAddAction(__FILE__, array(__CLASS__, 'pageSettingsPlugin')); //Инициализация  метода выполняющегося при нажатии на кнопку настроект плагина

        mgAddShortcode('delta-payment', array(__CLASS__, 'addPaymentParam'));


        self::$pluginName = PM::getFolderPlugin(__FILE__);
        self::$path = PLUGIN_DIR.self::$pluginName;
        self::$lang = PM::plugLocales(self::$pluginName);
        self::$options = unserialize(stripcslashes(MG::getSetting(self::$pluginName.'-option')));

        if(!URL::isSection('mg-admin')){
          // mgAddMeta('<script type="text/javascript" src='.SITE.'/'.self::$path.'/js/script.js></script>', 'order');
          
        }

        if(URL::isSection('order')){

          $p_id = URL::post('paymentId');
          $o_id = URL::post('orderID');

          // $creation = URL::get('creation');
          // $payment = URL::post('payment');

          if ( isset($p_id, $o_id) ){
              // $paymentString = self::getPaymentForm($p_id, $o_id);

            mgAddMeta('<script type="text/javascript">

                $(document).ready( function(){

                    $(".main-block").append(`
                        <input type="submit" id="delta-submit" value="Оплатить" />
                    `);

                    $("#delta-submit").click( function(){
                        $.ajax({
                            type: "POST",
                            async: false,
                            url: mgBaseDir+"/ajaxrequest",
                            dataType: \'json\',
                            data:{
                                mguniqueurl: "action/getPayLink",
                                pluginHandler: "delta-payment",
                                //actionerClass: "Pactioner",
                                //action: "getPayLink",
                                paymentId: '.$p_id.',
                                orderID: '.$o_id.',
                                mgBaseDir: mgBaseDir,
                            },
                            cache: false,
                            success: function(response){
                                if(response.status!=\'error\'){
                                  // console.log(response)
                                    if (response.data.result != null){
                                        window.location.href = response.data.result;
                                    }
                                }
                            }
                        });
                    })

                })



                </script>');
            }

          }

        // mgAddMeta('<link rel="stylesheet" href="'.SITE.'/'.self::$path.'/css/style.css" type="text/css" />');

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

    private static function setDefultPluginOption(){
        USER::AccessOnly('1,4','exit()');        

        $paymentId = self::getPaymentForPlugin();
        
        if(MG::getSetting(self::$pluginName.'-option') == null || empty($paymentId)){            
          
          if(empty($paymentId)){
            $paymentId = self::setPaymentForPlugin();        
          }
          
          $arPluginParams = array(
            'payment_id' => $paymentId,
            'currency' => 'RUB',
            'language' => 'ru',

          );      
          
          MG::setOption(array('option' => self::$pluginName.'-option', 'value' => addslashes(serialize($arPluginParams))));
        }
        
        // $sql = 'CREATE TABLE IF NOT EXISTS `mg-ddelivery-price-order` (
        //   `sdk_id` INT(11) NOT NULL,
        //   `sdk_price` DOUBLE NOT NULL
        //   )';
        // DB::query($sql);
      }



    /**
    * Возвращает идентификатор записи доставки из БД для плагина, по полю 'name'
    */
    static function getPaymentForPlugin(){
        $result = array();
        $dbRes = DB::query('
          SELECT id
          FROM `'.PREFIX.'payment`
          WHERE `name` = \'DeltaProcessing\'');
        
        if($result = DB::fetchAssoc($dbRes)){
          $sql = '
            UPDATE `'.PREFIX.'payment` 
            SET `activity` = 1 
            WHERE `name` = \'DeltaProcessing\'';
          DB::query($sql);
          
          return $result['id'];
        }
    }

    static function setPaymentForPlugin(){
        USER::AccessOnly('1,4','exit()');
        
        $sql = '
            INSERT INTO '.PREFIX.'payment (`name`, `activity`,`paramArray`, `urlArray`) VALUES
            (\'DeltaProcessing\', 1, \'{"Язык страницы оплаты":"", "ID Магазина":"", "Секретный ключ":""}\', \'{}\')';
        
        if(DB::query($sql)){

            $thisId = DB::insertId();
            $sql = '
                UPDATE `'.PREFIX.'payment` 
                SET `urlArray` = \'{"result URL:":"/payment?id='.$thisId.'&pay=result","success URL:":"/payment?id='.$thisId.'&pay=success","fail URL:":"/payment?id='.$thisId.'&pay=fail"}\'
                WHERE `id` = \''.$thisId.'\'';
              DB::query($sql);

            return $thisId;
        }    
    }

    

}

?>