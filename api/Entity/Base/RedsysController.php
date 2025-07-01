<?php
require_once(__DIR__ . '/../ClassLoader.php');
require_once(__DIR__ . '/../Mailer.php');
require_once(__DIR__ . '/../EntityLib.php');

class RedsysController
{

    private $logFilePath = __DIR__ . '/../../log/redsys/';
    private $logFileName = 'redsys.log';
    private $logFile = null;
    private $logEnabled = false;
    private $uuid = null;


    public function __construct($create_uiid = false)
    {
        // Comprobamos que existan los directorios para el log
        $log_folder_1 = $this->logFilePath . date('Y');
        $log_folder_2 = $log_folder_1 . '/' . date('m');
        if (!is_dir($this->logFilePath))
            mkdir($this->logFilePath);
        if (!is_dir($log_folder_1))
            mkdir($log_folder_1);
        if (!is_dir($log_folder_2))
            mkdir($log_folder_2);
        $this->logFile = $log_folder_2 . '/' . $this->logFileName;
        $this->logEnabled = is_dir($log_folder_2);

        if ($this->logEnabled && !empty($create_uiid)) {
            $this->setUuid();
        }
    }

    // Se lanza petición a redsys
    public function redsysRequest()
    {
        require_once(__DIR__ . '/../lib/redsys/ApiRedsysREST/initRedsysApi.php');
        $config_url = EntityLib::getConfig('app_url');
        // Obtenemos configuración Redsys
        $cabinpaqConfigObj = ClassLoader::getModelObject('configuracion_cabinpaq', false);
        $cabinpaqData = $cabinpaqConfigObj->getById(1);
        $merchant_fuc = $cabinpaqData['redsys_comercio'];
        $merchant_currency = $cabinpaqData['redsys_currency'];
        $merchant_terminal = $cabinpaqData['redsys_terminal'];
        $merchant_sig = $cabinpaqData['redsys_sig'];
        $redsys_url = $cabinpaqData['redsys_url'];
        for ($i = 0; $i <= 4; $i++) {
            $merchant_sig = base64_decode($merchant_sig);
        }

        $transaccionesObj = ClassLoader::getModelObject('transacciones', false);
        $guid = (!empty($_GET) && !empty($_GET['operacion'])) ? $_GET['operacion'] : null;
        if (empty($guid)) die('Error generando la referencia para el pago');
        $options = array(
            'filters' => array(
                'guid' => array('=' => $guid),
                'procesado' => array('=' => 0)
            ),
        );
        $auxTransaccionData = $transaccionesObj->getList($options);
        $transaccionData = array();
        if (!empty($auxTransaccionData) && !empty($auxTransaccionData['data']) && count($auxTransaccionData['data']) == 1)
            $transaccionData = $auxTransaccionData['data'][0];
        if (empty($transaccionData)) {
            die('Error generando la referencia para el pago.');
        }
        $amount = '0000';
        $metodo_pago_order = !empty($transaccionData['codigo_pago']) ? $transaccionData['codigo_pago'] : null;
        $pago_liquidacion_id = !empty($transaccionData['liquidacion_id']) ? $transaccionData['liquidacion_id'] : null;
        if (!empty($pago_liquidacion_id)) {
            $codigo_liquidacion = !empty($transaccionData['liquidacion_codigo']) ? $transaccionData['liquidacion_codigo'] : '';
            $importe_liquidacion = !empty($transaccionData['liquidacion_importe']) ? $transaccionData['liquidacion_importe'] : 0.00;
            $amount = '' . ($importe_liquidacion * 100);
            $redirect_url = !empty($transaccionData['from']) ? $transaccionData['from'] : '';
        }

        // Generamos los datos del pedido
        $data = array(
            "DS_MERCHANT_MERCHANTCODE" => $merchant_fuc,
            "DS_MERCHANT_CURRENCY" => '' . $merchant_currency,
            "DS_MERCHANT_TERMINAL" => '' . $merchant_terminal,
            "DS_MERCHANT_AMOUNT" => $amount,
            "DS_MERCHANT_TRANSACTIONTYPE" => "0",
            "DS_MERCHANT_MERCHANTURL" => $config_url . '/api/redsysNotify',
        );
        $data["DS_MERCHANT_URLKO"] = $config_url . '/api/redsysKo/'.$guid;
        $data["DS_MERCHANT_URLOK"] = $config_url . '/api/redsysOk/'.$guid;

        // Si había en sesión código de pedido, lo incluimos en la petición
        if (!empty($metodo_pago_order)) {
            $data["DS_MERCHANT_ORDER"] = $metodo_pago_order;
            $data["DS_MERCHANT_COF_INI"] = "S";
            $data["DS_MERCHANT_IDENTIFIER"] = "REQUIRED";
            $data["DS_MERCHANT_COF_TYPE"] = "M";

        } else if (!empty($pago_liquidacion_id)) {
            $data["DS_MERCHANT_ORDER"] = $codigo_liquidacion;
        }

        $_SESSION['__transaccion_id__'] = $transaccionData['id'];

        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $base64data = base64_encode($data);
        $signatureUtils = new RESTSignatureUtils();
        $localSignature = $signatureUtils->createMerchantSignature($merchant_sig, $base64data);
        $msg = EntityLib::__('Redirigiendo a la página del comercio');
        ?>
        <form id="redsysForm" name="from" action="<?php echo $redsys_url; ?>" method="POST" style="display: none;">
            <input type="hidden" name="Ds_SignatureVersion" value="HMAC_SHA256_V1"/>
            <input type="hidden" name="Ds_MerchantParameters" value="<?php echo $base64data; ?>"/>
            <input type="hidden" name="Ds_Signature" value="<?php echo $localSignature; ?>"/>
            <input type="submit" id="submitButton"/>
        </form>
        <script type="text/javascript">document.getElementById("redsysForm").submit();</script>
        <?php
        $this->setUuid();
        if (!empty($metodo_pago_order))
            $this->logData('Redirigiendo a REDSYS para primera referencia: ' . $redsys_url);
        else {
            $this->logData('Redirigiendo a REDSYS para primera referencia: ' . $redsys_url);
        }
        $this->logData($base64data);
        $this->logData($localSignature);
        $this->logData($data);
    }

    public function redsysPayment($tarjeta_id, $payment_data)
    {
        $this->setUuid();
        $this->logData('Recibida solicitud de pago para tarjeta ' . $tarjeta_id);
        $this->logData($payment_data);
        require_once(__DIR__ . '/../lib/redsys/ApiRedsysREST/initRedsysApi.php');
        try {
            if (empty($payment_data['token'])) throw new Exception('Token de tarjeta incorrecto.');
            if (empty($payment_data['referencia_pedido'])) throw new Exception('Referencia de pedido incorrecta');
            if (empty($payment_data['importe'])) throw new Exception('Importe incorrecto.');

            // Obtenemos configuración Redsys
            $cabinpaqConfigObj = ClassLoader::getModelObject('configuracion_cabinpaq', false);
            $cabinpaqData = $cabinpaqConfigObj->getById(1);
            $merchant_fuc = $cabinpaqData['redsys_comercio'];
            $merchant_currency = '' . $cabinpaqData['redsys_currency'];
            $merchant_terminal = '' . $cabinpaqData['redsys_terminal'];
            $merchant_sig = $cabinpaqData['redsys_sig'];
            for ($i = 0; $i <= 4; $i++) {
                $merchant_sig = base64_decode($merchant_sig);
            }
            $amount = '' . ($payment_data['importe'] * 100);

            // Generamos la petición REST
            $request = new RESTOperationMessage();
            // Operation mandatory data
            $request->setMerchant($merchant_fuc);
            $request->setTransactionType("0");
            $request->setOrder($payment_data['referencia_pedido']);
            $request->setTerminal($merchant_terminal);
            $request->setCurrency($merchant_currency);
            $request->setAmount($amount);
            $request->addParameter("DS_MERCHANT_IDENTIFIER", $payment_data['token']);
            $request->addParameter("DS_COF_TYPE", "M");
            $request->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Cobro recurrente");
            $request->useDirectPayment();

            // Service setting (Signature, Environment, type of payment)
            $this->logData('Procesado correctamente datos para enviar el pago');
            $this->logData($request);
            $service = new RESTOperationService($merchant_sig, RESTConstants::$ENV_PRODUCTION);
            $response = $service->sendOperation($request);

            $this->logData('Respuesta recibida');
            $this->logData($response->getResult());
            switch ($response->getResult()) {
                case RESTConstants::$RESP_LITERAL_OK:
                    $this->logData('Operación realizada');
                    break;
                /*case RESTConstants::$RESP_LITERAL_AUT:
                    echo "<h1>Operation requires authentication</h1>";
                    break;*/
                default:
                    //echo "<h1>Operation was not OK</h1>";
                    throw new Exception('No se pudo realizar la operación');
                    break;
            }
        } catch (Exception $e) {
            $this->logData($e->getMessage(), 'ERROR');
        }

    }

    // Gestión redsysOk para redirigir a origen con mensaje
    public function redsysOk()
    {
        $config_url = EntityLib::getConfig('app_url');

        $transaccionesObj = ClassLoader::getModelObject('transacciones', false);
        $guid = (!empty($_GET) && !empty($_GET['operacion'])) ? $_GET['operacion'] : null;
        if (empty($guid)) die('Error generando la referencia para el pago');
        $options = array(
            'filters' => array(
                'guid' => array('=' => $guid),
                'procesado' => array('=' => 0)
            ),
        );
        $auxTransaccionData = $transaccionesObj->getList($options);
        $transaccionData = array();
        if (!empty($auxTransaccionData) && !empty($auxTransaccionData['data']) && count($auxTransaccionData['data']) == 1)
            $transaccionData = $auxTransaccionData['data'][0];
        if (empty($transaccionData)) {
            die('Error generando la referencia para el pago...');
        }
        $from = $transaccionData['from'];
        $is_app = !empty($transaccionData['from_app']);

        $update = array(
            'id' => $transaccionData['id'],
            'procesado' => 1,
            'datetime_procesado' => date('Y-m-d H:i:s'),
        );
        $transaccion_id = $transaccionesObj->save($update);

        $redirect_url = $config_url . '/__redirect';
        $redirect_data = array(
            'redirect' => $from,
            'message' => EntityLib::__('Operación realizada correctamente'),
        );
        $redirect_json = json_encode($redirect_data, JSON_UNESCAPED_UNICODE);
        $redirect_data = base64_encode($redirect_json);
        ?>
        <form id="redsysForm" name="from" action="<?php echo $redirect_url; ?>" method="GET" style="display: none;">
            <input type="hidden" name="r" value="<?php echo $redirect_data; ?>"/>
            <input type="submit" id="submitButton"/>
        </form>
        <script type="text/javascript">
            try {
                <?php if($is_app) { ?>
                var target = false;
                if(typeof cordova_iab !== 'undefined')
                {
                    target = cordova_iab;
                }
                else if(typeof webkit.messageHandlers.cordova_iab !== 'undefined')
                {
                    target = webkit.messageHandlers.cordova_iab;
                }
                if (target) {
                    var message = {
                        action: 'close',
                        data: '<?php echo $redirect_data;?>',
                    };
                    target.postMessage(JSON.stringify(message));
                }
                else
                {
                    document.getElementById("redsysForm").submit();
                }
                <?php } else { ?>
                document.getElementById("redsysForm").submit();
                <?php } ?>
            }
            catch(err)
            {
                document.getElementById("redsysForm").submit();
            }
        </script>
        <?php
        $this->setUuid();
        $this->logData('Redirigiendo a URL - Operación OK: ' . $from);
        return array('success' => true);
    }

    // Gestión redsysKo para redirigir a origen con mensaje
    public function redsysKo()
    {
        $config_url = EntityLib::getConfig('app_url');

        $transaccionesObj = ClassLoader::getModelObject('transacciones', false);
        $guid = (!empty($_GET) && !empty($_GET['operacion'])) ? $_GET['operacion'] : null;
        if (empty($guid)) die('Error generando la referencia para el pago');
        $options = array(
            'filters' => array(
                'guid' => array('=' => $guid),
                'procesado' => array('=' => 0)
            ),
        );
        $auxTransaccionData = $transaccionesObj->getList($options);
        $transaccionData = array();
        if (!empty($auxTransaccionData) && !empty($auxTransaccionData['data']) && count($auxTransaccionData['data']) == 1)
            $transaccionData = $auxTransaccionData['data'][0];
        if (empty($transaccionData)) {
            die('Error generando la referencia para el pago...');
        }
        $from = $transaccionData['from'];
        $is_app = !empty($transaccionData['from_app']);

        $update = array(
            'id' => $transaccionData['id'],
            'procesado' => 2,
            'datetime_procesado' => date('Y-m-d H:i:s'),
        );
        $transaccion_id = $transaccionesObj->save($update);

        $redirect_url = $config_url . '/__redirect';
        $redirect_data = array(
            'redirect' => $from,
            'message' => EntityLib::__('No se pudo completar el proceso. Inténtelo de nuevo más tarde'),
            'message_format' => 'error',
        );
        $redirect_json = json_encode($redirect_data, JSON_UNESCAPED_UNICODE);
        $redirect_data = base64_encode($redirect_json);
        //echo '<pre>';print_r($transaccionData);echo '</pre>';
        ?>
        <form id="redsysForm" name="from" action="<?php echo $redirect_url; ?>" method="GET" style="display: none;">
            <input type="hidden" name="r" value="<?php echo $redirect_data; ?>"/>
            <input type="submit" id="submitButton"/>
        </form>
        <script type="text/javascript">
            try {
                <?php if($is_app) { ?>
                var target = false;
                if(typeof cordova_iab !== 'undefined')
                {
                    target = cordova_iab;
                }
                else if(typeof webkit.messageHandlers.cordova_iab !== 'undefined')
                {
                    target = webkit.messageHandlers.cordova_iab;
                }
                if (target) {
                    var message = {
                        action: 'close',
                        data: '<?php echo $redirect_data;?>',
                    };
                    target.postMessage(JSON.stringify(message));
                }
                else
                {
                    document.getElementById("redsysForm").submit();
                }
                <?php } else { ?>
                    document.getElementById("redsysForm").submit();
                <?php } ?>
            }
            catch(err)
            {
                document.getElementById("redsysForm").submit();
            }
        </script>
        <?php
        $this->setUuid();
        $this->logData('Redirigiendo a URL - Operación KO: ' . $from);
        return array('success' => true);
    }

    // Gestión notificación de petición de redsys
    public function redsysNotify()
    {
        $this->setUuid();
        $this->logData('Recibido NOTIFY de operación');
        $this->logData($_POST);
        $this->processNotify($_POST);
    }

    public function processNotify($content)
    {
        try {
            $this->logData('Procesando NOTIFY');
            $this->logData($content);
            $decoded = array();
            if (!empty($content['Ds_MerchantParameters'])) {
                $decoded = json_decode(base64_decode($content['Ds_MerchantParameters']), JSON_UNESCAPED_UNICODE);
            }
            $this->logData($decoded);
            if (!empty($decoded) && is_array($decoded) && !empty($decoded['Ds_Order'])) {

                $respuesta_code = !empty($decoded['Ds_Response']) ? $decoded['Ds_Response'] : '';
                if ($respuesta_code !== '0000')
                    throw new Exception('Operación no realizada: ' . $respuesta_code);

                $txnid = !empty($decoded['Ds_Merchant_Cof_Txnid']) ? $decoded['Ds_Merchant_Cof_Txnid'] : '';
                $is_authorization = !empty($txnid);
                $this->logData('Operación realizada correctamente: ' . $respuesta_code);
                if ($is_authorization) {
                    $this->logData('Detectada operación de confirmación de tarjeta con TxnId: ' . $txnid);
                    $comerciosMetodosPagosObj = ClassLoader::getModelObject('comercios_metodos_pagos', false);
                    $options = array(
                        'filters' => array(
                            'referencia_pedido' => array('=' => $decoded['Ds_Order']),
                            'estado' => array('=' => 'pendiente_validar'),
                        ),
                    );
                    $comercio_metodos_pago_data = $comerciosMetodosPagosObj->getList($options);
                    if (!empty($comercio_metodos_pago_data) && !empty($comercio_metodos_pago_data['data'])) {
                        if (count($comercio_metodos_pago_data['data']) == 1) {
                            $caducidad = $decoded['Ds_ExpiryDate'];
                            $decenio_actual = date('Y');
                            $decenio_actual = substr($decenio_actual, 0, 2);
                            $caducidad_formateada = substr($caducidad, 2) . '/' . substr($caducidad, 0, 2);

                            $mes_caducidad = intval(substr($caducidad, 2));
                            $anio_caducidad = intval($decenio_actual . substr($caducidad, 0, 2));

                            $update_data = array(
                                'id' => $comercio_metodos_pago_data['data'][0]['id'],
                                'estado' => 'validada',
                                'token' => $decoded['Ds_Merchant_Identifier'],
                                'caducidad' => $caducidad_formateada,
                                'caducidad_ano' => $anio_caducidad,
                                'caducidad_mes' => $mes_caducidad,
                                'default' => 1
                            );

                            $update_data['txn_id'] = $txnid;
                            $this->logData('Actualizando método de pago ' . $comercio_metodos_pago_data['data'][0]['id']);
                            $this->logData($update_data);
                            $updated_id = $comerciosMetodosPagosObj->save($update_data);
                            if (!empty($updated_id))
                                $this->logData('Método de pago actualizado correctamente');
                            else
                                throw new Exception('Error realizando la actualización del método de pago');
                        } else {
                            throw new Exception('Se encontró más de 1 tarjeta con el código de pedido "' . $decoded['Ds_Order'] . '"');
                        }
                    } else {
                        throw new Exception('No se encontró ninguna tarjeta con el código de pedido "' . $decoded['Ds_Order'] . '"');
                    }
                } else {
                    // Es el notify de una factura
                    // Limpiamos el código para quitarle, si lo hay, la versión (para evitar que Redsys de error repetido se envía vX con el intento de pago consecutivo
                    $codigo_liquidacion = $decoded['Ds_Order'];
                    $codigo_liquidacion_aux = explode('v', $codigo_liquidacion);
                    $codigo_liquidacion = $codigo_liquidacion_aux[0];
                    $liquidacionesObj = ClassLoader::getModelObject('liquidaciones', false);
                    $options = array(
                        'filters' => array(
                            'codigo' => array('=' => $codigo_liquidacion),
                            //'estado' => array('=' => 'pago_manual'),
                        )
                    );
                    $liqData = $liquidacionesObj->getList($options);
                    if (!empty($liqData) && !empty($liqData['data']) && count($liqData['data']) == 1) {
                        $fecha_hora_cobro = date('Y-m-d H:i:s');
                        $fecha_hora_cobro_txt = date('d/m/Y H:i:s');
                        $liquidacion_a_update = $liqData['data'][0];
                        $liquidacion_id = $liquidacion_a_update['id'];
                        $importe = $decoded['Ds_Amount'];
                        $importe = floatval($importe) / 100;
                        $importe_factura = floatval($liquidacion_a_update['importe_total_con_impuestos']);
                        try {
                            if ($importe != $importe_factura)
                                throw new Exception('No coincide el importe de la factura con el importe pagado.');
                            $liquidacion_update = array(
                                'id' => $liquidacion_id,
                                'estado' => 'pago_manual',
                                'fecha_hora_cobro' => $fecha_hora_cobro
                            );
                            $liquidacion_update['__related__'] = array(
                                'liquidaciones_estados' => array(
                                    array(
                                        'estado' => 'pago_manual',
                                        'texto' => 'Pago realizado correctamente a las ' . $fecha_hora_cobro_txt,
                                    ),
                                ),
                            );
                            $liquidacionesObj->save($liquidacion_update);
                        } catch (Exception $e) {
                            $liquidacion_update = array(
                                'id' => $liquidacion_id,
                                'estado' => 'error',
                            );
                            $liquidacion_update['__related__'] = array(
                                'liquidaciones_estados' => array(
                                    array(
                                        'estado' => 'error',
                                        'texto' => $e->getMessage(),
                                    ),
                                ),
                            );
                            $liquidacionesObj->save($liquidacion_update);
                        }
                    } else
                        throw new Exception('Error buscando liquidación con código ' . $codigo_liquidacion);
                    throw new Exception('Operación NO reconocida');
                }

            } else {
                throw new Exception('No se recibieron los parámetros correctos');
            }
        } catch (Exception $e) {
            $this->logData($e->getMessage(), 'ERROR');
        }
    }

    private function logData($data2append, $type = 'INFO')
    {
        if ($this->logEnabled) {
            $separator = '~@~';
            $message = '';
            $timestamp = date('Y-m-d H:i:s');
            if (!is_string($data2append)) {
                $data2append = json_encode($data2append, JSON_UNESCAPED_UNICODE);
            }
            $uuid = empty($this->uuid) ? '------------------------------------' : $this->uuid;
            $message = $timestamp . $separator . $uuid . $separator . $type . $separator . $data2append . "\n";
            file_put_contents($this->logFile, $message, FILE_APPEND);
        }
    }

    private function setUuid()
    {
        $this->uuid = EntityLib::guidv4();
        if ($this->logEnabled)
            $this->logData('----------- Nueva operación ' . $this->uuid);
    }


}

?>
