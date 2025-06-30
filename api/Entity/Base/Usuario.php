<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');

class Usuario extends Entity
{
    function __construct($tablename='_usuarios', $main_rec = false)
    {
        parent::__construct('_usuarios', $main_rec);
    }

    public function beforeSave(&$data){
        $errors = parent::beforeSave($data);
        if(!empty($data['password_new_1']) && !empty($data['password_new_2']))
        {
            $clean_password = trim($data['password_new_1']);
            $new_password = base64_decode($clean_password);

            $password_requirement_type = EntityLib::getConfig('app_password_requirement');
            if(empty($password_requirement_type)) $password_requirement_type = 0;
            $password_messsage = 'API_USER_PASSWORD_REQUIREMENTS_ERROR';
            if(!empty($password_requirement_type)) $password_messsage .= '_'.$password_requirement_type;
            if(!EntityLib::checkPasswordRequirements($new_password,$password_requirement_type))
            {
                $errors[] = array('error' => EntityLib::__($password_messsage));
            }
            $new_md5pass = md5($new_password);
            $data['password'] = $new_md5pass;
            unset($data['password_new_1']);
            unset($data['password_new_2']);
        }
        return $errors;
    }

    public function validate($data)
    {
        $errors = parent::validate($data);
        // Limpiamos username
        if(!empty($data['username'])) {
            $username = !empty($data['username']) ? $data['username'] : '';
            $username = trim($username);
            //$email = strtolower($email);
            if (empty($username)) {
                $errors[] = array('field' => 'username', 'error' => EntityLib::__('API_USER_EMPTY_USERNAME_ERROR'));
            } else {
                /* Ya no es un email
                $is_email_ok = EntityLib::validateEmailFormat($email);
                if (!$is_email_ok) {
                    $errors[] = array('field' => 'username', 'error' => EntityLib::__('API_USER_WRONG_USERNAME_ERROR'));
                } else {
                */
                    $actual_id = !empty($data['id']) ? $data['id'] : 0;
                    $filters = array(
                        'deleted' => array('=' => 0),
                        'username' => array('=' => $username),
                        'activo' => array('=' => 1),
                    );
                    if(!empty($actual_id))
                    {
                        $filters['id'] = array('!=' => $actual_id);
                    }
                    $users = $this->getList(array('filters' => $filters));
                    $repetido = !empty($users) && !empty($users['data']);
                    if($repetido)
                    {
                        $errors[] = array('field' => 'username', 'error' => EntityLib::__('API_USER_UNIQUE_USERNAME_ERROR'));
                    }
                    else
                        $data['username'] = $username;
                /* Ya no es un email
                }
                */
            }
        }

        if(empty($data['id']) && empty($data['username']))
        {
            $errors[] = array('field' => 'username', 'error' => EntityLib::__('API_USER_EMPTY_USERNAME_ERROR'));
        }

        return $errors;
    }

    
}

