<?php

require_once(__DIR__.'/Database.php');

class PermisosController {

    protected $permisosList = array();
    protected $roles_ids = array();
    protected $db = null;
    protected $all_allowed = false;

    public function __construct($roles_ids = array())
    {
        $this->roles_ids = $roles_ids;
        $this->permisosList = array();
        $this->db = !empty($_SESSION['__db__']) ? $_SESSION['__db__'] : array();
        if(!empty($this->roles_ids))
        {
            $this->loadPermissions($roles_ids);
        }
    }
    public function showPermisos()
    {
        echo '<pre>';print_r($this->roles_ids);echo '</pre>';
        echo '<pre>';print_r('AA'.$this->all_allowed);echo '</pre>';
        echo '<pre>';print_r($this->permisosList);echo '</pre>';die;
    }

    private function loadPermissions($roles_ids)
    {
        $processedPermisos = array();
        $all_allowed = false;

        // Añadimos los permisos para los rol_id NULL user_id NULL, globales
        $query = "SELECT * FROM _permisos WHERE rol_id IS NULL AND user_id IS NULL AND deleted = 0";
        $permisos_globales = $this->db->querySelectAll($query);
        if(!empty($permisos_globales))
        {
            foreach($permisos_globales as $pg)
            {
                if(empty($processedPermisos[$pg['controller']]))
                    $processedPermisos[$pg['controller']] = array();
                if(empty($processedPermisos[$pg['controller']][$pg['action']]))
                    $processedPermisos[$pg['controller']][$pg['action']] = array();
                $processedPermisos[$pg['controller']][$pg['action']] = array(
                    'allowed' => true,
                    'no_visible_fields' => !empty($pg['no_visible_fields']) ? $pg['no_visible_fields'] : array(),
                    'no_editable_fields' => !empty($pg['no_editable_fields']) ? $pg['no_editable_fields'] : array(),
                    'disable_actions' => !empty($pg['disable_actions']) ? explode(",",$pg['disable_actions']) : array(),
                );
            }
        }

        // Para cada rol pillamos sus permisos. Ojo, si distintos roles tienen los mismos permisos prevalecen los del primero (ID inferior)
        foreach($roles_ids as $rol_id) {
            $query = "SELECT * FROM _permisos WHERE rol_id = ? AND deleted = ? ORDER BY rol_id ASC";
            $query_params = array($rol_id,0);
            $permisos_rol = $this->db->querySelectAll($query,$query_params);
            foreach($permisos_rol as $pr)
            {
                if(empty($processedPermisos[$pr['controller']]))
                    $processedPermisos[$pr['controller']] = array();
                if(empty($processedPermisos[$pr['controller']][$pr['action']]))
                    $processedPermisos[$pr['controller']][$pr['action']] = array();
                $processedPermisos[$pr['controller']][$pr['action']] = array(
                    'allowed' => true,
                    'no_visible_fields' => !empty($pr['no_visible_fields']) ? $pr['no_visible_fields'] : array(),
                    'no_editable_fields' => !empty($pr['no_editable_fields']) ? $pr['no_editable_fields'] : array(),
                    'disable_actions' => !empty($pr['disable_actions']) ? explode(",",$pr['disable_actions']) : array(),
                );

                if($pr['controller'] === '*' && $pr['action'] === '*')
                    $all_allowed = true;
            }
        }

        // Añadimos los permisos para los rol_id NULL user_id el nuestro de sesión
        $query = "SELECT * FROM _permisos WHERE rol_id IS NULL AND user_id = ? AND deleted = ?";
        $query_params = array($_SESSION['__user_id__'],0);
        $permisos_user = $this->db->querySelectAll($query,$query_params);
        if(!empty($permisos_user))
        {
            foreach($permisos_user as $pu)
            {
                if(empty($processedPermisos[$pu['controller']]))
                    $processedPermisos[$pu['controller']] = array();
                if(empty($processedPermisos[$pu['controller']][$pu['action']]))
                    $processedPermisos[$pu['controller']][$pu['action']] = array();
                $processedPermisos[$pu['controller']][$pu['action']] = array(
                    'allowed' => true,
                    'no_visible_fields' => !empty($pu['no_visible_fields']) ? $pu['no_visible_fields'] : array(),
                    'no_editable_fields' => !empty($pu['no_editable_fields']) ? $pu['no_editable_fields'] : array(),
                    'disable_actions' => !empty($pu['disable_actions']) ? explode(",",$pu['disable_actions']) : array(),
                );

                if($pu['controller'] === '*' && $pu['action'] === '*')
                    $all_allowed = true;
            }
        }

        $this->permisosList = $processedPermisos;
        $this->all_allowed = $all_allowed;
    }

    public function checkPermissions($controller,$action)
    {
        //if($action === 'def') $controller = '__all__';
        if($action === 'panel') $action = 'list';
        $result = array(
            'allowed' => false,
        );

        if(!empty($this->permisosList))
        {
            if(!empty($this->permisosList[$controller]))
            {
                if(!empty($this->permisosList[$controller][$action]))
                {
                    $result['allowed'] = true;
                    $result['no_visible_fields'] = !empty($this->permisosList[$controller][$action]['no_visible_fields']) ? $this->permisosList[$controller][$action]['no_visible_fields'] : array();
                    $result['no_editable_fields'] = !empty($this->permisosList[$controller][$action]['no_editable_fields']) ? $this->permisosList[$controller][$action]['no_editable_fields'] : array();
                    $result['disable_actions'] = !empty($this->permisosList[$controller][$action]['disable_actions']) ? $this->permisosList[$controller][$action]['disable_actions'] : array();
                }
            }
        }


        // Si no hay permiso buscamos accion global
        if(!$result['allowed'])
        {
            $controller = '__all__';
            if(!empty($this->permisosList[$controller]))
            {
                if(!empty($this->permisosList[$controller][$action]))
                {
                    $result['allowed'] = true;
                    $result['no_visible_fields'] = !empty($this->permisosList[$controller][$action]['no_visible_fields']) ? $this->permisosList[$controller][$action]['no_visible_fields'] : array();
                    $result['no_editable_fields'] = !empty($this->permisosList[$controller][$action]['no_editable_fields']) ? $this->permisosList[$controller][$action]['no_editable_fields'] : array();
                    $result['disable_actions'] = !empty($this->permisosList[$controller][$action]['disable_actions']) ? $this->permisosList[$controller][$action]['disable_actions'] : array();
                }
            }
        }

        if($this->all_allowed && empty($result['allowed']))
        {
            $result['allowed'] = true;
            $result['no_visible_fields'] = array();
            $result['no_editable_fields'] = array();
            $result['disable_actions'] = array();
            return $result;
        }

        return $result;
    }

    public function getNoVisibleFields()
    {
        $list = '';
        $result = array();
        $sess_controller = (!empty($_SESSION) && !empty($_SESSION['__controller__'])) ? $_SESSION['__controller__'] : '';
        $sess_action = (!empty($_SESSION) && !empty($_SESSION['__action__'])) ? $_SESSION['__action__'] : '';
        if(!empty($sess_controller) && !empty($sess_action))
        {
            if(!empty($this->permisosList[$sess_controller]) && !empty($this->permisosList[$sess_controller][$sess_action]))
            {
                if(!empty($this->permisosList[$sess_controller][$sess_action]['no_visible_fields']))
                    $list = $this->permisosList[$sess_controller][$sess_action]['no_visible_fields'];
            }
        }
        if(!empty($list)) $result = explode(',',$list);
        return $result;
    }
    public function getNoEditableFields()
    {
        $list = '';
        $result = array();
        $sess_controller = (!empty($_SESSION) && !empty($_SESSION['__controller__'])) ? $_SESSION['__controller__'] : '';
        $sess_action = (!empty($_SESSION) && !empty($_SESSION['__action__'])) ? $_SESSION['__action__'] : '';
        if(!empty($sess_controller) && !empty($sess_action))
        {
            if(!empty($this->permisosList[$sess_controller]) && !empty($this->permisosList[$sess_controller][$sess_action]))
            {
                if(!empty($this->permisosList[$sess_controller][$sess_action]['no_editable_fields']))
                    $list = $this->permisosList[$sess_controller][$sess_action]['no_editable_fields'];
            }
        }
        if(!empty($list)) $result = explode(',',$list);
        return $result;
    }
    public function getDisabledActions()
    {
        $disabled_actions = '';
        $result = array();
        return $result;
        $sess_controller = (!empty($_SESSION) && !empty($_SESSION['__controller__'])) ? $_SESSION['__controller__'] : '';
        $sess_action = (!empty($_SESSION) && !empty($_SESSION['__action__'])) ? $_SESSION['__action__'] : '';
        if(!empty($sess_controller) && !empty($sess_action))
        {
            if(!empty($this->permisosList[$sess_controller]) && !empty($this->permisosList[$sess_controller][$sess_action]))
            {
                if(!empty($this->permisosList[$sess_controller][$sess_action]['disable_actions']))
                    $disabled_actions = $this->permisosList[$sess_controller][$sess_action]['disable_actions'];
            }
        }
        if(!empty($disabled_actions)) $result = explode(',',$disabled_actions);
        return $result;
    }


}

?>