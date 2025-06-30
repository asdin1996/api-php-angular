<?php

// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');
require_once(__DIR__ . '/../../ClassLoader.php');

class Avisos extends Entity
{

    function __construct($tablename='_avisos', $main_rec = false)
    {
        parent::__construct('_avisos', $main_rec);
    }

    public function getList($params = array())
    {
        // Forzamos a filtrar por los buzones del usuario
        if(!isset($params['ignore_user'])) {
            $userDataExtraInfo = EntityLib::getDimensionRolInfo();
            $no_user_filters = array('admin', 'admin_cli');
            $new_filter = array();
            $new_filter_value = array();
            $user_dimension = !empty($userDataExtraInfo['dimension']) ? $userDataExtraInfo['dimension'] : '';
            $user_dimension_value = !empty($userDataExtraInfo['valor']) ? $userDataExtraInfo['valor'] : '';
            if (empty($user_dimension)) {
                $new_filter = array(
                    '__union__' => 'AND',
                    '__conditions__' => array(
                        array(
                            'usuario_id' => array('=' => $_SESSION['__user_id__']),
                        ),
                    ),
                );
            } else {
                $new_filter = array(
                    '__union__' => 'OR',
                    '__conditions__' => array(
                        array(
                            'tipo_usuario_destino' => array('=' => $user_dimension),
                        ),
                        array(
                            'usuario_id' => array('=' => $_SESSION['__user_id__']),
                        ),
                    ),
                );
            }

            if (!in_array($user_dimension, $no_user_filters)) {
                if (empty($user_dimension_value)) {
                    throw new Exception('No se pudo obtener el valor de la dimensión del usuario para mostrar sus avisos correctamente.');
                }
                $new_filter['__conditions__'][0]['tipo_usuario_destino_id'] = array('=' => $user_dimension_value);
            }

            if (empty($params))
                $params = array();
            if (empty($params['filters']))
                $params['filters'] = array();
            $params['filters'][] = $new_filter;
        }
        $result = parent::getList($params);
        return $result;
    }

    public function processItem(&$rec)
    {
        if($this->is_main_rec) {
            $origen = $rec['origen'];
            $origen_id = $rec['origen_id'];
            $origen_procesado = '';
            if (!empty($origen) && !empty($origen_id)) {
                $objRelated = ClassLoader::getModelObject($origen, false);
                $objData = $objRelated->getById($origen_id);
                if (!empty($objData)) {
                    $origen_procesado = $objData['__name__'];
                } else {
                    $origen_procesado = 'No se encontró elemento de origen';
                }
            }
            $rec['origen_procesado'] = $origen_procesado;
        }
    }


    // En soportes nunca habrá ID, solo datos
    public function fncToggleLeido($id,$data)
    {
        $from = $data['__from__'];
        $this_data = $this->getById($id);
        $estado_actual = !empty($this_data) && !empty($this_data['estado']) ? $this_data['estado'] : '';
        $mensaje_final = '';
        if(empty($estado_actual))
            throw new Exception('No se puede realizar la acción puesto que no tiene un estado correcto');
        else
        {
            if($estado_actual === 'nuevo') {
                $estado_nuevo = 'leido';
                $mensaje_final = 'Se marcó el aviso como leído';
            }
            else {
                $estado_nuevo = 'nuevo';
                $mensaje_final = 'Se marcó el aviso como pendiente de leer';
            }
            $update = array(
                'id' => $id,
                'estado' => $estado_nuevo,
            );
            $this->save($update);
        }
        return array('message' => $mensaje_final,'redirect' => $from);
    }

    public function fncGoToSource($id,$data)
    {
        $this_data = $this->getById($id);
        $estado_actual = !empty($this_data) && !empty($this_data['estado']) ? $this_data['estado'] : '';
        if($estado_actual === 'nuevo')
            $this->fncToggleLeido($id,$data);

        $action = 'view';
        $this_data = $this->getById($id);
        if(empty($this_data))
            throw new Exception('No se encontró el elemento.');
        $origen = $this_data['origen'];
        $origen_id = $this_data['origen_id'];
        $link = '';
        if (!empty($origen) && !empty($origen_id)) {
            $objRelated = ClassLoader::getModelObject($origen, true);
            $objData = $objRelated->getById($origen_id);
            if(!empty($this_data['custom_action'])) {
                $custom_action = $this_data['custom_action'];
                if(in_array($custom_action,array('edit')))
                    $action = 'edit';
                else {
                    return $objRelated->$custom_action($origen_id,$data);
                }
            }
            if (!empty($objData))
                $link = '/'.$origen.'/'.$action.'/'.$origen_id;
        }
        if(empty($link))
        {
            throw new Exception('El aviso no tiene una entidad de origen para visualizar');
        }

        $result['redirect'] = $link;
        return $result;
    }

    public function getAvisosPendientesUser($user_id)
    {
        $filters = array(
            'deleted' => array('=' => 0),
            'estado' => array('=' => 'nuevo'),
        );
        $avisosData = $this->getList(array('filters'=>$filters,'ignore_related' => true));
        $total = !empty($avisosData) && !empty($avisosData['data']) ? count($avisosData['data']) : 0;
        return $total;
    }

    public function fncMarcarTodos($id, $data)
    {
        $from = $data['__from__'];
        $marcados = 0;
        $avisosOpt = array(
            'filters' => array(
            ),
        );
        if(!empty($data['__filters__']))
            $avisosOpt['filters'] = $data['__filters__'];
        $combined_filter = array(
            '__union__' => 'AND',
            '__conditions__' => array(
                array('estado' => array('=' => 'nuevo')),
            ),
        );
        $avisosOpt['filters'][] = $combined_filter;

        $avisosData = $this->getList($avisosOpt);
        if(!empty($avisosData) && !empty($avisosData['data']))
        {
            foreach($avisosData['data'] as $aviso)
            {
                $update_aviso = array(
                    'id' => $aviso['id'],
                    'estado' => 'leido',
                );
                $this->save($update_aviso);
                $marcados++;

            }
            $mensaje_final = 'Se marcaron '.$marcados.' avisos como leídos';
        }
        else
        {
            throw new Exception('No se encontraron avisos pendientes de marcar como leidos');
        }
        return array('message' => $mensaje_final,'redirect' => $from);
    }

}

