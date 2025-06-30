<?php
require_once(__DIR__.'/../api/Entity/Base/Usuario.php');
require_once(__DIR__.'/../api/ApiController.php');
$apiController = new ApiController();
$_SESSION['__db__'] = $apiController->getConnection();
$db = $_SESSION['__db__'];

echo '<pre>';print_r('Forzando a mover traducciones de tablas no del sistema');echo '</pre>';
$min_id = 100000;
$config = array(
    'label' => array('field' => 'valor_varchar'),
    'help' => array('field' => 'valor_txt'),
);
$idiomas = array(2);

$movidos = 0;
$last_id_query = "SELECT id FROM _i18n_fields WHERE tabla NOT IN ('__schema_fields','__schema_fields','_menus')";
$trads_to_move = $db->querySelectAll($last_id_query);
if(!empty($trads_to_move))
{
    $update_to_id = $min_id;
    foreach($trads_to_move as $tm)
    {
        $update_to_id++;
        $update_query = "UPDATE _i18n_fields SET id = ".$update_to_id." WHERE id = ".$tm['id'];
        $db->query($update_query);
        $movidos++;
    }
}
echo '<pre>';print_r('Se movieron '.$movidos.' elementos');echo '</pre>';




?>

