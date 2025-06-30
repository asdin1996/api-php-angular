<?php
// Importante, el base tira 2 hacia atrás!
require_once(__DIR__ . '/../../Entity.php');
require_once(__DIR__ . '/../../ClassLoader.php');

class Buzon extends Entity
{

    function __construct($tablename='_buzon_todos', $main_rec = false)
    {
        parent::__construct('_buzon_todos', $main_rec);
    }

    public function getList($params = array())
    {
        // Forzamos a filtrar por los buzones del usuario
        $userDataExtraInfo = EntityLib::getDimensionRolInfo();
        $no_user_filters = array('admin','admin_cli');
        $new_filter = array();
        $new_filter_value = array();
        $user_dimension = !empty($userDataExtraInfo['dimension']) ? $userDataExtraInfo['dimension'] : '';
        $user_dimension_value = !empty($userDataExtraInfo['valor']) ? $userDataExtraInfo['valor'] : '';
        if(empty($user_dimension))
        {
            $new_filter = array(
                '__union__' => 'AND',
                '__conditions__' => array(
                    array(
                        'usuario_id' => array('=' => $_SESSION['__user_id__']),
                    ),
                ),
            );
        }
        else {
            $new_filter = array(
                '__union__' => 'OR',
                '__conditions__' => array(
                    array(
                        'tipo_usuario_destino' => array('=' => $user_dimension),
                        'tipo' => array('=' => 'entrada'),
                    ),
                    array(
                        'tipo_usuario_origen' => array('=' => $user_dimension),
                        'tipo' => array('=' => 'salida'),
                    ),
                    array(
                        'usuario_id' => array('=' => $_SESSION['__user_id__']),
                    ),
                ),
            );
        }

        if(!in_array($user_dimension,$no_user_filters))
        {
            if(empty($user_dimension_value))
            {
                throw new Exception('No se pudo obtener el valor de la dimensión del usuario para mostrar su buzón correctamente.');
            }
            $new_filter['__conditions__'][0]['tipo_usuario_destino_id'] = array('=' => $user_dimension_value);
            $new_filter['__conditions__'][1]['tipo_usuario_origen_id'] = array('=' => $user_dimension_value);
        }

        if(empty($params))
            $params = array();
        if(empty($params['filters']))
            $params['filters'] = array();
        $params['filters'][] = $new_filter;

        // Filtro de tipo
        if(!empty($params['filters']) && !empty($params['filters']['filtro_tipo']))
        {
            $valor_buscar = $params['filters']['filtro_tipo'];
            unset($params['filters']['filtro_tipo']);
            $new_filter = array(
                '__union__' => 'OR',
                '__conditions__' => array(
                    array(
                        'tipo_usuario_origen' => $valor_buscar,
                    ),
                    array(
                        'tipo_usuario_origen_label' => $valor_buscar,
                    ),
                    array(
                        'tipo_usuario_origen_name' => $valor_buscar,
                    ),
                    array(
                        'tipo_usuario_destino' => $valor_buscar,
                    ),
                    array(
                        'tipo_usuario_destino_label' => $valor_buscar,
                    ),
                    array(
                        'tipo_usuario_destino_name' => $valor_buscar,
                    ),
                ),
            );
            $params['filters'][] = $new_filter;
        }


        $result = parent::getList($params);
        return $result;
    }

    // En soportes nunca habrá ID, solo datos
    public function fncResponder($id,$data)
    {
        $this_data = $this->getById($id);
        $estado_actual = !empty($this_data) && !empty($this_data['estado']) ? $this_data['estado'] : '';
        if(empty($estado_actual))
            throw new Exception('No se puede realizar la acción puesto que no tiene un estado correcto');
        else
        {
            $now = date('Y-m-d H:i:s');
            $update_fields = array();
            $update_fields['estado'] = 'respondido';
            $update_fields['datetime_respuesta'] = $now;
            $update_fields['datetime_upd'] = $now;
            $update_fields['user_upd_id'] = $_SESSION['__user_id__'];
            if($estado_actual === 'nuevo') {
                $update_fields['datetime_leido'] = $now;
            }
            $sql_query_aux = EntityLib::generateSqlv2('_buzon','UPDATE',$update_fields,array('id' => $id));
            $sql_query = $sql_query_aux[0];
            $sql_params = $sql_query_aux[1];
            $this->database->query($sql_query,$sql_params);


            $cuerpo_respuesta = $data['wizard1_cuerpo'];
            $asunto_respuesta = 'RE: ' . $this_data['asunto'];
            $cuerpo_respuesta .= "\r\n";
            $cuerpo_respuesta .= "\r\n";
            $cuerpo_respuesta .= '--------------------------------';
            $cuerpo_respuesta .= "\r\n";
            $cuerpo_respuesta .= $this_data['cuerpo'];

            $userDataExtraInfo = EntityLib::getDimensionRolInfo();
            $new_buzon_respuesta = array(
                'estado' => 'nuevo',
                'user_add_id' => $_SESSION['__user_id__'],
                'datetime_add' => $now,
                'usuario_destino_id' => $this_data['user_add_id'],
                'buzon_origen_id' => $id,
                'asunto' => $asunto_respuesta,
                'cuerpo' => $cuerpo_respuesta,
                'tipo_usuario_destino' => $this_data['tipo_usuario_origen'],
                'tipo_usuario_destino_label' => $this_data['tipo_usuario_origen_label'],
                'tipo_usuario_destino_id' => $this_data['tipo_usuario_origen_id'],
                'tipo_usuario_destino_name' => $this_data['tipo_usuario_origen_name'],
                'tipo_usuario_origen' => !empty($userDataExtraInfo['dimension']) ? $userDataExtraInfo['dimension'] : null,
                'tipo_usuario_origen_label' => !empty($userDataExtraInfo['label']) ? $userDataExtraInfo['label'] : null,
                'tipo_usuario_origen_id' => !empty($userDataExtraInfo['valor']) ? intval($userDataExtraInfo['valor']) : null,
                'tipo_usuario_origen_name' => !empty($userDataExtraInfo['valor_mostrar']) ? $userDataExtraInfo['valor_mostrar'] : null,
            );
            if(!empty($data) && !empty($data['wizard1_adjunto_new'])) {
                $new_buzon_respuesta['adjunto_new'] = $data['wizard1_adjunto_new'];
                $new_buzon_respuesta['adjunto_new_name'] = $data['wizard1_adjunto_new_name'];
                $new_buzon_respuesta['adjunto_new_size'] = $data['wizard1_adjunto_new_size'];
                $new_buzon_respuesta['adjunto_new_ext'] = $data['wizard1_adjunto_new_ext'];
            }

            $buzonObj = ClassLoader::getModelObject('buzon_source',true);
            $buzonObj->save($new_buzon_respuesta);

        }
        return array('message' => 'Se respondió al mensaje correctamente','redirect' => '/buzon');
    }

    public function fncVerDetalle($id,$data)
    {
        $message = '';
        $this_data = $this->getById($id);
        if(empty($this_data))
            throw new Exception('No se encontró el elemento.');

        // Si el mensaje está en estado nuevo
        if(!empty($this_data) && !empty($this_data['estado']) && $this_data['estado'] === 'nuevo')
        {
            // Comprobamos que el usuario sea el destinatario. Si es así, actualizamos estado y fecha de lectura
            $userDataExtraInfo = EntityLib::getDimensionRolInfo();
            $dimension_origen = !empty($userDataExtraInfo['dimension']) ? $userDataExtraInfo['dimension'] : null;
            $dimension_value = !empty($userDataExtraInfo['valor']) ? intval($userDataExtraInfo['valor']) : null;
            $no_user_filters = array('admin','admin_cli');
            $misma_dimension = $dimension_origen == $this_data['tipo_usuario_destino'];
            if(!in_array($dimension_origen,$no_user_filters))
            {
                $misma_dimension = $misma_dimension && $dimension_value == $this_data['tipo_usuario_destino_id'];
            }
            if($misma_dimension)
            {
                $update_data = array(
                    'id' => $id,
                    'datetime_leido' => date('Y-m-d H:i:s'),
                    'estado' => 'leido',
                );
                $buzonObj = ClassLoader::getModelObject('buzon_source', true);
                $buzonObj->save($update_data);
                $message = 'Se marcó el mensaje como leído.';
            }

        }

        $link = '/buzon/view/'.$id;
        $result['redirect'] = $link;
        if(!empty($message))
            $result['message'] = $message;
        return $result;
    }

    public function getBuzonesPendientesUser($user_id)
    {
        $filters = array(
            'deleted' => array('=' => 0),
            'estado' => array('=' => 'nuevo'),
            'tipo' => array('=' => 'entrada'),
        );
        $buzonesData = $this->getList(array('filters'=>$filters,'ignore_related' => true));
        $total = !empty($buzonesData) && !empty($buzonesData['data']) ? count($buzonesData['data']) : 0;
        return $total;
    }

    public function fncMarcarDestacado($id,$data)
    {
        $from = $data['__from__'];
        $prevData = $this->getById($id);
        switch($prevData['tipo'])
        {
            case 'salida' :
                $field_to_update = 'destacado_origen';
                break;
            case 'entrada' :
                $field_to_update = 'destacado_destino';
                break;
        }
        $update_fields = array(
            $field_to_update => 1,
        );
        $sql_query_aux = EntityLib::generateSqlv2('_buzon','UPDATE',$update_fields,array('id' => $id));
        $sql_query = $sql_query_aux[0];
        $sql_params = $sql_query_aux[1];
        $this->database->query($sql_query,$sql_params);
        return array('message' => 'Se marcó el mensaje como destacado','redirect' => $from);
    }

    public function fncDesmarcarDestacado($id,$data)
    {
        $from = $data['__from__'];
        $prevData = $this->getById($id);
        switch($prevData['tipo'])
        {
            case 'salida' :
                $field_to_update = 'destacado_origen';
                break;
            case 'entrada' :
                $field_to_update = 'destacado_destino';
                break;
        }
        $update_fields = array(
            $field_to_update => 0,
        );
        $sql_query_aux = EntityLib::generateSqlv2('_buzon','UPDATE',$update_fields,array('id' => $id));
        $sql_query = $sql_query_aux[0];
        $sql_params = $sql_query_aux[1];
        $this->database->query($sql_query,$sql_params);
        return array('message' => 'Se desmarcó el mensaje como destacado','redirect' => $from);
    }

}

