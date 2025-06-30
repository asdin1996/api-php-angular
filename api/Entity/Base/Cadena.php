<?php

require_once(__DIR__.'/../../Entity.php');

class Cadena extends Entity {

    public function __construct($tablename='cadenas',$is_main_rec = false){
        parent::__construct('cadenas',$is_main_rec);
    }

    public function fncExportarJSON($id,$data){
        if(empty($data['wizard1_idiomas'] || empty($data['wizard1_tipos']))) {
            throw new Exception(EntityLib::_('NO_DATOS_EXPORTAR_CADENAS'), 1);
        }

        $respuesta = array();
        $cadenas_vacias = array();
        $cadenas_correctas = array();

        foreach ($data['wizard1_idiomas'] as $idioma) {
            foreach ($data['wizard1_tipos'] as $tipo) {
                $filtros_cadena = array(
                    'filters' => array(
                        'idioma_id' => array( '=' => $idioma),
                        'tipo' => array('=' => $tipo),
                    ),
                );

                $idioma_tipo = array(
                    'idioma' => $idioma,
                    'tipo' => $tipo,
                );

                $resultado = $this->getList($filtros_cadena);
                $cadenas = $resultado['data'];

                if(empty($cadenas)) {
                    $cadenas_vacias[] = $idioma_tipo;
                } else {
                    $cadenas_exportar = array();

                    foreach ($cadenas as $cadena) {
                        $cadenas_exportar[$cadena['origen']] = $cadena['traduccion'];
                    }

                    $ruta = __DIR__ . '/../../../assets/i18n/' . $tipo . '/';
                    $nombre_archivo = $ruta . $idioma . '.json';
                    $contenido_archivo = json_encode($cadenas_exportar, JSON_UNESCAPED_UNICODE);

                    if(!is_dir($ruta)) {
                        mkdir($ruta, 0755, true);
                    }

                    $crear_archivo = file_put_contents($nombre_archivo, $contenido_archivo);
                    if(!$crear_archivo) {
                        $error = error_get_last();
                        throw new Exception("No se ha podido crear el archivo para idioma " . $idioma . " de tipo '" . $tipo . "'<br/><br/>" . $error['message'], 1);
                    }

                    $cadenas_correctas[] = $idioma_tipo;
                }

            }
        }

        $respuesta['message'] = '';

        if(!empty($cadenas_correctas)) {
            $texto = '<p>Archivos de traducciones generados correctamente:</p><ul>';

            foreach ($cadenas_correctas as $cadena) {
                $texto = $texto . $this->crearCadenaLi($cadena);
            }

            $texto = $texto . '</ul>';

            $respuesta['message'] = $respuesta['message'] . $texto;
        }

        if(!empty($cadenas_vacias)) {
            $respuesta['message_format'] = 'warn';
            $aviso = '<p>No se han encontrado cadenas para:</p><ul>';

            foreach ($cadenas_vacias as $cadena) {
                $aviso = $aviso . $this->crearCadenaLi($cadena);
            }

            $aviso = $aviso . '</ul>';

            $respuesta['message'] = $respuesta['message'] . $aviso;
        }

        return $respuesta;        
    }

    public function crearCadenaLi($cadena) {
        $texto = '<li>Idioma: ' . $cadena['idioma'] . '; Tipo: ' . $cadena['tipo'] . '</li>';
        return $texto;
    }
}

?>