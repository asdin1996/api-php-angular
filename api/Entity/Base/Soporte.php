<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');
require_once(__DIR__ . '/../../Mailer.php');
require_once(__DIR__ . '/../../ClassLoader.php');

class Soporte extends Entity
{

    const _ENTITY_SAVED = 'Se envió correctamente su solicitud de soporte. Será revisada por nuestro personal lo antes posible. Nos pondremos en contacto con usted en el correo electrónico asociado a su usuario.';

    function __construct($tablename='_soportes', $main_rec = false)
    {
        parent::__construct('_soportes', $main_rec);
    }

    public function beforeSave(&$data){
        $errors = parent::beforeSave($data);
        $crear_buzon_admon = false;
        $crear_buzon_soporte = false;
        $userDataExtraInfo = EntityLib::getDimensionRolInfo();
        if(empty($data['id'])) {
            $mail_sent = false;
            if (empty($errors)) {
                $config_mail_soporte = $this->getConfigForTipo($data['tipo']);
                $config_mail = (!empty($config_mail_soporte) && !empty($config_mail_soporte['mail'])) ? $config_mail_soporte['mail'] : array('soporte@agenciaekiba.com');
                $config_mail_buzon = (!empty($config_mail_soporte) && !empty($config_mail_soporte['buzon'])) ? $config_mail_soporte['buzon'] : false;
                $config_mail_template = (!empty($config_mail_soporte) && !empty($config_mail_soporte['template'])) ? $config_mail_soporte['template'] : 'soporte/new.php';
                $config_mail_cc = (!empty($config_mail_soporte) && !empty($config_mail_soporte['cc'])) ? $config_mail_soporte['cc'] : array();
                $config_mail_bcc = (!empty($config_mail_soporte) && !empty($config_mail_soporte['bcc'])) ? $config_mail_soporte['bcc'] : array();

                $config_mail_nombre = EntityLib::getConfig('smtp_name');
                $url = $_SERVER['HTTP_ORIGIN'];
                $mailer = new Mailer();

                // Pillamos datos de sesión de usuario que lanza petición
                $userId = !empty($_SESSION) && !empty($_SESSION['__user_id__']) ? $_SESSION['__user_id__'] : 0;
                $userData = array();
                if(!empty($userId))
                {
                    $userObj = ClassLoader::getModelObject('usuarios');
                    $userData = $userObj->getById($userId);
                }
                $user_email = !empty($userData) && !empty($userData['username']) ? $userData['username'] : '';
                $user_name = !empty($userData) && !empty($userData['nombre']) ? $userData['nombre'] : '';

                $defaultDef = $this->loadDefinition(1);
                $tipo_str = '';
                if(!empty($defaultDef['fields']['tipo']['enum_options'][$data['tipo']]))
                    $tipo_str = $defaultDef['fields']['tipo']['enum_options'][$data['tipo']];

                if(empty($tipo_str))
                    $asunto = '['.$config_mail_nombre.'] Nueva solicitud de soporte';
                else
                    $asunto = '['.$config_mail_nombre.']['.$tipo_str.'] Nueva solicitud de soporte';

                $asunto_mail = $data['asunto'];
                $body = $data['cuerpo'];
                $user_type_label = !empty($userDataExtraInfo['label']) ? $userDataExtraInfo['label'] : null;
                $user_type_name = !empty($userDataExtraInfo['valor_mostrar']) ? $userDataExtraInfo['valor_mostrar'] : null;
                $params = array(
                    '{$1}' => $userId,
                    '{$2}' => $user_email,
                    '{$3}' => $user_name,
                    '{$4}' => $asunto_mail,
                    '{$5}' => $body,
                    '{$6}' => $user_type_label,
                    '{$7}' => $user_type_name,
                );
                $params = $this->getExtraParams($data['tipo'],$params);

                if (empty($config_mail))
                    throw new Exception(EntityLib::__('SOPORTE_CONFIGURATION'));

                $to = $config_mail;
                $crear_buzon = !empty($config_mail_buzon) ? $config_mail_buzon : false;

                if (!empty($to)) {
                    $data['to'] = implode(";", $to);
                    $data['fecha_hora_envio'] = date('Y-m-d H:i:s');
                }

                if($crear_buzon)
                {
                    $buzonDestinoObj = ClassLoader::getModelObject($config_mail_buzon,false);
                    $new = array(
                        'asunto' => $data['asunto'],
                        'cuerpo' => $data['cuerpo'],
                        'estado' => 'nuevo',
                        'tipo_usuario_origen' => !empty($userDataExtraInfo['dimension']) ? $userDataExtraInfo['dimension'] : null,
                        'tipo_usuario_origen_label' => !empty($userDataExtraInfo['label']) ? $userDataExtraInfo['label'] : null,
                        'tipo_usuario_origen_id' => !empty($userDataExtraInfo['valor']) ? intval($userDataExtraInfo['valor']) : null,
                        'tipo_usuario_origen_name' => !empty($userDataExtraInfo['valor_mostrar']) ? $userDataExtraInfo['valor_mostrar'] : null,
                    );
                    $buzonDestinoObj->save($new);
                }
                if(!empty($config_mail_cc)) $mailer->setCC($config_mail_cc);
                if(!empty($config_mail_bcc)) $mailer->setBCC($config_mail_bcc);

                $msg = '';
                try {
                    $resultMail = $mailer->sendTemplate($to,$config_mail_template,$asunto,$params);
                    $mail_sent = !empty($resultMail) && !empty($resultMail['success']);
                    $msg = (!empty($resultMail) && !empty($resultMail['message'])) ? $resultMail['message'] : '';
                }catch(Exception $e)
                {
                    $mail_sent = false;
                    $msg = $e->getMessage();
                }
            }

            if (!$mail_sent) {
                $error_msg = EntityLib::__('API_MAIL_SENT_ERROR');
                if(!empty($msg))
                    $error_msg  .= ' - '.$msg;
                throw new Exception($error_msg);
            }

        }
        return $errors;
    }

    // En soportes nunca habrá ID, solo datos
    public function fncEnviarSoporte($id_nulo,$data)
    {
        $asunto = !empty($data['asunto']) ? trim($data['asunto']) : '';
        $cuerpo = !empty($data['cuerpo']) ? trim($data['cuerpo']) : '';
        if(empty($asunto) || empty($cuerpo))
            throw new Exception(EntityLib::__('API_MAIL_SENT_KO_FIELDS'));
        $this->save($data);
        return array('message' => EntityLib::__('API_MAIL_SENT_OK_USER'));
    }


    // Esta función incluye funcionalidad por defecto. En caso de ExtendSoporte, llamarla con parent y luego ampliar
    protected function getConfigForTipo($tipo)
    {
        $config_mail_admin = EntityLib::getConfig('admin_mail'.$tipo);
        $config_mail_admin_global = EntityLib::getConfig('admin_mail');
        $config_mail_tipo = EntityLib::getConfig('soporte_'.$tipo);
        $config_mail_tipo_buzon = EntityLib::getConfig('soporte_'.$tipo.'_buzon');
        $config_mail_cc = EntityLib::getConfig('soporte_'.$tipo.'_cc');
        $config_mail_bcc = null;

        // Si no se ha configurado admin_email, forzamos valor
        if(empty($config_mail_admin)) $config_mail_admin = $config_mail_admin_global;
        if(empty($config_mail_admin)) $config_mail_admin = 'alberto.garcia@agenciaekiba.com';
        // Limpiamos admin email para que sea un array por si se han especificado varios destinatarios
        if(!empty($config_mail_admin))
        {
            $config_mail_admin = str_replace(' ','',$config_mail_admin);
            $config_mail_admin = explode(';',$config_mail_admin);
        }

        // Limpiamos email para que sea un array por si se han especificado varios destinatarios para el tipo
        // Si no hay email para el tipo, pondremos el del admin
        if(empty($config_mail_tipo))
            $config_mail_tipo = $config_mail_admin;
        else
        {
            $config_mail_tipo = str_replace(' ','',$config_mail_tipo);
            $config_mail_tipo = explode(';',$config_mail_tipo);
        }
        if(!empty($config_mail_cc))
        {
            $config_mail_cc = str_replace(' ','',$config_mail_cc);
            $config_mail_cc = explode(';',$config_mail_cc);
        }

        // Marcamos el buzón de consulta / incidencias
        if(empty($config_mail_tipo_buzon)) $config_mail_tipo_buzon = false;
        if(!empty($config_mail_tipo_buzon))
        {
            switch($tipo)
            {
                case 'consulta' : $config_mail_tipo_buzon = 'buzon_admon'; break;
                case 'incidencia' : $config_mail_tipo_buzon = 'buzon_soporte'; break;
                default : $config_mail_tipo_buzon = false; break;
            }
        }

        switch($tipo)
        {
            case 'incidencia' :
            case 'consulta':
                $template = 'soporte/new.php';
                $config_mail_bcc = $config_mail_admin;
                break;
            default :
                $template = 'soporte/'.$tipo.'.php';
                $config_mail_bcc = $config_mail_admin;
                break;
        }
        $result = array(
            'mail' => $config_mail_tipo,
            'buzon' => $config_mail_tipo_buzon,
            'template' => $template,
        );
        if(!empty($config_mail_cc)) $result['cc'] = $config_mail_cc;
        if(!empty($config_mail_bcc)) $result['bcc'] = $config_mail_bcc;
        return $result;

    }

    // Esta función incluye funcionalidad por defecto. En caso de ExtendSoporte, llamarla con parent y luego ampliar
    protected function getExtraParams($tipo,$params)
    {
        return $params;
    }

}

