<?php
try{
    $script_params = (!empty($_GET) && !empty($_GET['script_params'])) ? $_GET['script_params'] : $_GET;
    $pagesize = (!empty($_GET) && !empty($_GET['pagesize'])) ? $_GET['pagesize'] : 5;
    $sleep = (!empty($_GET) && !empty($_GET['sleep'])) ? $_GET['sleep'] : 5;
    // Sleep forzado entre 5 y 15
    if($sleep < 5) $sleep = 5;
    else if($sleep > 15) $sleep = 15;
    $colaEnvioObj = ClassLoader::getModelObject('_mail_send',false);
    $options = array(
        'filters' => array(
            'estado' => array('IN' => array('pendiente','error')),
        ),
        'order' => array(
            'estado' => 'ASC',
            'datetime_ultimo_intento' => 'DESC',
            'id' => 'ASC',
        ),
        'pagesize' => $pagesize,
        'page' => 1
    );
    $options['filters'][] = array(
        '__union__' => 'OR',
        '__conditions__' => array(
            array(
                'numero_intentos' => array('<=' => 3),
            ),
            array(
                'numero_intentos' => array('IS' => null),
            ),
        )
    );
    $envios_result = $colaEnvioObj->getList($options);
    $envios_pendientes = (!empty($envios_result) && !empty($envios_result['data'])) ? $envios_result['data'] : array();
    $files_to_delete = array();
    $first = true;
    if(!empty($envios_pendientes))
    {
        $total = count($envios_pendientes);
        $contador = 0;
        debugCron('Se han encontrado '.$total.' envíos pendientes de realizar');
        foreach($envios_pendientes as $envio)
        {
            $hora_sistema = date('Y-m-d H:i:s');
            $contador++;
            $intento_actual = !empty($envio['numero_intentos']) ? intval($envio['numero_intentos']) : 0;
            $intento_actual++;
            $envio_update = array(
                'id' => $envio['id'],
                'numero_intentos' => $intento_actual,
                'datetime_ultimo_intento' => $hora_sistema,
            );
            try {
                debugCron('Realizando envío ' . $contador . ' de ' . $total);
                debugCron($envio);
                $to = explode(';', $envio['para']);

                $mailer = new Mailer();
                if (!$envio['interno'])
                    $mailer->setBaseTemplate('mail_sin_pie.php');

                if(!empty($envio['copia']))
                    $mailer->setCC(explode(';',$envio['copia']));
                if(!empty($envio['copia_oculta']))
                    $mailer->setBCC(explode(';',$envio['copia_oculta']));

                $asunto = $envio['asunto'];
                $params = !empty($envio['params']) ? json_decode($envio['params'], JSON_UNESCAPED_UNICODE) : array();
                $adjuntos = !empty($envio['adjuntos']) ? json_decode($envio['adjuntos'], JSON_UNESCAPED_UNICODE) : array();

                $files_to_delete_from_this_mail = array();
                if (!empty($adjuntos)) {
                    $array_mailer_adjuntos = array();
                    foreach ($adjuntos as $adj) {
                        $new_adjunto = array(
                            'absolute_path' => $adj['file'],
                            'name' => $adj['name'],
                        );
                        if (!empty($adj['delete']))
                            $files_to_delete_from_this_mail[] = $adj['file'];
                        $array_mailer_adjuntos[] = $new_adjunto;
                    }
                    $params['attachments'] = $array_mailer_adjuntos;
                }

                // Nos aseguramos que se dejan pasar X segundos (definible por parámetro del script) entre envíos
                if(!$first)
                    sleep($sleep);
                else
                    $first = false;
                $resultMail = $mailer->sendTemplate($to, $envio['plantilla'], $asunto, $params);
                $mail_sent = !empty($resultMail) && !empty($resultMail['success']);
                if ($mail_sent) {
                    debugCron(EntityLib::__('API_MAIL_SENT_OK_MESSAGE'));
                    $envio_update['estado'] = 'enviado';
                    $envio_update['observaciones'] = EntityLib::__('API_MAIL_SENT_OK_MESSAGE');
                    if(!empty($files_to_delete_from_this_mail))
                    {
                        foreach($files_to_delete_from_this_mail as $ftdftm)
                            $files_to_delete[] = $ftdftm;
                    }

                    // Gestionamos el after_send
                    if(!empty($envio['after_send']))
                    {
                        try {
                            $aux_envio = explode('#',$envio['after_send']);
                            if(!empty($aux_envio))
                            {
                                $ent = $aux_envio[0];
                                $acc = $aux_envio[1];
                                $id = $aux_envio[2];
                                $entityObj = ClassLoader::getModelObject($ent,false);
                                $method_exists = method_exists($entityObj,$acc);
                                if($method_exists) $entityObj->$acc($id);
                            }
                        } catch(Exception $e)
                        {

                        }
                    }

                }
                else
                {
                    debugCron($resultMail);
                    $mail_error = (!empty($resultMail) && !empty($resultMail['message'])) ? $resultMail['message'] : '';
                    $msg = EntityLib::__('API_MAIL_SENT_KO_MESSAGE');
                    if(!empty($mail_error))
                        $msg .= ': '.$mail_error;
                    throw new Exception($msg);
                }
            }catch(Exception $e)
            {
                $envio_update['estado'] = 'error';
                $envio_update['observaciones'] = $e->getMessage();
            }
            debugCron('Actualizando estado envío');
            debugCron($envio_update);
            $colaEnvioObj->save($envio_update);
        }
    }
    else
    {
        debugCron('No se encontraron envíos pendientes de realizar');
    }
    if(!empty($files_to_delete))
    {
        foreach($files_to_delete as $ftd)
        {
            debugCron('Borrando fichero adjunto enviado correctamente: '.$ftd);
            unlink($ftd);
        }
    }
}
catch(Exception $e)
{
    die($e->getMessage());
}

?>