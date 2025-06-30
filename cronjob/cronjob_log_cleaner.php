<?php

require_once(__DIR__.'/../api/ApiController.php');
require_once(__DIR__.'/../api/ClassLoader.php');
require_once(__DIR__.'/../api/EntityLib.php');
require_once(__DIR__ . '/../api/Mailer.php');
$apiController = new ApiController();
$_SESSION['__db__'] = $dbObject = $apiController->getConnection();

$dias_log = (!empty($_GET) && !empty($_GET['script_params']) && !empty($_GET['script_params']['normal'])) ? intval($_GET['script_params']['normal']) : 7;
$dias_log_error = (!empty($_GET) && !empty($_GET['script_params']) && !empty($_GET['script_params']['errors'])) ? intval($_GET['script_params']['errors']) : 7;
if($dias_log <= 0) $dias_log = 1;
if($dias_log_error <= 0) $dias_log_error = 1;

// Calculamos las fechas para el SQL a partir de fecha actual
// Eliminamos logs sin error más viejos de 3 días
// Eliminamos logs de errores tras 1 mes
$actual_datetime = date('Y-m-d H:i:s');
$fecha = new DateTime($actual_datetime);
$fecha->sub(new DateInterval('P'.$dias_log.'D'));
$fecha_eliminar = $fecha->format('Y-m-d H:i:s');
$fecha_old_error = new DateTime($actual_datetime);
$fecha_old_error->sub(new DateInterval('P'.$dias_log_error.'D'));
$fecha_eliminar_errores = $fecha_old_error->format('Y-m-d H:i:s');

$sql_delete = "DELETE FROM _cronjobs_historicos WHERE datetime_add <= ? AND is_error = 0";
$sql_delete_old_errors = "DELETE FROM _cronjobs_historicos WHERE datetime_add <= ? AND is_error = 1";
$sql_params_1 = array($fecha_eliminar);
$sql_params_2 = array($fecha_eliminar_errores);

$dbObject->query($sql_delete,$sql_params_1);
$dbObject->query($sql_delete_old_errors,$sql_params_2);
$result = array(
    'fecha_lanzamiento' => $actual_datetime,
    'fecha_eliminar' => $fecha_eliminar,
    'fecha_eliminar_errores' => $fecha_eliminar_errores,
);
echo '<pre>';print_r($result);echo '</pre>';
?>