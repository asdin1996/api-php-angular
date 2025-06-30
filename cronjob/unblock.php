<?php
    $block_cron_file = __DIR__.'/.cron-ejecutandose';
    if(file_exists($block_cron_file)) {
        unlink($block_cron_file);
        echo '<pre>';print_r('CRONJOB desbloqueado correctamente');echo '</pre>';
    }
    else
    {
        echo '<pre>';print_r('CRONJOB no está ejecutándose ahora mismo. No se ha realizado ningún ajuste.');echo '</pre>';
    }
?>