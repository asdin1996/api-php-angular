<?php
    session_start();
    date_default_timezone_set('Europe/Madrid');
    $now_string = date('Y-m-d H:i:s');
    $execution_cron_datetime = new DateTime($now_string);

    // Control de cronjob en ejecución
    // Si existe el fichero, comprobamos que lleve más de X minutos. En ese caso borramos para no bloquear permanente.
    // Se quedará el fichero si por cualquier motivo el script no llega a terminar su ejecución.
    $block_cron_file = __DIR__.'/.cron-ejecutandose';
    $limit = 60*10; // Límite: 10 minutos.
    if(file_exists($block_cron_file)) {
        $timestamp = filemtime($block_cron_file);
        $timestamp_now = time();
        $diferencia = $timestamp_now - $timestamp;
        if($diferencia > $limit)
        {
            unlink($block_cron_file);
        }
        else
            launchErrorCron(500,'No se pueden lanzar tareas automáticas porque ya se están ejecutando. Última ejecución: '.$diferencia.'s');
    }

    if(!file_put_contents($block_cron_file,'.'))
    {
        launchErrorCron(500,'No se pudo crear el fichero que marca las tareas cron como en ejecución. No se lanzaron.');
    }

    // Quitamos límites de memoria a peticiones
    ini_set('memory_limit', -1);

    // Requires y procesamiento necesario para gestión de permisos y ejecuciones
    require_once(__DIR__ . '/../api/ApiController.php');
    require_once(__DIR__ . '/../api/ApiException.php');
    require_once(__DIR__ . '/../api/PermisosController.php');
    require_once(__DIR__ . '/../api/ClassLoader.php');
    if(!empty($_SESSION)) {
        session_destroy();
        session_start();
    }
    $lang_id = ApiController::__cronjobLangId;
    $user_id = ApiController::__cronjobUserId;
    $init_session = false;
    if (empty($_SESSION)) $init_session = true;
    if ($init_session) {
        EntityLib::initSession();
        EntityLib::setSession('__lang_id__', $lang_id);
        EntityLib::setSession('__user_id__', $user_id);
        EntityLib::setSession('__rol_ids__', array(1));
    }
    $apiController = new ApiController();
    EntityLib::setSession('__db__', $apiController->getConnection());

    // Cargamos posibles idiomas
    $sql_idiomas = "SELECT id,codigo FROM `_idiomas` WHERE deleted = 0 AND id > 1";
    $idiomas_sesion = array();
    $idiomas_result = $apiController->getConnection()->querySelectAll($sql_idiomas);
    if(!empty($idiomas_result))
    {
        foreach($idiomas_result as $i_r)
        {
            $idiomas_sesion[$i_r['id']] = $i_r['codigo'];
        }
    }
    EntityLib::setSession('__languages__', $idiomas_sesion);

    // Buscamos las tareas programadas que haya que ir lanzando
    $cronjobObj = ClassLoader::getModelObject('_cronjobs',false);
    $cronjobHistoryObj = ClassLoader::getModelObject('_cronjobs_historicos',false);
    $dt_now = date('Y-m-d H:i:s');
    $cronjobOpt = array(
        'filters' => array(
            'deleted' => array('=' => 0),
        ),
        'order' => array(
            'datetime_next' => 'ASC',
        ),
    );
    $cronjobOpt['filters'][] = array(
        '__union__' => 'OR',
        '__conditions__' => array(
            array(
                'datetime_next' => array('<=' => $dt_now),
            ),
            array(
                'datetime_next' => array('IS' => null),
            ),
        ),
    );

    // Controlamos parámetros FORCE y DEBUG por si se han establecido en la petición
    if(!empty($argv))
    {
        $argv_key = 0;
        for($argv_key = 1;$argv_key < count($argv); $argv_key++)
        {
            $aux_argv = explode('=',$argv[$argv_key]);
            $_GET[$aux_argv[0]] = $aux_argv[1];
        }
    }
    $force_cronjob = (!empty($_GET) && !empty($_GET['force'])) ? $_GET['force'] : '';
    $debug = (!empty($_GET) && !empty($_GET['debug']));
    $write_to_file_default = (!empty($_GET) && !empty($_GET['debug-file'])) ? $_GET['debug-file'] : 0;

    if(!empty($force_cronjob))
    {
        $cronjobOpt['filters'] = array('nombre' => array('=' => $force_cronjob));
    }
    else
    {
        // Si no estamos forzando, filtraremos porque sea una tarea activa
        $cronjobOpt['filters']['activa'] = array('=' => 1);
    }

    // En caso de tener que guardar en fichero, preparamos
    $base_path_log_system = __DIR__.'/../log/cronjob/';
    $date_log_system_1 = date('Y');
    $date_log_system_2 = date('m');

    // Buscamos las tareas y ejecutamos individualmente
    $cronData = $cronjobObj->getList($cronjobOpt);
    if(!empty($cronData) && !empty($cronData['data']))
    {
        foreach($cronData['data'] as $cj)
        {
            $write_to_file = $write_to_file_default;
            $task_path = !empty($cj['nombre']) ? $cj['nombre'] : $cj['script_url'];
            $full_log_path = null;
            $last_log_path = null;

            // Preparamos la tarea que se está ejecutando para modificar sus tiempos
            $update_cron = array(
                'id' => $cj['id'],
            );
            // Preparamos el histórico de ejecución del cronjob
            $cron_history = array(
                'cronjob_id' => $cj['id'],
            );
            $cronjob_execution_dt = date('Y-m-d H:i:s');
            $cron_history['datetime_inicio'] = $cronjob_execution_dt;

            try {
                $interval_value = !empty($cj['intervalo']) ? $cj['intervalo'] : 0;
                $interval_cron = !empty($cj['intervalo_cron']) ? $cj['intervalo_cron'] : null;
                $interval = $interval_value;
                $interval_new_date = null;
                $interval_type = 1;
                $cron_year = intval($execution_cron_datetime->format('Y'));
                $cron_month = intval($execution_cron_datetime->format('m'));
                $cron_dw = intval($execution_cron_datetime->format('N'));
                $cron_hm = $execution_cron_datetime->format('H:i');
                if(!empty($interval_cron))
                {
                    try {
                        $ic_config = json_decode($interval_cron, JSON_UNESCAPED_UNICODE);
                        if (!empty($ic_config)) {
                            $ic_time = !empty($ic_config['T']) ? $ic_config['T'] : null;

                            if(!empty($ic_time)) {
                                $interval_type = 2;

                                $ic_week = !empty($ic_config['W']) ? $ic_config['W'] : null;
                                $ic_month = !empty($ic_config['M']) ? $ic_config['M'] : null;

                                if (!empty($ic_month)) {
                                    $cron_month++;
                                    if ($cron_month > 12) {
                                        $cron_month = 1;
                                        $cron_year++;
                                    }
                                    $cron_day = $ic_month;
                                }
                                if (!empty($ic_week)) {
                                    $dias_anadir_fecha = 7 + $ic_week - $cron_dw;
                                    $today_date = $execution_cron_datetime->format('Y-m-d');
                                    $cron_aux = new DateTime(date("Y-m-d",strtotime($today_date.'+ '.$dias_anadir_fecha.' days')));
                                    $cron_year = intval($cron_aux->format('Y'));
                                    $cron_month = intval($cron_aux->format('m'));
                                    $cron_day = intval($cron_aux->format('d'));
                                }
                                if(!empty($ic_time))
                                {
                                    if(is_array($ic_time))
                                    {
                                        // Aquí recorremos las horas para ver el siguiente tramo
                                        $interval_found = null;
                                        foreach($ic_time as $ict_key => $ict)
                                        {
                                            if($ict > $cron_hm) $interval_found = $ict_key;
                                            if($interval_found) break;
                                        }
                                        $next_day = false;
                                        if(empty($interval_found))
                                        {
                                           $next_day = true;
                                           $interval_found = 0;
                                        }
                                        $ic_time = $ic_time[$interval_found];
                                        $today_date = $execution_cron_datetime->format('Y-m-d');
                                        $str_plus = '';
                                        if($next_day) $str_plus = '+ 1 days';
                                        $cron_aux = new DateTime(date("Y-m-d",strtotime($today_date.$str_plus)));
                                        $cron_year = intval($cron_aux->format('Y'));
                                        $cron_month = intval($cron_aux->format('m'));
                                        $cron_day = intval($cron_aux->format('d'));
                                    }
                                }

                                $new_date = new DateTime();
                                $new_date->setDate($cron_year, $cron_month, $cron_day);
                                $new_date_str = $new_date->format('Y-m-d') . ' ' . $ic_time.':00';
                                $new_date = new DateTime(date('Y-m-d H:i:s', strtotime($new_date_str)));
                                $interval_new_date = $new_date->format('Y-m-d H:i:s');
                            }
                            else
                            {
                                throw new Exception('1#Configuración de intervalo cron erronea: {"M":1,"T":"00:01:00"} || {"W":1,"T":"00:01:00"} || {"H":[4,6,8]');
                            }
                        }
                        else
                        {
                            throw new Exception('2#Configuración de intervalo cron erronea: {"M":1,"T":"00:01:00"} || {"W":1,"T":"00:01:00"}');
                        }

                        if(empty($interval_new_date))
                        {
                            throw new Exception('3#Configuración de intervalo cron erronea: {"M":1,"T":"00:01:00"} || {"W":1,"T":"00:01:00"}');
                        }
                    }catch(Exception $e)
                    {
                        // Si el intervalo por fecha no es correcto y no hay, se define cada 15 minutos
                        debugCron($e->getMessage());
                        debugCron($ic_config);
                        $interval_type = 1;
                        if (empty($interval)) $interval = 15;
                        debugCron('Intervalo aplicado en su lugar: '.$interval);
                    }

                }

                // Si el intervalo se define por tiempo, se considera 1 min como mínimo
                if($interval_type == 1) {
                    if (empty($interval) || $interval <= 0)
                        $interval = 1;

                    // Pasamos al sistema 2 y calculamos la fecha
                    $today_date = $execution_cron_datetime->format('Y-m-d H:i').':00';
                    $new_date = new DateTime(date("Y-m-d H:i:s",strtotime($today_date.'+ '.$interval.' minutes')));
                    $interval_new_date = $new_date->format('Y-m-d H:i:s');
                }

                if(empty($cj['script_url']))
                    throw new Exception('No se ha configurado el script asociado a la tarea automática');
                $script_url = __DIR__.'/'.$cj['script_url'];
                if(!file_exists($script_url))
                    throw new Exception('No se ha encontrado el fichero de script: '.$script_url);

                $_GET['script_params'] = array();
                // Metemos parámetros que no sean force o script_params del get a la petición
                $exclude_params = array('force', 'script_params');
                if(!empty($_GET)) {
                    foreach ($_GET as $get_param => $get_value) {
                        if (!in_array($get_param, $exclude_params)) {
                            $_GET['script_params'][$get_param] = $get_value;
                        }
                    }
                }

                // Pasamos los valores definidos a nivel de configuración de tarea (mandan estos sobre los del $_GET)
                if(!empty($cj['params'])) {
                    $cron_params = json_decode($cj['params'], JSON_UNESCAPED_UNICODE);
                    if(is_null($cron_params)) $cron_params = array();
                    foreach($cron_params as $cron_param => $cron_param_value)
                    {
                        $_GET['script_params'][$cron_param] = $cron_param_value;
                    }
                }

                if(!empty($_GET['script_params']) && !empty($_GET['script_params']['debug-file']))
                    $write_to_file = intval($_GET['script_params']['debug-file']);
                
                ob_start();
                include($script_url);
                $output = ob_get_contents();
                ob_end_clean();
                
                if($debug)
                {
                    echo '<pre>';print_r($output);echo '</pre>';
                }

                // Si finaliza correctamente, marcamos los datos de última y próxima ejecución
                $datetime_last = $execution_cron_datetime->format('Y-m-d H:i:s');

                // Modificaremos, en la tarea, los tiempos de última y próxima ejecución
                $update_cron['datetime_last'] = $datetime_last;
                $update_cron['datetime_next'] = $interval_new_date;

                // Asignamoes el resultado en la ejecucíon de la tarea cron
                $cron_history['last_result'] = $output;
            }catch(Exception $e)
            {
                if($debug)
                {
                    echo '<pre>';print_r($e->getMessage());echo '</pre>';
                }
                $update_cron = array(
                    'id' => $cj['id'],
                    'datetime_last' => $execution_cron_datetime->format('Y-m-d H:i:s'),
                );
                $cron_history['is_error'] = true;
                $cron_history['last_result'] = $e->getMessage();
            }

            $cronjob_execution_end_dt = date('Y-m-d H:i:s');
            $cron_history['datetime_fin'] = $cronjob_execution_end_dt;


            // Log en fichero
            if(!empty($write_to_file))
            {
                $full_output = '<html><head><meta charset="UTF-8"></head><body>'.$output.'</body></html>';

                // Comprobamos que existan directorios
                $base_path_log_system_with_date = $base_path_log_system.'/'.$date_log_system_1.'/'.$date_log_system_2.'/';
                // Si no existe directiorio de log los creamos
                if(!empty($write_to_file)) {
                    if (!is_dir($base_path_log_system))
                        mkdir($base_path_log_system);
                    $log_folder_1 = $base_path_log_system.'/'.$date_log_system_1.'/';
                    if($write_to_file === 2) {
                        if (!is_dir($log_folder_1))
                            mkdir($log_folder_1);
                        $log_folder_2 = $log_folder_1 . $date_log_system_2 . '/';
                        if (!is_dir($log_folder_2))
                            mkdir($log_folder_2);
                    }
                }

                // Escribimos el log por fecha si se indica
                if($write_to_file === 2) {
                    $full_log_path = $base_path_log_system_with_date.$task_path.'.log';
                    file_put_contents($full_log_path, '-----'.$now_string.'-----'."\n".$full_output."\n", FILE_APPEND);
                }
                // El último lo escribimos siempre que se active funcionalidad
                $last_log_path = $base_path_log_system.'_last-'.$task_path.'.html';
                file_put_contents($last_log_path, $full_output);
            }
            $cronjobObj->save($update_cron);
            $cronjobHistoryObj->save($cron_history);
        }
    }

    // Borramos el fichero de cron puesto que se ha terminado la ejecución
    if(file_exists($block_cron_file))
    {
        if(!unlink($block_cron_file))
            launchErrorCron(500,'No se pudo eliminar el fichero para desbloquear las tareas cron aunque exista.');
    }
    else
        launchErrorCron(500,'No se pudo eliminar el fichero para desbloquear las tareas cron porque no se encontró.');


    function launchErrorCron($code,$message)
    {
        header("HTTP/1.1 ".$code." ".$message);
        echo $message;
        exit;
    }
    
    function debugCron($msgOrItem)
    {
        echo '<pre>';print_r($msgOrItem);echo '</pre>';
    }
?>