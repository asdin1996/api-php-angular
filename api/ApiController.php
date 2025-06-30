<?php

require_once(__DIR__.'/Database.php');
require_once(__DIR__.'/EntityLib.php');
require_once(__DIR__.'/Mailer.php');

class ApiController {

    protected $database = null;
    protected $user_id = null;
    protected $lang_id = null;
    protected $rol_id = null;

    const __masterToken = 'CONKDEEKIBA';
    const __masterUserId = 1;
    const __masterLangId = 1;
    const __cronjobToken = 'CONKDEEKIBAYCDECRON';
    const __cronjobUserId = 0;
    const __cronjobLangId = 1;

    /* Constructor por defecto
     * Cargamos fichero de config y abrimos BDD
     */
    function __construct(){
        $servername = EntityLib::getServerName();
        $optionsPathFile = __DIR__.'/options/options.'.$servername.'.json';
        if(!file_exists($optionsPathFile))
        {
            throw new Exception(EntityLib::__('API_JSON_CONFIG_ERROR',self::__masterLangId));
        }

        $jsonOptions = file_get_contents($optionsPathFile);
        $app_options = json_decode($jsonOptions,JSON_UNESCAPED_UNICODE);


        $db_options = array(
            'db_dbtype' => 'sql',
            'db_host' => !empty($app_options['db_host']) ? $app_options['db_host'] : '',
            'db_database' => !empty($app_options['db_name']) ? $app_options['db_name'] : '',
            'db_user' => !empty($app_options['db_user']) ? $app_options['db_user'] : '',
            'db_password' => !empty($app_options['db_pass']) ? $app_options['db_pass'] : '',
        );
        $this->database = new Database($db_options);

        // En el propio constructor asignamos idioma
        $this->lang_id = (!empty($_SESSION) && !empty($_SESSION['__lang_id__'])) ? $_SESSION['__lang_id__'] : self::__masterLangId;
        $this->user_id = (!empty($_SESSION) && !empty($_SESSION['__user_id__'])) ? $_SESSION['__user_id__'] : self::__masterUserId;

    }
    public function setUser($user_id)
    {
        $this->user_id = $user_id;
    }
    public function setRol($rol_id)
    {
        $this->rol_id = $rol_id;
        // MEJORAR -> METER AQUÍ LA OBTENCIÓN DE PERMISOS!
    }

    /* Función para logear un user
     * Genera token si es correcto y actualiza. Devuelve el token generado para el login.
     * Si se llama a login teniendo token se recrea
     */
    public function loginUser($user,$pass,$sso_data = false)
    {
        $result = array();

        $userObject = ClassLoader::getModelObject('usuarios');
        $userTokenObject = ClassLoader::getModelObject('_usuarios_tokens');

        // Force reset - Buscamos usuario sin contraseña
        $options = array(
            'filters' => array(
                'username' => array('=' => $user),
                'deleted' => array('=' => 0),
                'force_reset' => array('=' => 1),
                'activo' => array('=' => 1),
            ),
        );
        $userReset = $userObject->getList($options);
        if(!empty($userReset) && !empty($userReset['data'])) {
            if(count($userReset['data']) > 1)
                throw new Exception(EntityLib::__('API_GENERAL_NO_SINGLE_USER_FOUND'));
            $error_msg_2 = EntityLib::__('API_RECOVER_SUBJECT');
            $error_msg = EntityLib::__('API_RECOVER_ERROR',array($error_msg_2));
            throw new Exception($error_msg);
        }

        $options = array(
            'filters' => array(
                'username' => array('=' => $user),
                'deleted' => array('=' => 0),
                'password' => array('MD5' => $pass),
                'activo' => array('=' => 1),
            ),
        );
        if(!empty($sso_data)) unset($options['filters']['password']);
        $userRes = $userObject->getList($options);
        $userData = array();
        if(empty($userRes['data'])) {

            // No debe, pero si hay varias versiones de un usuario inactivo, nos quedaremos con la última para evitar errores
            $options = array(
                'filters' => array(
                    'username' => array('=' => $user),
                    'deleted' => array('=' => 0),
                    'password' => array('MD5' => $pass),
                    'activo' => array('=' => 0),
                ),
                'pagesize' => 1,
            );
            if(!empty($sso_data)) unset($options['filters']['password']);
            $userRes = $userObject->getList($options);
        }


        if(!empty($userRes) && !empty($userRes['data'])) {
            if(count($userRes['data']) > 1)
                throw new Exception(EntityLib::__('API_GENERAL_NO_SINGLE_USER_FOUND'));
            $userData = $userRes['data'][0];
        }
        if(empty($userData))
        {
            throw new Exception(EntityLib::__('API_WRONG_USER'));
        }
        else if(empty($userData['activo']) && !empty($userData['observaciones_bloqueo']))
        {
            throw new Exception(EntityLib::__($userData['observaciones_bloqueo']));
        }
        else if(empty($userData['activo']))
        {
            throw new Exception(EntityLib::__('API_WRONG_USER'));
        }

        // Añadimos control para prohibir por origen. Buscamos los roles del USER
        // En rol_app_values nos dejaremos todos los valores del campo app (si existe) de los roles del usuario (sin duplicados)
        // Casos
        // a) No hay nada, no se restringirá al usuario (lo normal)
        // b) Solo hay prohibir para APP => rol_app_values = array('prohibir')
        // c) Solo hay exigir para APP => rol_app_values = array('exigir')
        $rol_app_values = array();
        $is_app = !empty($_POST) && !empty($_POST['__app__']);
        $user_id = $userData['id'];

        // Buscamos los roles del usuario
        $userObject = ClassLoader::getModelObject('usuarios');
        $userObject->setIgnoreDef(true);
        $rolObject = ClassLoader::getModelObject('roles');
        $rolObject->setIgnoreDef(true);
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
        if (empty($rol_ids_for_user)) {
            if (!empty($user_roles) && !empty($user_roles['data'])) {
                foreach ($user_roles['data'] as $ur) {
                    if (!in_array($ur['rol_id'], $rol_ids_for_user))
                        $rol_ids_for_user[] = $ur['rol_id'];
                }
            }
            // Si el usuario no tiene roles, daremos error
            if (empty($rol_ids_for_user)) {
                $forzar_logout_user = true;
                throw new Exception(EntityLib::__('API_ERROR_LOADING_USER'));
            } else {
                // Si el usuario tiene roles, los buscamos.
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
                        if (!empty($rol_data['codigo']) && !in_array($rol_data['codigo'], $rol_codes)) {
                            $rol_codes[] = $rol_data['codigo'];
                            // Si el rol tiene el campo app, añadimos el valor al array
                            if(!empty($rol_data['app']))
                                if(!in_array($rol_data['app'],$rol_app_values))
                                    $rol_app_values[] = $rol_data['app'];
                        }
                    }
                }
            }
        }

        // Comprobaremos ahora si hay que dar error por dispositivo.
        $dispositivo_error = false;
        $dispositivo_error_msg = '';
        // Si es APP y se prohibe
        if($is_app && $rol_app_values == array('prohibir'))
        {
            $dispositivo_error = true;
            $dispositivo_error_msg = 'Su usuario está permitido solo desde la aplicación web.';
        }
        // Si no es APP (Panel u otro) y se exige APP
        if(!$is_app && $rol_app_values == array('exigir')){
            $dispositivo_error = true;
            $dispositivo_error_msg = 'Su usuario está permitido solo desde la aplicación móvil (APP).';
        }
        // Si hay que dar error directamente tiraremos en el propio login
        if($dispositivo_error) {
            throw new Exception('No puede iniciar sesión porque está conectando desde el dispositivo equivocado. '.$dispositivo_error_msg);
        }

        $new_token = EntityLib::getGuidv4();
        $new_time = date('Y-m-d H:i:s');

        $tiempo_validez_propio = !empty($userData['session_valid_time']) ? $userData['session_valid_time'] : '+12 hours';
        $new_expire = date('Y-m-d H:i:s', strtotime($tiempo_validez_propio,strtotime($new_time)));
        $user_token = array(
            'usuario_id' => $userData['id'],
            'token' => $new_token,
            'session_in' => $new_time,
            'session_expires' => $new_expire,
        );
        $updateResult = $userTokenObject->save($user_token);
        if(empty($updateResult))
            throw new Exception(EntityLib::__('API_LOGIN_ERROR_CREATE_TOKEN'));

        $result['token'] = base64_encode($new_token);
        $result['token_valid'] = $new_expire;
        $result['name'] = !empty($userData['nombre']) ? $userData['nombre'] : '-';
        $result['language_id'] = !empty($userData['lang_id']) ? $userData['lang_id'] : 1;

        /* Cargamos las posibles dimensiones del usuario */
        $result['__dimensions__'] = EntityLib::loadDimensionsForLoginUser($userData['id']);

        return $result;
    }

    /* Función para logear un user vía cookie
     * Se le pasa el dato de la cookie de sesión SSO, hace loginUser a partir de esos datos
     */
    public function loginUserFromCookie($cookie_data)
    {
        if(empty($cookie_data)) throw new Exception(EntityLib::__('API_SSO_WRONG_DATA_LOGIN'));
        $ssoObject = ClassLoader::getModelObject('_sso');
        $sso_data = $ssoObject->getById(1);
        if(empty($sso_data)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG'));
        $sso_config = !empty($sso_data['config']) ? json_decode($sso_data['config'],JSON_UNESCAPED_UNICODE) : array();
        $sso_cookie_domain = (!empty($sso_config) && !empty($sso_config['cookie_domain'])) ? $sso_config['cookie_domain'] : null;
        $sso_cookie_name = (!empty($sso_config) && !empty($sso_config['cookie_name'])) ? $sso_config['cookie_name'] : null;
        $sso_cookie_key = (!empty($sso_config) && !empty($sso_config['cookie_key'])) ? $sso_config['cookie_key'] : null;
        // La clave en BDD está 5 veces codificada en base64
        for($i = 0; $i <= 4;$i++)
        {
            $sso_cookie_key = base64_decode($sso_cookie_key);
        }
        if(empty($sso_cookie_domain)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (cookie_domain)');
        if(empty($sso_cookie_name)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (cookie_name)');
        if(empty($sso_cookie_key)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (cookie_key)');

        // Decodificamos datos de cookie y llamamos a la función de login
        $login_result = array();
        try {
            require_once(__DIR__ . '/lib/cryptojs-custom/CryptoJsCustom.php');
            $cryptoJsCustom = new CryptoJsCustom();
            $cookie_decrypted_data = $cryptoJsCustom->cryptoJs_aes_decrypt($cookie_data, $sso_cookie_key);
            $aux = explode('#', $cookie_decrypted_data);
            $user_login = $aux[0];
            $login_result = $this->loginUser($user_login, null, $sso_data);
            $login_result['cookie_user'] = $user_login;
        }catch(Exception $e)
        {
            throw new Exception(EntityLib::__('API_SSO_ERROR_LOGIN'));
        }
        return $login_result;
    }

    /* Función para logear un user utilizando sistema SSO Cookie
     * Función similar al login de usuario, solo que no checkea la password
     */
    public function loginUserSSOCookie($user,$pass)
    {
        $login_result = array();
        try {

            $ssoObject = ClassLoader::getModelObject('_sso');
            $sso_data = $ssoObject->getById(1);
            if (empty($sso_data)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG'));
            $sso_config = !empty($sso_data['config']) ? json_decode($sso_data['config'], JSON_UNESCAPED_UNICODE) : array();
            $sso_affected_roles = (!empty($sso_config) && !empty($sso_config['roles'])) ? explode(',',$sso_config['roles']) : array();

            $normal_login = !$this->isUserAffectedSso($user,$sso_affected_roles);
            if($normal_login)
            {
                $login_result = $this->loginUser($user,$pass);
                //$login_result['cookie_user'] = $user;
            }
            else {
                $sso_type = (!empty($sso_data) && !empty($sso_data['tipo'])) ? $sso_data['tipo'] : null;
                switch ($sso_type) {
                    case 'prestashop' :

                        $sso_db_host = (!empty($sso_config) && !empty($sso_config['sso_host'])) ? $sso_config['sso_host'] : null;
                        $sso_db_name = (!empty($sso_config) && !empty($sso_config['sso_db'])) ? $sso_config['sso_db'] : null;
                        $sso_db_user = (!empty($sso_config) && !empty($sso_config['sso_db_user'])) ? $sso_config['sso_db_user'] : null;
                        $sso_db_pass = (!empty($sso_config) && !empty($sso_config['sso_db_pass'])) ? $sso_config['sso_db_pass'] : null;
                        $sso_db_prefix = (!empty($sso_config) && !empty($sso_config['sso_db_prefix'])) ? $sso_config['sso_db_prefix'] : null;
                        $sso_cookie_key = (!empty($sso_config) && !empty($sso_config['cookie_key'])) ? $sso_config['cookie_key'] : null;
                        if (empty($sso_cookie_key)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (cookie_key)');
                        if (empty($sso_db_host)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (sso_db_host)');
                        if (empty($sso_db_name)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (sso_db_name)');
                        if (empty($sso_db_user)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (sso_db_user)');
                        if (empty($sso_db_pass)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (sso_db_pass)');
                        if (empty($sso_db_prefix)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (sso_db_prefix)');
                        for ($i = 0; $i <= 4; $i++) {
                            $sso_db_pass = base64_decode($sso_db_pass);
                            $sso_cookie_key = base64_decode($sso_cookie_key);
                        }
                        $sso_db_object = new mysqli($sso_db_host, $sso_db_user, $sso_db_pass, $sso_db_name);
                        if ($sso_db_object->connect_error) {
                            throw new Exception($sso_db_object->connect_error);
                        }

                        $sso_sql = "SELECT c.id_customer,c.email,c.firstname,c.lastname,c.passwd,c.secure_key as value,l.iso_code FROM " . $sso_db_prefix . "_customer c ";
                        $sso_sql .= "LEFT JOIN " . $sso_db_prefix . "_lang as l on l.id_lang = c.id_lang ";
                        $sso_sql .= "WHERE c.email = '".$user."' AND c.active = 1 AND c.is_guest = 0";
                        //$query_params = array($user);
                        $sso_result = $sso_db_object->query($sso_sql);
                        if ($sso_result && $sso_result->num_rows > 0) {
                            while ($row = $sso_result->fetch_assoc()) {
                                $ps_data = $row;
                            }
                        }
                        if (empty($ps_data))
                            throw new Exception(EntityLib::__('API_WRONG_USER'));
                        else {
                            $ps_passwd = $ps_data['passwd'];
                            $verify = password_verify($pass, $ps_passwd);
                            if (!$verify)
                                throw new Exception(EntityLib::__('API_WRONG_USER'));

                            $login_result = $this->loginUser($user, null, $sso_data);
                            if (empty($login_result)) throw new Exception(EntityLib::__('API_WRONG_USER'));
                            $iso_code = !empty($ps_data['iso_code']) ? $ps_data['iso_code'] : null;
                            $sso_cookie = $ps_data['email'] . '#' . $ps_data['firstname'] . '#' . $ps_data['lastname'] . '#0000-00-00##1#' . $ps_data['iso_code'];
                            require_once(__DIR__ . '/lib/cryptojs-custom/CryptoJsCustom.php');
                            $cryptoJsCustom = new CryptoJsCustom();
                            $cookie_encrypted_data = $cryptoJsCustom->cryptoJs_aes_encrypt($sso_cookie, $sso_cookie_key);
                            $login_result['cookie_value'] = $cookie_encrypted_data;
                            $login_result['cookie_user'] = $user;
                        }
                        break;
                    default :
                        throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG').' (tipo)');
                }
            }
            if(empty($login_result)) throw new Exception(EntityLib::__('API_WRONG_USER'));

        }catch(Exception $e)
        {
            throw new Exception($e->getMessage());
        }
        return $login_result;
    }

    public function getSSOCookie($user,$cookie,$language_id = null){
        try {
            require_once(__DIR__ . '/lib/cryptojs-custom/CryptoJsCustom.php');
            $login_result = array();
            $cryptoJsCustom = new CryptoJsCustom();
            $ssoObject = ClassLoader::getModelObject('_sso');
            $sso_data = $ssoObject->getById(1);
            if (empty($sso_data)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG'));
            $sso_config = !empty($sso_data['config']) ? json_decode($sso_data['config'], JSON_UNESCAPED_UNICODE) : array();

            $sso_cookie_key = (!empty($sso_config) && !empty($sso_config['cookie_key'])) ? $sso_config['cookie_key'] : null;
            if (empty($sso_cookie_key)) throw new Exception(EntityLib::__('API_SSO_WRONG_CONFIG') . ' (cookie_key)');

            for ($i = 0; $i <= 4; $i++) {
                $sso_cookie_key = base64_decode($sso_cookie_key);
            }
            $cookie_encrypted_data = $cryptoJsCustom->cryptoJs_aes_decrypt($cookie, $sso_cookie_key);
            if (!empty($language_id)) {
                $languageObject = ClassLoader::getModelObject('_idiomas', $this->getConnection());
                $language_data = $languageObject->getById($language_id);
                if (!empty($language_data) && !empty($cookie_encrypted_data)) {
                    $language_iso = !empty($language_data['iso']) ? $language_data['iso'] : null;
                    $explode_cookie = explode('#', $cookie_encrypted_data);
                    $last_key = array_key_last($explode_cookie);
                    if ($last_key !== null) {
                        $explode_cookie[$last_key] = $language_iso;
                    }
                    $cookie_modified_data = implode('#', $explode_cookie);
                    $cookie_encrypted_data = $cryptoJsCustom->cryptoJs_aes_encrypt($cookie_modified_data, $sso_cookie_key);
                    $login_result['cookie_value'] = $cookie_encrypted_data;
                    $login_result['cookie_user'] = $user;
                }
            }
        }catch(Exception $e)
        {
            throw new Exception($e->getMessage());
        }
        return $login_result;
    }


    public function setUserLang($user_id,$lang_id)
    {
        if(empty($user_id))
            throw new Exception(EntityLib::__('API_WRONG_LANGUAGE_USER'));
        if(empty($lang_id))
            throw new Exception(EntityLib::__('API_WRONG_LANGUAGE_LANG'));
        $update_user = array(
            'id' => $user_id,
            'lang_id' => $lang_id,
        );
        $usuariosObj = ClassLoader::getModelObject('usuarios',false);
        $usuariosObj->save($update_user);
        return $lang_id;
    }

    /* Función para recuperar la password de un usuario
     * Se envía email con token de recuperación
     */
    public function recoverUser1($user)
    {
        $result = false;
        $userObject = ClassLoader::getModelObject('usuarios',$this->getConnection());
        $options = array(
            'filters' => array(
                'username' => array('=' => $user),
                'deleted' => array('=' => 0),
                'activo' => array('=' => 1),
            ),
        );
        $user_email = null;
        $users = $userObject->getList($options);
        $userData = array();
        if(!empty($users) && !empty($users['data'])) {
            if(count($users['data']) > 1)
                throw new Exception(EntityLib::__('API_GENERAL_NO_SINGLE_USER_FOUND'));
            $userData = $users['data'][0];
            $user_email = $userData['user_email'];
        }
        if(empty($userData))
        {
            // No distnguimos error concreto de que no existe usuario para no revelar información
            throw new Exception(EntityLib::__('API_WRONG_USER_RECOVER'));
        }
        else
        {
            $new_token = EntityLib::getGuidv4();
            $new_time = date('Y-m-d H:i:s');
            $new_expire = date('Y-m-d H:i:s', strtotime('+10 minutes',strtotime($new_time)));
            $user_update = array(
                'id' => $userData['id'],
                'last_recovery_token' => $new_token,
                'last_recovery_in' => $new_time,
                'last_recovery_expires' => $new_expire,
            );
            $updateResult = $userObject->save($user_update);
            if(empty($updateResult))
                throw new Exception(EntityLib::__('API_LOGIN_ERROR_CREATE_TOKEN'));

            $codified_token = base64_encode($new_token);
            $mailer = new Mailer($this->getConnection());
            $to = array($user_email);
            $recovery_address = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/recover-password/'.$codified_token;
            $mail_subject = EntityLib::__('API_RECOVER_SUBJECT');
            $mail_params = array(
                '{$1}' => $user,
                '{$2}' => $recovery_address
            );
            $mail_result = $mailer->sendTemplate($to,'base/recover_step_1.php',$mail_subject,$mail_params);
            if($mail_result['success'])
                $result = true;
            else
                throw new Exception($mail_result['message']);
        }

        return $result;
    }

    /* Función para devolver el usuario asociado al token de recuperación de sesión
     */
    public function recoverUser2($token)
    {
        $result = null;
        $userObject = ClassLoader::getModelObject('usuarios');
        $now = date('Y-m-d H:i:s');
        $options = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'last_recovery_token' => array('=' => $token),
                'last_recovery_in' => array('<=' => $now),
                'last_recovery_expires' => array('>=' => $now),
            ),
        );
        $user = $userObject->getList($options);
        $userData = array();
        if(!empty($user) && !empty($user['data'])) {
            if(count($user['data']) > 1)
                throw new Exception(EntityLib::__('API_GENERAL_NO_SINGLE_USER_FOUND'));
            $userData = $user['data'][0];
        }

        if(empty($userData))
        {
            throw new Exception(EntityLib::__('API_RECOVER_STEP2_WRONG_TOKEN'));
        }
        $result = $userData;
        return $result;
    }

    /* Función para devolver el usuario asociado al token de recuperación de sesión
     */
    public function recoverUser3($token,$new_password)
    {
        $result = array();
        $userObject = ClassLoader::getModelObject('usuarios');
        $userTokenObject = ClassLoader::getModelObject('_usuarios_tokens');
        $now = date('Y-m-d H:i:s');
        $options = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'last_recovery_token' => array('=' => $token),
                'last_recovery_in' => array('<=' => $now),
                'last_recovery_expires' => array('>=' => $now),
            ),
        );
        $user = $userObject->getList($options);
        $userData = array();
        if(!empty($user) && !empty($user['data'])) {
            //
            if(count($user['data']) > 1)
                throw new Exception(EntityLib::__('API_GENERAL_NO_SINGLE_USER_FOUND'));
            $userData = $user['data'][0];
        }

        if(empty($userData))
        {
            throw new Exception(EntityLib::__('API_RECOVER_STEP2_WRONG_TOKEN'));
        }

        $new_token = EntityLib::getGuidv4();
        $new_time = date('Y-m-d H:i:s');
        $new_expire = date('Y-m-d H:i:s', strtotime('+12 hours',strtotime($new_time)));
        $user_update = array(
            'id' => $userData['id'],
            'last_recovery_expires' => $new_time,
            'password_new_1' => $new_password,
            'password_new_2' => $new_password,
            'force_reset' => 0, // Marcamos que ya no hay que resetear por si se forzó
        );
        $updateResult = $userObject->save($user_update);
        if(empty($updateResult))
            throw new Exception(EntityLib::__('API_LOGIN_ERROR_CREATE_TOKEN'));

        $new_time = date('Y-m-d H:i:s');
        $new_expire = date('Y-m-d H:i:s', strtotime('+12 hours',strtotime($new_time)));
        $user_token = array(
            'usuario_id' => $userData['id'],
            'token' => $new_token,
            'session_in' => $new_time,
            'session_expires' => $new_expire,
        );
        $updateResult = $userTokenObject->save($user_token);
        if(empty($updateResult))
            throw new Exception(EntityLib::__('API_RECOVER_STEP3_ERROR_SAVING'));

        $result['token'] = base64_encode($new_token);
        $result['name'] = !empty($userData['nombre']) ? $userData['nombre'] : '-';

        /* Cargamos las posibles dimensiones del usuario */
        $result['__dimensions__'] = EntityLib::loadDimensionsForLoginUser($userData['id']);


        return $result;
    }

    /* Función para comprobar que el token corresponde a un user
     * Devuelve el id del usuario asignado
     */
    public function authUser($token)
    {
        // Permitimos master token para identificar con master user
        if($token === $this::__masterToken)
            return $this::__masterUserId;

        $token = base64_decode($token);
        $result = null;
        $userDimensionObject = ClassLoader::getModelObject('_usuarios_tokens');
        $userDimensionObject->setIgnoreDef(true);
        $userObject = ClassLoader::getModelObject('usuarios');
        $userObject->setIgnoreDef(true);

        $now = date('Y-m-d H:i:s');
        $options = array(
            'filters' => array(
                'deleted' => array('=' => 0),
                'token' => array('=' => $token),
                'session_in' => array('<=' => $now),
                'session_expires' => array('>=' => $now),
            ),
        );
        $user = $userDimensionObject->getList($options);
        $userData = array();
        $usertableData = array();
        if(!empty($user) && !empty($user['data'])) {
            if(count($user['data']) > 1)
                throw new Exception(EntityLib::__('API_GENERAL_NO_SINGLE_USER_FOUND'));

            $userData = $user['data'][0];
            $usertableData = $userObject->getById($userData['usuario_id']);
        }
        if(empty($userData) || empty($usertableData))
        {
            throw new Exception(EntityLib::__('API_WRONG_TOKEN'));
        }
        else if(empty($usertableData['activo']) && !empty($usertableData['observaciones_bloqueo']))
        {
            throw new Exception(EntityLib::__($usertableData['observaciones_bloqueo']));
        }
        $result = array(
            'id' => $userData['usuario_id'],
            'name' => (!empty($usertableData) && !empty($usertableData['nombre'])) ? $usertableData['nombre'] : '',
            'allowed_ips' => (!empty($usertableData) && !empty($usertableData['allowed_ips'])) ? $usertableData['allowed_ips'] : '',
        );
        return $result;
    }

    /* Función para obtener el menú del usuario
     * Se pilla el rol del user y se construye su árbol de menú
     */
    private function prepareSingleMenu($menu_item)
    {
        $padre_id = !empty($menu_item['padre_id']) ? $menu_item['padre_id'] : null;
        $this_id = !empty($menu_item['id']) ? $menu_item['id'] : null;
    }

    private function prepareMenuData($menu_items)
    {
        //echo '<pre>';print_r($menu_items);echo '</pre>';
        $processed_menu_items = array();
        if(!empty($menu_items)) {
            do {
                $changed = false;
                // Le ponemos a todos los elementos el childs
                $pmu = array();
                foreach ($menu_items as $menu_key => $menu_data) {
                    $pmu[$menu_data['id']] = $menu_data;
                    $pmu[$menu_data['id']]['childs'] = array();
                }
                $start = count($menu_items) - 1;
                for($i = $start - 1; $i >= 0; $i--)
                {
                    $menu_key = $i;
                    $menu_data = $pmu[$i];
                    if(!empty($menu_data['padre_id'])) {
                        $menu_items[$menu_data['padre_id']]['childs'] = $menu_data;
                        unset($menu_items[$i]);
                        $changed = true;
                        break;
                    }

                    //echo '<pre>';print_r($menu_key);echo '</pre>';
                }

            } while ($changed);
        }
        //echo '<pre>';print_r($menu_items);echo '</pre>';
        return $processed_menu_items;
    }
    public function getMenuData($rol_ids){

        $query_params = array();
        $query_params_pr = array();
        $rol_id_string = '';
        $query_per_role = '';
        foreach($rol_ids as $ri)
        {
            $query_per_role .= " OR m.rol_ids LIKE ?";
            $query_params_pr[] = '%,'.$ri.',%';
        }

        $database = $this->getConnection();
        $user_translate_to_id = !empty($_SESSION['__lang_id__']) ? ($_SESSION['__lang_id__'] != 1 ? $_SESSION['__lang_id__'] : null) : null;

        if(!empty($user_translate_to_id))
        {
            $query = "SELECT m.*,ifs.valor_varchar AS trad FROM _menus m ";
            $query .= "LEFT JOIN _i18n_fields ifs ON (ifs.tabla = '_menus' AND ifs.registro_id = m.id AND ifs.deleted = 0 AND ifs.idioma_id = ?) ";
            $query .= "WHERE m.deleted = 0 AND (m.rol_ids IS NULL ".$query_per_role.") ORDER BY m.orden";
            $query_params = array($user_translate_to_id);
            $query_params = array_merge($query_params,$query_params_pr);
        }
        else {
            $query = "SELECT m.* FROM _menus m WHERE m.deleted = 0 AND (m.rol_ids IS NULL " . $query_per_role . ") ORDER BY m.orden";
            $query_params = $query_params_pr;
        }
        $menu_items = $database->querySelectAll($query,$query_params);
        //$menu_items_config = $this->prepareMenuData($menu_items);

        return $this->buildMenusRecursive($menu_items);
        
        // $processed_menu = array();
        // foreach($menu_items as $menu_item) {
        //     if(!empty($menu_item['trad']))
        //         $menu_item['nombre'] = $menu_item['trad'];

        //     $menu_parent_id = !empty($menu_item['padre_id']) ? $menu_item['padre_id'] : 0;

        //     if(empty($menu_parent_id)) {
        //         $processed_menu[$menu_item['id']] = $menu_item;
        //     }
        //     else
        //     {
        //         if(empty($processed_menu[$menu_parent_id]))
        //             $processed_menu[$menu_parent_id] = array();
        //         if(empty($processed_menu[$menu_parent_id]['child']))
        //             $processed_menu[$menu_parent_id]['child'] = array();
        //         $processed_menu[$menu_parent_id]['child'][] = $menu_item;
        //     }
        // }

        // $final_menu = array();
        // foreach($processed_menu as $menu_id => $menu_data)
        // {
        //     $menu_data['deleted'] = !empty($menu_data['deleted']) ? 1 : 0;
        //     $menu_data['id'] = !empty($menu_data['deleted']) ? intval($menu_data['id']) : null;
        //     $menu_data['orden'] = !empty($menu_data['orden']) ? intval($menu_data['orden']) : 0;
        //     $menu_data['padre_id'] = !empty($menu_data['padre_id']) ? intval($menu_data['padre_id']) : null;
        //     $final_menu[] = $menu_data;
        // }
        

        // return $final_menu;
    }

    public function buildMenusRecursive($menus, $parent = null)
    {
        $result = array();

        $level_menus = array_filter($menus, function ($menu) use ($parent) {
            return $menu['padre_id'] == $parent;
        });

        foreach ($level_menus as $menu) {
            $children = $this->buildMenusRecursive($menus, $menu['id']);
            if (!empty($children)) {
                $menu['child'] = $children;
            }
            $result[] = $menu;
        }

        usort($result, function ($a, $b) {
            return $a['orden'] <=> $b['orden'];
        });

        return $result;
    }

    /* Habilitamos transacciones en objeto de BDD */
    public function beginTransaction()
    {
        $this->database->beginTransaction();
    }
    public function commitTransaction()
    {
        $this->database->commitTransaction();
    }
    public function rollbackTransaction()
    {
        $this->database->rollbackTransaction();
    }
    public function getConnection(){
        return $this->database;
    }


    /* Comprobación de que exista tabla */
    public function checkTable($table)
    {
        $sql = "SELECT `entity` FROM __schema_tables WHERE ((`api_call` = ?) or (`table` = ?)) AND deleted = 0";
        $query_params = array($table,$table);
        $exists = false;
        $showTables = $this->database->querySelectAll($sql,$query_params);
        if(!empty($showTables))
            $exists = true;
        return $exists;
    }

    public function checkTableName($table)
    {
        $sql = "SELECT `table` FROM __schema_tables WHERE ((`api_call` = ?) or (`table` = ?)) AND deleted = 0";
        $query_params = array($table,$table);
        $tableName = $table;
        $showTables = $this->database->querySelectAll($sql,$query_params);
        if(!empty($showTables))
            $tableName = $showTables[0]['table'];
        return $tableName;
    }

    public function isUserAffectedSso($user,$sso_affected_roles)
    {
        $check_roles_user_query = "SELECT r.codigo FROM _usuarios u LEFT JOIN _usuarios_roles ur ON (ur.usuario_id = u.id) LEFT JOIN _roles r ON (ur.rol_id = r.id) ";
        $check_roles_user_query .= "WHERE u.username = ? AND u.activo = 1 AND ur.deleted = 0 AND r.deleted = 0";
        $query_params = array($user);
        $user_roles = $this->database->querySelectAll($check_roles_user_query,$query_params);
        $is_affected = false;
        if(!empty($user_roles))
        {
            foreach($user_roles as $ur)
            {
                $rol_code = $ur['codigo'];
                if(in_array($rol_code,$sso_affected_roles))
                {
                    $is_affected = true;
                }
            }
        }
        return $is_affected;
    }


}

?>