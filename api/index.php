<?php

$__is_dev = false;
if(!empty($_SERVER) && !empty($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'],'agenciaekiba.com') !== false)
{
    $__is_dev = true;
}
if($__is_dev)
{
    ini_set('display_errors', '1');
}
else
{
    ini_set('display_errors', '0');
}
// Quitamos límites a peticiones
ini_set('memory_limit', -1);
date_default_timezone_set('Europe/Madrid');
// Registramos hora inicio
$time_in = gettimeofday();
$debug_time_in = time();
$token = '';
require_once(__DIR__ . '/ApiController.php');
require_once(__DIR__ . '/ApiException.php');
require_once(__DIR__ . '/PermisosController.php');
require_once(__DIR__ . '/ClassLoader.php');
require_once(__DIR__ . '/Entity/CustomController.php');
require_once(__DIR__ . '/Entity/Base/Configuracion.php');
$forzar_logout_user = false;
$mantener_mensaje_logout = false;
$is_userapi = false;
$result = array(
    'success' => true,
    'result' => null,
    'debug' => array(),
);

// INICIALIZAMOS SESIÓN!
session_start();
$apiController = new ApiController();
$lang_id = $apiController::__masterLangId;
$init_session = false;
if (empty($_SESSION)) $init_session = true;
if ($init_session) {
    EntityLib::initSession();
    EntityLib::setSession('__lang_id__', $lang_id);
}

EntityLib::setSession('__db__', $apiController->getConnection());
try {
    $apiController->beginTransaction();
    $params_get = !empty($_GET) ? $_GET : array();
    $params_post = $_POST;
    if (empty($params_post)) {
        $json_post = file_get_contents("php://input");
        $_POST = json_decode($json_post, true);
    }
    $params_post = !empty($_POST) ? $_POST : array();
    $params = array_merge($params_get, $params_post);
    if(!empty($params['action'])) $params['__source_action__'] = $params['action'];

    // Recojemos, en caso de venir, el campo del idioma en la petición
    if(!empty($params) && !empty($params['__language_id__']))
    {
        $lang_id = $params['__language_id__'];
        unset($params['__language_id__']);
    }
    EntityLib::setSession('__lang_id__', $lang_id);

    // Comprobamos si estamos en mantenimiento
    if(file_exists(__DIR__.'/.mantenimiento'))
    {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        $configObj = new Configuracion();
        $ips_mantenimiento = array();
        $aux = $configObj->getConfig('app_ips_mantenimiento');
        $mensaje_mant = $configObj->getConfig('app_mensaje_mantenimiento');
        if(!empty($aux)) $ips_mantenimiento = explode(',',$aux);
        if(!in_array($ip,$ips_mantenimiento))
        {
            $is_login_request = !empty($params) && !empty($params['action']) && in_array($params['action'],array('login','login_sso_from_cookie','create_user'));
            //$forzar_logout_user = !$is_login_request;
            $forzar_logout_user = true;
            $mantener_mensaje_logout = true;
            throw new Exception($mensaje_mant);
        }
    }

    // Solo hay que chekear el usuario en determinadas peticiones, donde se espera TOKEN de sesión
    $check_login_for_request = !in_array($params['action'], array('login','login_sso_from_cookie','recover', 'recover_start', 'recover_end', 'create_user','cronjob','redsysOk','redsysKo','redsysRequest','redsysNotify'));
    if ($check_login_for_request) {
        EntityLib::initSession();
        EntityLib::setSession('__db__', $apiController->getConnection());
    }

    // Forzamos reinicio dimensiones del usuario
    EntityLib::setSession('__dimension__', array());
    unset($_SESSION['__user_id__']);
    unset($_SESSION['__rol_ids__']);
    unset($_SESSION['__rol_codes__']);
    unset($_SESSION['__sql__']);
    unset($_SESSION['__navigation__']);

    // Habilitamos caché o no (deshabilitado, la de MySQL propia es mejor para consultas largas)
    $habilitar_cache = false;
    EntityLib::setSession('__cache__', $habilitar_cache);
    if($habilitar_cache) {
        EntityLib::setSession('__cachedata__', array());
    }
    EntityLib::setSession('__queries_cached__', 0);
    EntityLib::setSession('__queries_executed__', 0);
    EntityLib::setSession('__all_query__', array());
    EntityLib::setSession('__last_query__', '');
    //EntityLib::setSession('__debug_backtrace__', array());


    // Aquello que venga en __session__ se considerará dimensión, excepto lang_id
    // Comento - Ya se ha gestionado antes!
    /*
    if (!empty($params) && !empty($params['__session__'])) {
        $params_session_keys = array_keys($params['__session__']);
        foreach ($params_session_keys as $psk) {
            $ps_value = $params['__session__'][$psk];
            switch ($psk) {
                case 'lang_id' :
                    //$_SESSION['__lang_id__'] = $lang_id;
                    EntityLib::setSession('__lang_id__', $lang_id);
                    break;
            }
        }
    }
    */

    if (!empty($params) && !empty($params['table'])) {
        $sess_controller = $params['table'];
        $sess_action = !empty($params['action']) ? $params['action'] : 'list';
        EntityLib::setSession('__controller__', $sess_controller);
        EntityLib::setSession('__action__', $sess_action);
    }

    if (empty($params))
        throw new Exception(EntityLib::__('API_NO_PARAMS_REQUEST'));

    $result['debug']['params_total'] = $params;

    // Solo para pruebas y hacer invocaciones por URL y ver que se devuelve


    $result['debug']['initial_request'] = $params;

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

    if ($check_login_for_request) {
        if (empty($token))
            $token = getAuthorizationHeader();

        if (empty($token)) {
            $forzar_logout_user = true;
            throw new Exception(EntityLib::__('API_NO_AUTH'));
        }
        // A partir de aquí versión definitiva
        $result['debug']['token'] = $token;
        $check_ip = false;
        $wrong_token_str = EntityLib::__('API_WRONG_TOKEN');
        try {
            $request_user = $apiController->authUser($token);

            $request_user_id = !empty($request_user['id']) ? $request_user['id'] : null;
            $request_user_name = !empty($request_user['name']) ? $request_user['name'] : null;
            $check_ip = !empty($request_user['allowed_ips']);
            if (empty($request_user_id)) {
                throw new Exception($wrong_token_str);
            }
        } catch (Exception $e) {
            $forzar_logout_user = true;
            $launch_error = $e->getMessage();
            if(empty($launch_error))
            {
                $launch_error = $wrong_token_str;
            }
            if($launch_error != $wrong_token_str)
            {
                $mantener_mensaje_logout = true;
            }
            // No distinguimos mensaje de error para no dar pistas. No se sabe si el usuario existe o no, solo que el token no es correcto.
            throw new Exception($launch_error);
        }
        if($check_ip)
        {
            $ip = '';
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } else {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
            $ips_permitidas = array();
            if(!empty($request_user['allowed_ips'])) $ips_permitidas = explode(',',$request_user['allowed_ips']);
            if(!in_array($ip,$ips_permitidas))
            {
                $forzar_logout_user = true;
                $mantener_mensaje_logout = true;
                throw new Exception(EntityLib::__('No puede iniciar sesión en el sistema ya que no está conectando desde una de sus IPs permitidas.'));
            }
        }

        $result['debug']['session'] = $request_user_id;
        $_SESSION['__user_id__'] = $request_user_id;
        $_SESSION['__user_name__'] = $request_user_name;
        EntityLib::setSession('__user_id__', $request_user_id);

        // Comprobamos si el usuario indicado tiene permiso o no para realizar la acción
        // Primero obtenemos su rol, posteriormente obtenemos si tiene o no permisos para realizar la acción
        $user_id = $request_user_id;

        $userObject = ClassLoader::getModelObject('usuarios');
        $userObject->setIgnoreDef(true);
        $rolObject = ClassLoader::getModelObject('roles');
        $rolObject->setIgnoreDef(true);
        $user_data = $userObject->getById($user_id);
        $userRolObject = ClassLoader::getModelObject('usuarios_roles', true);
        $userRolObject->setIgnoreDef(true);
        $filters = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'usuario_id' => array('=' => $user_id),
            ),
            'order' => array('rol_id' => 'ASC'),
        );
        $user_roles = $userRolObject->getList($filters);
        $rol_ids_for_user = array();
        $rol_codes_for_user = array();
        if (empty($rol_ids_for_user)) {
            /*if (!empty($user_data) && !empty($user_data['rol_id']))
                $rol_ids_for_user[] = $user_data['rol_id'];*/
            if (!empty($user_roles) && !empty($user_roles['data'])) {
                //echo '<pre>';print_r($user_roles['data']);echo '</pre>';die;
                foreach ($user_roles['data'] as $ur) {
                    if (!in_array($ur['rol_id'], $rol_ids_for_user))
                        $rol_ids_for_user[] = $ur['rol_id'];
                }
            }
            if (empty($rol_ids_for_user)) {
                $forzar_logout_user = true;
                throw new Exception(EntityLib::__('API_ERROR_LOADING_USER'));
            } else {
                $filters = array(
                    'filters' => array(
                        'deleted' => array('=' => 0),
                        'id' => array('IN' => $rol_ids_for_user),
                    ),
                );
                $rol_list = $rolObject->getList($filters);
                $rol_codes = array();
                if (!empty($rol_list) && !empty($rol_list['data'])) {
                    foreach ($rol_list['data'] as $rk => $rol_data) {
                        if (!empty($rol_data['codigo']) && !in_array($rol_data['codigo'], $rol_codes))
                            $rol_codes[] = $rol_data['codigo'];
                    }
                }
                EntityLib::setSession('__rol_ids__', $rol_ids_for_user);
                EntityLib::setSession('__rol_codes__', $rol_codes);
            }
        }

        // Si estamos abriendo un wizard no comprobamos permisos para evitar que falle en el add que se llama
        $is_opening_wizard = !empty($params['__open_wizard__']);
        if($is_opening_wizard)
        {
            unset($params['__open_wizard__']);
            $allowed = true;
        }
        if(!$is_opening_wizard) {
            $allowed = false;
            $check_controller = !empty($params['table']) ? $params['table'] : 'global';
            $check_action = !empty($params['action']) ? $params['action'] : '';
            if ($check_action === 'function') {
                $check_action = !empty($params['method']) ? $params['method'] : '';
            }

            unset($_SESSION['__permisos__']);
            $permisosController = !empty($_SESSION['__permisos__']) ? $_SESSION['__permisos__'] : new PermisosController($rol_ids_for_user);
            $allowed_with_options = $permisosController->checkPermissions($check_controller, $check_action);
            $allowed = !empty($allowed_with_options) && !empty($allowed_with_options['allowed']);
            $_SESSION['__permisos__'] = $permisosController;
            //echo '<pre>';print_r($_SESSION['__permisos__']);echo '</pre>';
            //echo '<pre>';print_r($allowed_with_options);echo '</pre>';
            /*echo '<pre>';print_r($check_controller);echo '</pre>';
            echo '<pre>';print_r($check_action);echo '</pre>';*/
            if (!$allowed) throw new Exception(EntityLib::__('API_ERROR_PERMISSION', array($check_controller, $check_action)));
        }
    }

    // Aquello que venga en __session__ se considerará dimensión, excepto lang_id
    if (!empty($params) && !empty($params['__session__'])) {
        $params_session_keys = array_keys($params['__session__']);
        foreach ($params_session_keys as $psk) {
            $ps_value = $params['__session__'][$psk];
            if (empty($_SESSION['__dimension__']))
                EntityLib::setSession('__dimension__', array());
            //EntityLib::setSessionSub('__dimension__',$psk,$ps_value);
            $_SESSION['__dimension__'][$psk] = $ps_value;
            // Sumamos las dimensiones relacionadas
            $parentDims = EntityLib::addParentDimensions($psk,$ps_value);
            if(!empty($parentDims))
            {
                foreach($parentDims as $pD => $pV)
                {
                    $_SESSION['__dimension__'][$pD] = $pV;
                    $params['__session__'][$pD] = $pV;
                }
            }
        }
    }

    // Si han definido tabla, inicializamos la entidad correspondiente
    $recObject = null;
    if (!empty($params['table'])) {
        $recObject = ClassLoader::getModelObject($params['table'], true);
        //$recObject->setUser($user_id);
        //$recObject->setLanguage($lang_id);
    }
    // Dependiendo del tipo de acción se hará una tarea u otra
    /* Tareas permitidas:
     * - login (login user)
     * - recover (recuperación de contraseña)
     * - save   (guardado genérico)
     * - /table/
     *  - list (obtiene listado con paginación)
     *  - delete (borrar registro con id pasado por parámetro)
     *  - def (obtiene la definición de una tabla)
     */

    $is_edit_user = false;
    if (!empty($params['table']) && !empty($params['action'])) {
        $is_edit_user = $params['table'] === 'usuarios' && $params['action'] === 'edit_user';
    }
    if ($is_edit_user) {
        $params['action'] = 'edit';
        $params['id'] = $request_user_id;
    }

    $is_userapi = !empty($params['public']);

    // Obtenemos las rutas del cliente y las guardamos en la sesión del usuario.
    $_SESSION['__navigation__'] = (!empty($params) && !empty($params['__navigation__'])) ? $params['__navigation__'] : array();
    $no_action_defined = false;

    $force_kill = false;

    switch ($params['action']) {
        // Petición de HOME
        case 'home' :
            $result['result'] = array();
            $primer_rol_id = (!empty($_SESSION['__rol_ids__']) && is_array($_SESSION['__rol_ids__'])) ? $_SESSION['__rol_ids__'][0] : null;
            $primer_rol = $rolObject->getById($primer_rol_id);
            $home_modules = (!empty($primer_rol) && !empty($primer_rol['home_modules'])) ? explode(',',$primer_rol['home_modules']) : array();
            if(!empty($home_modules))
            {
                foreach($home_modules as $home_module)
                {
                    switch($home_module)
                    {
                        case 'home-title' :
                            $cmsObject = ClassLoader::getModelObject('_comunicados', true);
                            $cmsObject->setIgnoreDef(true);
                            $cmsOptions = array(
                                'filters' => array(
                                    'deleted' => array('=' => 0),
                                    'tipo' => array('=' => 'home-title'),
                                ),
                                'order' => array(
                                    'orden' => 'ASC',
                                    'datetime_add' => 'DESC',
                                ),
                            );
                            $cmsTitleData = $cmsObject->getList($cmsOptions);
                            $cmsTitle = (!empty($cmsTitleData) && !empty($cmsTitleData['data'])) ? $cmsTitleData['data'][0] : array(
                                'nombre' => '<h1>Gestión ERP - Agencia Ékiba</h1>',
                                'texto' => '<p>Texto por definir. Crear entrada en _comunicados con tipo = home-title</p>',
                            );
                            $homeContent = !empty($cmsData) && !empty($cmsData['data']) ? $cmsData['data'][0] : $cmsTitle;
                            if(!empty($homeContent))
                            {
                                $result['result']['titulo'] = array(
                                    'title' => $cmsTitle['nombre'],
                                    'texto' => $cmsTitle['texto'],
                                );
                            }
                            break;
                        case 'comunicados' :
                            $cmsObject = ClassLoader::getModelObject('_comunicados', true);
                            $cmsObject->setIgnoreDef(true);
                            $cmsOptions = array(
                                'filters' => array(
                                    'deleted' => array('=' => 0),
                                    'tipo' => array('=' => 'home'),
                                ),
                                'order' => array(
                                    'orden' => 'ASC',
                                    'datetime_add' => 'DESC',
                                ),
                                'pagesize' => 3,
                            );
                            $cmsData = $cmsObject->getList($cmsOptions);
                            $homeContent = !empty($cmsData) && !empty($cmsData['data']) ? $cmsData['data'] : array();
                            if(!empty($homeContent))
                                $result['result']['comunicados'] = array(
                                    'title' => 'Comunicados internos',
                                    'data' => $homeContent,
                                );
                            break;
                        case 'noticias' :
                            $noticiasObject = ClassLoader::getModelObject('noticias', true);
                            $noticiasObject->setIgnoreDef(true);
                            $noticiasOptions = array(
                                'filters' => array(
                                    'deleted' => array('=' => 0),
                                    'estado' => array('=' => 'publico'),
                                ),
                                'order' => array(
                                    'datetime_publicacion' => 'DESC',
                                    'id' => 'DESC',
                                ),
                                'pagesize' => 3,
                                'page' => 1,
                            );
                            $noticiasData = $noticiasObject->getList($noticiasOptions);
                            $noticiasHomeContent = !empty($noticiasData) && !empty($noticiasData['data']) ? $noticiasData['data'] : array();
                            if(!empty($noticiasHomeContent))
                                $result['result']['noticias'] = array(
                                    'title' => 'Últimas noticias',
                                    'data' => $noticiasHomeContent,
                                );
                            break;
                        case 'dashboard' :
                            $cc = new CustomController();
                            $result['result']['dashboard_title'] = $cc->dashboard_title();
                            $result['result']['dashboard_content'] = $cc->dashboard();
                            break;
                    }
                }
            }
            break;
        case 'login' :
            try {
                if (!empty($params['sso']))
                    $loginResult = $apiController->loginUserSSOCookie($params['user'], base64_decode($params['password']));
                else
                    $loginResult = $apiController->loginUser($params['user'], base64_decode($params['password']));
            }catch(Exception $e)
            {
                $forzar_logout_user = true;
                throw $e;
            }
            $result['result'] = $loginResult;
            break;
        case 'login_sso_from_cookie' :
            $loginResult = $apiController->loginUserFromCookie($params['cookie']);
            $result['result'] = $loginResult;
            break;
        // Petición para RECUPERAR MI CONTRASEÑA
        case 'recover' :
            if (empty($params['user']))
                throw new Exception(EntityLib::__('API_RECOVER_NO_MAIL'));

            $is_sso = !empty($params['sso']);
            if($is_sso)
            {
                $ssoObject = ClassLoader::getModelObject('_sso');
                $sso_data = $ssoObject->getById(1);
                if (empty($sso_data)) throw new Exception('No se ha configurado correctamente el inicio SSO');
                $sso_config = !empty($sso_data['config']) ? json_decode($sso_data['config'], JSON_UNESCAPED_UNICODE) : array();
                $sso_affected_roles = (!empty($sso_config) && !empty($sso_config['roles'])) ? explode(',',$sso_config['roles']) : array();
                $is_sso = $apiController->isUserAffectedSso($params['user'],$sso_affected_roles);
            }

            if(!$is_sso) {
                $recovery_result = $apiController->recoverUser1($params['user']);
                if (!$recovery_result)
                    throw new Exception(EntityLib::__('API_RECOVER_STEP1_ERROR'));
                $result['result']['msg'] = EntityLib::__('API_RECOVER_STEP1_OK', array($params['user']));
            }
            else
            {
                $sso_recover_url = (!empty($sso_config) && !empty($sso_config['recover_url'])) ? $sso_config['recover_url'] : null;
                if(empty($sso_recover_url)) throw new Exception('No se ha configurado correctamente el inicio SSO (recover_url)');
                $sso_recover_url = str_replace('{USER}',base64_encode($params['user']),$sso_recover_url);
                $result['result']['redirect'] = $sso_recover_url;
            }
            break;
        // Petición para RECUPERAR MI CONTRASEÑA - PASO OBTENER TOKEN
        case 'recover_start' :
            $recover_user_data = $apiController->recoverUser2(base64_decode($params['token']));
            if (empty($recover_user_data))
                throw new Exception(EntityLib::__('API_RECOVER_STEP2_WRONG_TOKEN'));
            $result['result'] = $recover_user_data['username'];
            break;
        // Petición para RECUPERAR MI CONTRASEÑA - PASO REGENERAR CONTRASEÑA
        case 'recover_end' :
            $recover_result = $apiController->recoverUser3(base64_decode($params['token']), $params['new_password']);
            $result['result'] = $recover_result;
            break;
        case 'create_user' :
            /*$recover_result = $apiController->recoverUser3(base64_decode($params['token']),base64_decode($params['new_password']));*/
            $create_result = 'Test';
            $cc = new CustomController();
            $result_create = $cc->create_user($params);
            if(!empty($result_create['login']))
            {
                $loginResult = $apiController->loginUser($params['user'], base64_decode($params['new_password_1']));
                $result['do_login'] = true;
                $result['result'] = $loginResult;
            }
            else
            {
                $result['do_login'] = false;
                $result['result'] = EntityLib::__($result_create['message']);
            }
            break;
        case 'alertas_usuario' :
            $avisosObj = ClassLoader::getModelObject('_avisos',false);
            $buzonesObj = ClassLoader::getModelObject('_buzon_todos',false);
            $result['result'] = array(
                'avisos' => $avisosObj->getAvisosPendientesUser($request_user_id),
                'buzon' => $buzonesObj->getBuzonesPendientesUser($request_user_id),
            );
            break;
        case 'set_lang' :
            $usuario_id = (!empty($_SESSION) && !empty($_SESSION['__user_id__'])) ? $_SESSION['__user_id__'] : null;
            $result['result'] = array(
                'language_id' => $apiController->setUserLang($usuario_id,$params['language_id']),
                '__dimensions__' => EntityLib::loadDimensionsForLoginUser($usuario_id),
                'message' => EntityLib::__('API_USER_LANG_CHANGE'),
            );
            if(!empty($params['cookie'])){
                $cookie = (!empty($params) && !empty($params['cookie'])) ? $params['cookie'] : null;
                $loginResult = $apiController->getSSOCookie($params['user'], $cookie,$params['language_id']);
                if (!empty($loginResult['cookie_value'])){
                    $result['result']['cookie_value'] = $loginResult['cookie_value'];
                }
            }
            break;
        // Petición para DEFINICIÓN DE TABLA
        case 'def' :
            $result['result'] = $recObject->getDefinition();
            break;
        case 'add' :
            $def = $recObject->getDefinition();
            $result['result'] = $def;
            // Cargamos botones
            $card_actions_options = array(
                'is_add' => true
            );
            $extra_params = !empty($params['params']) ? $params['params'] : null;
            if (!empty($allowed_with_options) && !empty($allowed_with_options['disable_actions'])) {
                $card_actions_options['disable_actions'] = $allowed_with_options['disable_actions'];
            }
            $custom_actions = !empty($def['custom_actions']) ? $def['custom_actions'] : array();
            $final_custom_actions = array();
            foreach($custom_actions as $ca)
            {
                $add_to_record = empty($ca['if']);
                if($add_to_record) {
                    $final_custom_actions[] = $ca;
                }
            }
            $card_actions_options['custom_actions'] = $final_custom_actions;
            $result['result']['__card_actions__'] = EntityLib::getCardActions($recObject, $card_actions_options);

            // Busco el JSON
            $tabla_buscar = $params['table'];
            $accion_buscar = !empty($params['action']) ? $params['action'] : '';

            if(!empty($params['__source_action__']))
                $accion_buscar = $params['__source_action__'];
            if(!empty($params['__subtable__']))
                $accion_buscar = 'subtable_'.$accion_buscar;
            $json_file_content = EntityLib::getJsonFileFromTableView($tabla_buscar,$accion_buscar);
            $result['result']['form'] = $json_file_content;

            // En los ADD, tenemos en cuenta que podemos querer hacer algún tipo de lógica
            $result['result']['data'] = $recObject->processBeforeAdd($extra_params);

            break;
        // Petición para MENÚ
        case 'menu' :
            $menuResult = $apiController->getMenuData($rol_ids_for_user);
            $result['result'] = $menuResult;
            break;
        // Petición tipo LISTADO
        case 'list' :
        case 'panel' :
            if (empty($params['table']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ENTITY'));

            if (!$apiController->checkTable($params['table']))
                throw new Exception(EntityLib::__('API_ERROR_NO_ENTITY', array($params['table'])));

            $listOptions = array();
            $listOptions['from_api'] = true;
            $listOptions['ignore_related'] = true;
            if (!empty($params['custom_process'])) $listOptions['custom_process'] = $params['custom_process'];
            if (!empty($params['filters'])) $listOptions['filters'] = $params['filters'];
            if (!empty($params['order'])) $listOptions['order'] = $params['order'];
            if (!empty($params['page'])) $listOptions['page'] = $params['page'];
            else
                $listOptions['page'] = 1;
            if (!empty($params['subentity'])) $listOptions['subentity'] = $params['subentity'];

            $subaction_option = null;
            if (!empty($params['id'])) $subaction_option = $params['id'];
            if (!empty($subaction_option)) $listOptions['table_view'] = $subaction_option;

            if (!empty($params['pagesize']))
                $listOptions['pagesize'] = $params['pagesize'];
            else
                $listOptions['pagesize'] = 'default';

            $listOptions['add_actions'] = ($is_userapi) ? 0 : 1;


            // Si se tira un select_all interesan solo el id
            if(!empty($params['select_all']))
            {
                $listOptions['select_all'] = true;
            }

            // Ponemos valores por defecto
            // def -> Incluye estructura. Por defecto a sí, solo si indican que no la quieren no se pasa
            // convert -> Conversión de valores a formato final (números, booleanos, etc)
            // limit_text -> Si hemos de limitar el texto o no
            // include_count -> Si ha de incluir count total para paginación
            // include_possible_values -> Para obtener distincts de los valores filtrados para campos
            $listOptions['def'] = true;
            if (isset($params['def']) && ($params['def'] === false || $params['def'] === 0))
                unset($listOptions['def']);
            else {
                $result['result']['def'] = $recObject->getDefinition($subaction_option);
            }
            $listOptions['convert'] = true;
            $listOptions['limit_text'] = true;
            $listOptions['include_count'] = true;
            //$listOptions['include_possible_values'] = true;
            if(!empty($listOptions) && !empty($listOptions['filters'])) {
                if (!array_key_exists('deleted',$listOptions['filters']))
                    $listOptions['filters']['deleted'] = array('=' => 0);
            }
            $request = $recObject->getList($listOptions);
            $result['result']['data'] = $request['data'];
            $result['result']['count'] = $request['count'];
            $result['result']['query'] = $request['query'];
            if (!empty($request['values']))
                $result['result']['values'] = $request['values'];


            // Busco el JSON del formulario
            $tabla_buscar = $params['table'];
            $accion_buscar = !empty($params['action']) ? $params['action'] : '';
            if(!empty($params['__source_action__']))
                $accion_buscar = $params['__source_action__'];
            $json_file_content = EntityLib::getJsonFileFromTableView($tabla_buscar,$accion_buscar);
            $result['result']['form'] = $json_file_content;

            if($is_userapi)
            {
                //$result['query_used'] = $result['result']['query'];
                $result['result'] = array('data' => (!empty($result['result']) && !empty($result['result']['data'])) ? $result['result']['data'] : array());
                //unset($result['result']['def']);
            }
            $result['result']['__watermark__'] = date('Y-m-d H:i:s');
            break;
        // Petición tipo EXPORTAR_CSV
        case 'export_csv' :
            if (empty($params['table']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ENTITY'));

            if (!$apiController->checkTable($params['table']))
                throw new Exception(EntityLib::__('API_ERROR_NO_ENTITY', array($params['table'])));
            $result['result'] = $recObject->export2Csv($listOptions);
            break;
        // Petición tipo EXPORTAR_PDF
        case 'export_pdf' :
            if (empty($params['table']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ENTITY'));

            $listOptions = array();
            if (!empty($params['filters'])) $listOptions['filters'] = $params['filters'];
            if (!empty($params['order'])) $listOptions['order'] = $params['order'];

            if (!$apiController->checkTable($params['table']))
                throw new Exception(EntityLib::__('API_ERROR_NO_ENTITY', array($params['table'])));
            $result['result'] = $recObject->export2Pdf($listOptions);
            break;
        // Petición tipo EXPORTAR_EXCEL
        case 'export_xls' :
            if (empty($params['table']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ENTITY'));

            $listOptions = array();
            if (!empty($params['filters'])) $listOptions['filters'] = $params['filters'];
            if (!empty($params['order'])) $listOptions['order'] = $params['order'];
            if (!$apiController->checkTable($params['table']))
                throw new Exception(EntityLib::__('API_ERROR_NO_ENTITY', array($params['table'])));
            $result['result'] = $recObject->export2Xls($listOptions);
            break;
        // Petición tipo FICHA - OBTENCIÓN DATOS
        case 'wizard' :
        case 'edit' :
        case 'view' :
            if (empty($params['table']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ENTITY'));
            if (empty($params['id']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ID'));

            if (!$apiController->checkTable($params['table']))
                throw new Exception(EntityLib::__('API_ERROR_NO_ENTITY', array($params['table'])));


            $def = $recObject->getDefinition();
            $options = array(
                'is_card' => 1,
                'is_wizard' => $params['action'] === 'wizard' ? 1 : 0,
            );
            if(!empty($def['real_entity']) && $def['real_entity'] != $def['table']) {
                $realObject = ClassLoader::getModelObject($def['real_entity'], true);
                $data = $realObject->getById($params['id'], $options);
            }
            else
            {
                $data = $recObject->getById($params['id'], $options);
            }
            if (empty($data))
                throw new Exception(EntityLib::__('API_GENERAL_MAIN_NOT_FOUND'));

            $recObject->allowActionByRecord($params['action'],$data);

            // Marcamos la hora del servidor para control de modificados
            $result['result']['__watermark__'] = date('Y-m-d H:i:s');

            if (!empty($data['__values__'])) {
                $result['result']['values'] = $data['__values__'];
                unset($data['__values__']);
            }


            $result['result']['data'] = $data;
            $result['result']['def'] = $recObject->getDefinition();

            // Busco el JSON
            $tabla_buscar = $params['table'];
            $accion_buscar = !empty($params['action']) ? $params['action'] : '';
            if(!empty($params['__source_action__']))
                $accion_buscar = $params['__source_action__'];
            if(in_array($params['action'],array('edit','view'))) {
                if (!empty($params['__subtable__']))
                    $accion_buscar = 'subtable_' . $accion_buscar;
            }
            $json_file_content = EntityLib::getJsonFileFromTableView($tabla_buscar,$accion_buscar);
            $result['result']['form'] = $json_file_content;

            // En los VER forzamos a todos los campos que se pasan que no son editables
            $is_view = false;
            if ($params['action'] === 'view') {
                $is_view = true;
                if(!empty($result['result']) && !empty($result['result']['def']))
                {
                    EntityLib::forceAllInputsFromDefDisabled($result['result']['def']);
                }
            }

            // Cargamos botones
            //$custom_actions = !empty($def['custom_actions']) ? $def['custom_actions'] : array();
            //$result['result']['def']['__card_actions__'] = EntityLib::getCardActions($recObject, array('is_view' => $is_view,'custom_actions' => $custom_actions));
            $card_actions_options = array('is_view' => $is_view);
            if (!empty($allowed_with_options) && !empty($allowed_with_options['disable_actions'])) {
                $card_actions_options['disable_actions'] = $allowed_with_options['disable_actions'];
            }
            $custom_actions = !empty($def['custom_actions']) ? $def['custom_actions'] : array();
            $final_custom_actions = array();

            foreach($custom_actions as $ca)
            {
                $add_to_record = empty($ca['if']);
                if(!$add_to_record) {
                    $aux_filters = explode(',', $ca['if']);
                    if(!empty($aux_filters)) {
                        $incumple_algun_filtro = false;
                        foreach($aux_filters as $aux_filter) {
                            $aux = explode('=', $aux_filter);
                            if (!empty($aux)) {
                                $cumple_filtro = false;
                                $field_condition = $aux[0];
                                $field_value = '' . $aux[1];
                                $field_source = '' . !empty($result['result']['data'][$field_condition]) ? $result['result']['data'][$field_condition] : '';
                                // Capturamos valores NOT NULL / NULL
                                if ($field_value === "NOT NULL") {
                                    $cumple_filtro = !empty($field_source);
                                } else if ($field_value === "NULL") {
                                    $cumple_filtro = empty($field_source);
                                } // Permitimos OR en los valores
                                else if (strpos($field_value, '||') !== false) {
                                    $field_value_or_conditions = explode('||', $field_value);
                                    $cumple_filtro = in_array($field_source, $field_value_or_conditions);
                                } else
                                    $cumple_filtro = ($field_value == $field_source) || (empty($field_value) && empty($field_source));
                                if(!$cumple_filtro) $incumple_algun_filtro = true;
                            }
                        }
                        if(!$incumple_algun_filtro) $add_to_record = true;
                    }
                }
                // Comprobamos si se ha definido que tiene que haber una dimensión en concreto
                if($add_to_record && !empty($ca['dimension']))
                {
                    $add_to_record = (!empty($_SESSION) && !empty($_SESSION['__dimension__']) && !empty($_SESSION['__dimension__'][$ca['dimension']]));
                }
                if($add_to_record)
                    $final_custom_actions[] = $ca;
            }
            $card_actions_options['custom_actions'] = $final_custom_actions;
            $result['result']['def']['__card_actions__'] = EntityLib::getCardActions($recObject, $card_actions_options);
            $recObject->processActionByRecordValues($result['result']['def']['__card_actions__'],$data);
            break;
        // Petición tipo DELETE
        case 'delete' :
            //
            if (empty($params['table']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ENTITY'));
            if (empty($params['id']))
                throw new Exception(EntityLib::__('API_GENERAL_NO_ID'));

            if (!$apiController->checkTable($params['table']))
                throw new Exception(EntityLib::__('API_ERROR_NO_ENTITY', array($params['table'])));

            $data = $recObject->getById($params['id']);
            if (empty($data))
                throw new Exception(EntityLib::__('API_GENERAL_MAIN_NOT_FOUND'));
            $update_data = array(
                'id' => $params['id'],
                'deleted' => 1,
            );
            $main_id = $recObject->save($update_data);
            if (empty($main_id))
                throw new Exception(EntityLib::__('API_ERROR_DELETE'));
            $result['result']['main_id'] = $main_id;


            break;
        // Petición tipo FICHA - GUARDADO
        case 'save' :
            $dataToSave = !empty($params['data']) ? $params['data'] : array();
            if (empty($dataToSave))
                throw new Exception(EntityLib::__('API_GENERAL_NO_DATA'));

            $main_record = $dataToSave;
            $main_id = $recObject->save($main_record);
            $related_affected_ids = array();
            if (!empty($main_id)) {
                $result['result'] = array(
                    'main_id' => $main_id,
                    'message' => EntityLib::__($recObject::_ENTITY_SAVED)
                );
            } else
                throw new Exception(EntityLib::__('API_ERROR_SAVE'));

            if($is_userapi)
            {
                $main_data = $recObject->getById($main_id);
                unset($main_data['__related__']);
                unset($main_data['__actions__']);
                $result['result']['data'] =  $main_data;
            }
            break;
        // Petición tipo FICHA - GUARDADO MÚLTIPLE
        case 'save_multiple' :
            $dataToSave = !empty($params['data']) ? $params['data'] : array();
            $insert_with_errors = !empty($params['insert_with_errors']) ? true : false;
            if (empty($dataToSave))
                throw new Exception(EntityLib::__('API_GENERAL_NO_DATA'));
            // Si es una petición de api, queremos que nos devuelva todos los datos
            $add_data = $is_userapi;

            $main_record = $dataToSave;
            $result_multiple = $recObject->saveMultiple($dataToSave,$insert_with_errors,$add_data);
            if (!empty($result_multiple)) {
                $result['result'] = array(
                    'processed_results' => $result_multiple,
                    'message' => EntityLib::__($recObject::_ENTITY_SAVED)
                );
            } else
                throw new Exception(EntityLib::__('API_ERROR_SAVE'));

            break;
        // Función personalizada de entidad
        case 'function' :
            $record_action = !empty($params['method']) ? $params['method'] : '';
            $id_action = !empty($params['id']) ? $params['id'] : 0;
            $fnc_params = array();
            if(!empty($params['output']))
                $fnc_params = $params['output'];
            if(!empty($params['data']))
                $fnc_params = $params['data'];
            try {
                if (empty($record_action) && empty($id_action))
                    throw new Exception(EntityLib::__('API_ERROR_FUNCTION'));
                $record_action = 'fnc' . ucfirst($record_action);
                $result['result'] = $recObject->$record_action($id_action, $fnc_params);
                // Puede ser que haya fallado y queramos mostrar error pero guardando
                if(isset($result['result']['success'])) {
                    $result['success'] = $result['result']['success'];
                    unset($result['result']['success']);
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            } catch (Error $e) {
                throw new Exception($e->getMessage());
            }
            break;
        // Peticiones para flujo de Redsys
        // Importante, el controlador de redsys es propio de cada proyecto!
        case 'redsysRequest' :
            $redsysControllerPath = __DIR__ . '/Entity/RedsysController.php';
            if(!file_exists($redsysControllerPath))
                throw new Exception(EntityLib::__('Redsys no se ha configurado correctamente.'));
            require_once($redsysControllerPath);
            $rC = new RedsysController();
            $rC->redsysRequest();
            $force_kill = true;
            break;
        case 'redsysOk' :
            $redsysControllerPath = __DIR__ . '/Entity/RedsysController.php';
            if(!file_exists($redsysControllerPath))
                throw new Exception(EntityLib::__('Redsys no se ha configurado correctamente.'));
            require_once($redsysControllerPath);
            $rC = new RedsysController();
            $rC->redsysOk();
            $force_kill = true;
            break;
        case 'redsysKo' :
            $redsysControllerPath = __DIR__ . '/Entity/RedsysController.php';
            if(!file_exists($redsysControllerPath))
                throw new Exception(EntityLib::__('Redsys no se ha configurado correctamente.'));
            require_once($redsysControllerPath);
            $rC = new RedsysController();
            $rC->redsysKo();
            $force_kill = true;
            break;
        case 'redsysNotify' :
            $redsysControllerPath = __DIR__ . '/Entity/RedsysController.php';
            if(!file_exists($redsysControllerPath))
                throw new Exception(EntityLib::__('Redsys no se ha configurado correctamente.'));
            require_once($redsysControllerPath);
            $rC = new RedsysController();
            $rC->redsysNotify();
            $force_kill = true;
            break;
        // Petición NO PREPARADA
        default :
            $no_action_defined = true;
            $accion = $params['action'];
            try {
                $cc = new CustomController();
                $result['result'] = $cc->$accion($params);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            } catch (Error $e) {
                throw new Exception($e->getMessage());
            }
            break;
    }
    if(!isset($result['success']))
        $result['success'] = true;

    // Si se han definido sobre que acciones van los contenidos extra, se añaden
    $add_page_extra_content = EntityLib::getConfig('extra_content_for_pages');
    if(!empty($add_page_extra_content))
    {
        $aux_extra_content = explode(",",$add_page_extra_content);
        if(in_array($params['action'],$aux_extra_content) || $no_action_defined)
        {
            $cc = new CustomController();
            $extra_content_for_page = $cc->getExtraContent($params);
            if(!empty($extra_content_for_page))
                $result['result']['extra_content'] = $extra_content_for_page;
        }
    }


    $apiController->commitTransaction();

    if($force_kill) die;

} catch (ApiException $e) {
    $result['success'] = false;
    $result['result'] = array('error' => $e->getErrors(), 'api_error' => true);
    $apiController->rollbackTransaction();
} catch (Exception $e) {
    $result['success'] = false;
    $result['result'] = array('error' => $e->getMessage());
    if ($forzar_logout_user) {
        $result['logout'] = true;
    }
    if($mantener_mensaje_logout)
    {
        $result['logout_message'] = true;
    }
    $apiController->rollbackTransaction();
} catch (Error $e)
{
    /*
    echo '<pre>';print_r('Linea: ');echo '</pre>';
    echo '<pre>';print_r($e->getLine());echo '</pre>';
    echo '<pre>';print_r('Fichero: ');echo '</pre>';
    echo '<pre>';print_r($e->getFile());echo '</pre>';
    echo '<pre>';print_r('Mensaje: ');echo '</pre>';
    echo '<pre>';print_r($e->getMessage());echo '</pre>';
    echo '<pre>';print_r('Traza: ');echo '</pre>';
    echo '<pre>';print_r($e->getTrace());echo '</pre>';die;
    */
    $result['success'] = false;
    $result['result'] = array('error' => $e->getMessage());
}

// Cerramos conexión a BDD y eliminamos de sesión
if (!empty($_SESSION) && !empty($_SESSION['__db__'])) unset($_SESSION['__db__']);

// Calculamos tiempo de ejecución
$time_out = gettimeofday();
$debug_time_out = time();
$diff_seconds = floatval($time_out['sec'] - $time_in['sec']);
$diff_miliseconds = floatval($time_out['usec'] - $time_in['usec']);
if ($diff_miliseconds < 0.00) {
    $diff_seconds = $diff_seconds + 1;
    $diff_miliseconds = floatval(1000000 + $time_out['usec'] - $time_in['usec']);
}
$diff_miliseconds = '000000'.$diff_miliseconds;
$diff_miliseconds = substr($diff_miliseconds,-6);
$diff = number_format(floatval($diff_seconds . '.' . $diff_miliseconds),6,'.');

$result['debug']['time_total'] = $diff;
$result['debug']['exec_query'] = !empty($_SESSION['__queries_executed__']) ? intval($_SESSION['__queries_executed__']) : 0;
$add_debug_info = !empty($_SESSION['__rol_codes__']) && is_array($_SESSION['__rol_codes__']) && in_array('EKIBA',$_SESSION['__rol_codes__']);
if(!$add_debug_info && $__is_dev)
{
    $add_debug_info = true;
}
$result['debug']['add_debug_info'] = $add_debug_info;

if(!$add_debug_info || $is_userapi)
{
    if(!empty($result) && !empty($result['result']) && !empty($result['result']['query']))
    {
        unset($result['result']['query']);
    }
    
    if(!empty($result) && !empty($result['debug'])) unset($result['debug']);
}
else if($add_debug_info && !$is_userapi)
{
    $result['debug']['sql_count'] = !empty($_SESSION['__sql_count__']) ? $_SESSION['__sql_count__'] : 0;
    if(!empty($_SESSION['__last_query__'])) $result['debug']['last_query'] = $_SESSION['__last_query__'];
    //if(!empty($_SESSION['__all_query__'])) $result['debug']['all_query'] = $_SESSION['__all_query__'];
    //if(!empty($_SESSION['__debug_backtrace__'])) $result['debug']['_trace'] = $_SESSION['__debug_backtrace__'];
}
//

// Limpio sql y cachedata para pasar a la salida del session para debug
/*$session2Output = $_SESSION;
if(!empty($session2Output['__sql__'])) unset($session2Output['__sql__']);
if (!empty($session2Output['__cachedata__'])) unset($session2Output['__cachedata__']);
if (!empty($session2Output['__translate__'])) unset($session2Output['__translate__']);
if (!empty($session2Output['__defs__'])) unset($session2Output['__defs__']);
$result['debug']['_SESSION'] = $session2Output;
*/

if ($forzar_logout_user) {
    session_destroy();
    unset($_SESSION);
    header("HTTP/1.1 401 Unauthorized");
    //header('Status: 401');
}

// Tratamos respuesta para evitar error de codificación. Si da algún problema convertimos
header('Content-type: application/json');
$json_string = json_encode($result,JSON_UNESCAPED_UNICODE);
if(!$json_string && json_last_error() === JSON_ERROR_UTF8)
{
    $json_string = json_encode(mb_convert_encoding($result, 'UTF-8', 'UTF-8'),JSON_UNESCAPED_UNICODE);
}
/*
ob_start('ob_gzhandler'); // Habilitar la compresión GZIP
header('Content-Encoding: gzip');
header('Content-Type: application/json');
*/
// Si es una petición de API pública, guardamos log
if($is_userapi)
{
    $log_file = __DIR__.'/../log/{identifier}.log';
    $userapi_id = !empty($params['identifier']) ? $params['identifier'] : 'undefined';
    $userapi_id = date('Ymd').$userapi_id;
    $log_file = str_replace('{identifier}',$userapi_id,$log_file);
    $f = fopen($log_file,'a');
    // Le metemos la cabecera de uft8
    $json_string_params = json_encode($params, JSON_PRETTY_PRINT);
    $json_string_result = json_encode($result, JSON_PRETTY_PRINT);
    $write = '## [REQUEST '.date('YmdHis').'] ##'."\n".'[PARAMS]'."\n".$json_string_params."\n".'[RESULT]'."\n".$json_string_result."\n".'## [END REQUEST] ##'."\n\n";
    fwrite($f,$write);
    fclose($f);
}

echo $json_string;die;

function getAuthorizationHeader()
{
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        //print_r($requestHeaders);
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    if (!empty($headers))
        $headers = str_replace('Bearer ', '', $headers);
    return $headers;
}

?>
