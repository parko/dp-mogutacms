<?php
class Pactioner extends Actioner {
    private static $pluginName = 'delta-payment';
    
    /**
     * Сохраняет  опции плагина
     * @return boolean
     */
    public function saveBaseOption(){
        USER::AccessOnly('1,4','exit()');
        $this->messageSucces = $this->lang['SAVE_BASE'];
        $this->messageError = $this->lang['NOT_SAVE_BASE'];
        unset($_SESSION['delta-paymentAdmin']);
        unset($_SESSION['delta-payment']);
        
        if(!empty($_POST['data'])) {
            MG::setOption(array('option' => self::$pluginName.'-option', 'value' => addslashes(serialize($_POST['data']))));
        }
        
        return true;
    }

    public function test(){
        $this->data["result"] = "ok";
        return true;
    }

    public function notification(){
        $o_id = $_GET["orderID"];
        $this->data["result"] = $o_id;
        $notification_decoded = json_decode(file_get_contents('php://input'), true);
        if ($notification_decoded["transaction"]["status"] == "successful" ){
            $result_order = array();
            $dbRes = DB::query('
                SELECT *
                FROM `'.PREFIX.'order`
                WHERE `id`=\''.$o_id.'\'
            ');

            $result_order = DB::fetchAssoc($dbRes);
            if ($result_order["paided"] == 0 && $result_order["status_id"] != 2){
                $sql = '
                    UPDATE `'.PREFIX.'order` 
                    SET `paided` = 1, `status_id` = 2
                    WHERE `id` = \''.$o_id.'\'';
                DB::query($sql);

                $this->data["result"] = "ok";
            }
            return true;
        } else {
            return false;
        }
    }


    public function getPayLink(){
        $p_id = $_POST['paymentId'];
        $o_id = $_POST['orderID'];
        $mgBaseDir = $_POST['mgBaseDir'];

        $result_payment = array();
        $dbRes = DB::query('
            SELECT *
            FROM `'.PREFIX.'payment`
            WHERE `id` = \''.$p_id.'\'');
        $result_payment = DB::fetchArray($dbRes);

        $result_order = array();
        $dbRes = DB::query('
            SELECT *
            FROM `'.PREFIX.'order`
            WHERE `id`=\''.$o_id.'\'
        ');

        $result_order = DB::fetchAssoc($dbRes);

        $paymentParamDecoded = json_decode($result_payment[3]);

        $summ = $result_order['summ'];
        if (strpos($summ, ".") !== false){
            $new_summ = str_replace(".", "", $summ);
            $summ = $new_summ;
        } else {
            $summ = $summ.'00';
        }

        $auth_key_array = array("", "");

        $curr = (MG::getSetting('currencyShopIso')=="RUR")?"RUB":MG::getSetting('currencyShopIso');
        // $curr = "zz";
        foreach ($paymentParamDecoded as $key => $value) {
            if ($key == 'Язык страницы оплаты') {
                $lang = CRYPT::mgDecrypt($value);
            } elseif ($key == "ID Магазина") {
                $auth_key_array[0] = CRYPT::mgDecrypt($value);
            } elseif ($key == "Секретный ключ") {
                $auth_key_array[1] = CRYPT::mgDecrypt($value);
            }
        }

        $urlDecoded = json_decode($result_payment[4]);
        foreach ($urlDecoded as $key => $value) {
            if ($key == "result URL"){
                $resultURL = $value;
            } elseif ($key == "success URL") {
                $successURL = $value;
            } elseif ($key == "fail URL") {
                $failURL = $value;
            }
        }

        $notificationURL = "/ajaxrequest?mguniqueurl=action/notification&pluginHandler=delta-payment&orderID=".$o_id;

        $authToken = base64_encode($auth_key_array[0].$auth_key_array[1]);

        $postData = array(
            "checkout" => array(
                "transaction_type" => "payment",
                "version" => 2,
                "order" => array(
                    "amount" => $summ,
                    "currency" => $curr,
                    "description" => $result_order['number']
                ),
                "settings" => array(
                    "decline_url" => $mgBaseDir.$failURL,
                    "fail_url" => $mgBaseDir.$failURL,
                    "notification_url" => $mgBaseDir.$notificationURL,
                    "success_url" => $mgBaseDir.$successURL,
                    "language" => $lang
                )
            )
        );

        $ch = curl_init('https://checkout.deltaprocessing.ru/ctp/api/checkouts');
        curl_setopt_array($ch, array(
            CURLOPT_POST => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
            CURLOPT_USERPWD => $auth_key_array[0] . ":" . $auth_key_array[1],
            CURLOPT_POSTFIELDS => json_encode($postData)
        ));

        // Send the request
        $response = curl_exec($ch);

        // Check for errors
        // if($response === FALSE){
        //     die(curl_error($ch));
        // }

        // Decode the response
        $responseData = json_decode($response, TRUE);

        // Print the date from the response
        // echo var_dump($responseData);
        // echo $responseData['redirect_url'];
        $this->data["result"] = $responseData["checkout"]["redirect_url"];
        // $this->data["result"] = $summ;
        // $this->data["result"] = "https://google.com/";
        return true;
    }
}
