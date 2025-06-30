<?php
    require_once(__DIR__.'/../api/Entity/Base/Usuario.php');
    require_once(__DIR__.'/../api/ApiController.php');
    $apiController = new ApiController();
    $_SESSION['__db__'] = $apiController->getConnection();


    $mailer = new Mailer();
    $asunto = 'Prueba de envío de correo electrónico OK';
    $mensaje = 'Si recibe este email correctamente, la configuración de envío es correcta.';
    $template = 'base/test.php';
    $params = array(
        '{$1}' => date('Y-m-d H:i:s')
    );
    $mailer->setBaseTemplate('mail_sin_pie.php');
    $sent = $mailer->sendTemplate(array('alberto.garcia@agenciaekiba.com'),$template,$asunto,$params,true);

    
    echo '<pre>';print_r($sent);echo '</pre>';

?>

