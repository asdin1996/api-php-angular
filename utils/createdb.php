<?php

require_once(__DIR__.'/../api/ApiController.php');
require_once(__DIR__.'/../api/EntityLib.php');


// Quitamos límites a peticiones
ini_set('memory_limit',-1);
// Registramos hora inicio
$time_in = gettimeofday();

$apiController = new ApiController();
$apiController->beginTransaction();
$dbConn = $apiController->getConnection();

$sqlInserts = array();
$tables = array();
if(!empty($_GET['table']))
    $tables = explode(",",$_GET['table']);

$skip_internal = empty($tables);
try {
    $schemaName = $dbConn->getSchema();
    $sqlSearchTables = "SELECT `table_name` FROM `information_schema`.`tables`";
    $sqlSearchTables .= " WHERE `table_schema` = '".$schemaName."'";
    if(!empty($tables))
    {
        $implodedStr = "'".implode("','",$tables)."'";
        $sqlSearchTables .= "AND `table_name` IN (".$implodedStr.")";
    }
    $sqlSearchTables .= " ORDER BY `table_name`";

    $queryResult = $dbConn->querySelectAll($sqlSearchTables);
    $tableNames = array();
    foreach($queryResult as $tn) {
        // IMPORTANTE! El information_schema hace algo raro con la cadena y no puedo filtrar, hay que filtrar tras obtener registros :(
        if(!empty($tn['table_name']))
            $tableNames[] = $tn['table_name'];
        else if(!empty($tn['TABLE_NAME']))
            $tableNames[] = $tn['TABLE_NAME'];
    }
    
    $sqlSearchId = "SELECT (MAX(id)+1) as sig FROM __schema_tables WHERE `deleted` = 0";
    $queryResult = $dbConn->querySelectAll($sqlSearchId);
    // Tablas a partir de la 1000
    $sig = 10000;
    if(!empty($queryResult))
        $sig = $queryResult[0]['sig'];
    if($sig < 10000)
        $sig = 10000;

    $types = array();
    foreach($tableNames as $tn)
    {
        $skip = false;
        if((!empty($tables) && in_array($tn,$tables)) || empty($tables)) {
            $first_letter = substr($tn,0,1);
            $is_internal = $first_letter == '_';
            $skip = $is_internal && $skip_internal;

            if(!$skip) {
                echo '<pre style="color: green;">';
                print_r('Buscamos info de la tabla <b>' . $tn . '</b>');
                echo '</pre>';
                $tabla_id = 0;
                $sqlSearch1 = "SELECT id FROM __schema_tables WHERE `table` = '" . $tn . "' and `deleted` = 0";
                $queryResult = $dbConn->querySelectAll($sqlSearch1);
                if (empty($queryResult)) {
                    $sqlInsertTable = "INSERT INTO __schema_tables (id,`table`,api_call,entity,entity_name_one,entity_name_multiple) VALUES (" . $sig . ",'" . $tn . "','" . $tn . "','Entity','" . $tn . "','" . $tn . "')";
                    $sqlInserts[] = $sqlInsertTable;
                    //$dbConn->query($sqlInsertTable);
                    $table_id = $sig;
                    $sig++;
                } else {
                    $table_id = $queryResult[0]['id'];
                    echo '<pre style="color: orange;">';
                    print_r('La tabla "' . $tn . '" ya existe con el ID "' . $table_id . '". No se creará pero se revisarán sus campos.');
                    echo '</pre>';
                }

                $sqlSearchFieldExists = "SELECT * FROM __schema_fields WHERE table_id = " . $table_id;
                $queryResultExisting = $dbConn->querySelectAll($sqlSearchFieldExists);
                $sqlSearch2 = "SHOW FULL COLUMNS FROM " . $tn . " WHERE Field NOT IN ('id','datetime_add','datetime_upd','datetime_del','user_add_id','user_upd_id','user_del_id','deleted')";
                $queryResult = $dbConn->querySelectAll($sqlSearch2);

                $existingFields = array();
                $last_field_id_for_table = ($table_id * 100);
                $increment_last = false;
                $last_order = 0;
                foreach ($queryResultExisting as $key => $qre) {
                    $existingFields[$qre['field']] = $qre;
                    $aux = $qre['id'];
                    $aux2 = $qre['field_order'];
                    if ($aux > $last_field_id_for_table) {
                        $last_field_id_for_table = $aux;
                        $increment_last = true;
                    }
                    if ($aux2 > $last_order)
                        $last_order = $aux2;
                }
                if($increment_last)
                    $last_field_id_for_table = intval($last_field_id_for_table) + 1;

                $fieldsForTable = array();
                foreach ($queryResult as $dbField) {

                    $field_exists = !empty($existingFields[$dbField['Field']]);
                    if (!$field_exists) {
                        $type = 'int(11)';
                        $miniType = substr($dbField['Type'], 0, 3); // los 3 primeros
                        $field = array(
                            'table_id' => $table_id,
                            'field' => $dbField['Field'],
                            'label' => $dbField['Field'],
                            'type' => '',
                            'id' => $last_field_id_for_table,
                        );
                        switch ($miniType) {
                            case 'dec' :
                                $type = 'decimal';
                                $aux = explode('(', $dbField['Type']);
                                $aux = substr($aux[1], 0, strlen($aux[1]) - 1);
                                $aux = explode(',', $aux);
                                $field['decimal_precission'] = $aux[1];
                                break;
                            case 'int' :
                                $type = 'int';
                                $is_related = substr($dbField['Field'], -3) === '_id';
                                if ($is_related) {
                                    $type = 'related';
                                    $field['related_options'] = '[PTE NAME TABLA PADRE]';
                                }
                                break;
                            case 'var' :
                                $type = 'varchar';
                                $aux = explode('(', $dbField['Type']);
                                $aux = substr($aux[1], 0, strlen($aux[1]) - 1);
                                $field['max_size'] = $aux;
                                break;
                            case 'tex' :
                                $type = 'text';
                                $field['max_size'] = 2048;
                                break;
                            case 'dat' :
                                $type = ($dbField['Type'] === 'datetime') ? 'datetime' : 'date';
                                break;
                            case 'tin' :
                                switch ($dbField['Type']) {
                                    case 'tinytext' :
                                        $type = 'text';
                                        break;
                                    case 'tinyint' :
                                        $type = 'boolean';
                                        break;
                                }
                                break;
                            case 'tim' :
                                $type = 'time';
                                break;
                            case 'enu' :
                                $type = 'enum';
                                $aux = explode('(', $dbField['Type']);
                                $aux = substr($aux[1], 0, strlen($aux[1]) - 1);
                                $aux = explode(',', $aux);
                                $options = array();
                                foreach ($aux as $option)
                                    $options[$option] = $option;
                                $jsonOptions = json_encode($options, JSON_UNESCAPED_UNICODE);
                                $jsonOptions = str_replace("'", '', $jsonOptions);
                                $field['enum_options'] = $jsonOptions;
                                break;
                            default :
                                $type = $dbField['Type'];
                                break;
                        }
                        $field['type'] = $type;
                        if (!in_array($type, $types))
                            $types[] = $type;
                        $fieldsForTable[] = $field;
                        $last_field_id_for_table += 1;
                    } else {
                        //echo '<pre style="color: orange;">';print_r('Se saltó el campo "' . $dbField['Field'] . '" porque ya existe.');echo '</pre>';
                    }
                }

                $order = $last_order;

                foreach ($fieldsForTable as $field) {
                    $order++;
                    $field['field_order'] = $order;
                    $sql_insert = EntityLib::generateSql('__schema_fields', 'INSERT', $field);
                    $sqlInserts[] = $sql_insert;
                    echo '<pre style="color: red; font-weight: bold;">';
                    print_r('Se marcó para crear el campo "' . $field['field'] . '" porque NO existe.');
                    echo '</pre>';
                }
            }

        }
    }

    foreach($sqlInserts as $query)
    {
        $dbConn->query($query);
        echo '<pre>';print_r($query);echo '</pre>';
    }
    $apiController->commitTransaction();
}catch(Exception $e)
{
    $apiController->rollbackTransaction();
    echo '<pre>';print_r('ERROR! '.$e->getMessage());echo '</pre>';
}

// Calculamos tiempo de ejecución
$time_out = gettimeofday();
$diff_seconds = $time_out['sec'] - $time_in['sec'];
$diff_miliseconds = $time_out['usec'] - $time_in['usec'];
if($diff_miliseconds < 0) {
    $diff_seconds = $diff_seconds - 1;
    $diff_miliseconds = $diff_miliseconds * (-1);
}
$diff = floatval($diff_seconds.'.'.$diff_miliseconds);


echo '<pre>';print_r('Proceso finalizado en '.$diff.'s');echo '</pre>';

?>