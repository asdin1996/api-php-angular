<?php
require_once(__DIR__.'/lib/PHPMailer-master/src/PHPMailer.php');
require_once(__DIR__.'/lib/PHPMailer-master/src/SMTP.php');
require_once(__DIR__.'/lib/PHPMailer-master/src/Exception.php');
use PHPMailer\PHPMailer\PHPMailer;
class Mailer {

    protected $db;
    protected $smtp_host = '';
    protected $smtp_port = 587;
    protected $smtp_user = '';
    protected $smtp_pass = '';
    protected $smtp_secure = '';
    protected $smtp_from = '';
    protected $smtp_name = '';
    protected $smtp_cc = array();
    protected $smtp_bcc_propio = array();
    protected $smtp_bcc = array();

    protected $basetemplate = 'mail.php';

    protected $template = '';

    public function __construct($dbObject=null)
    {
        if(is_null($dbObject)) {
            $this->db = !empty($_SESSION['__db__']) ? $_SESSION['__db__'] : array();
        }
        else
            $this->db = $dbObject;
        $query = "SELECT path,value FROM _configuracion WHERE deleted = 0 AND path LIKE 'smtp_%'";
        $configs = $this->db->querySelectAll($query);
        if(!empty($configs))
        {
            foreach($configs as $config)
            {
                switch($config['path'])
                {
                    case 'smtp_host' : $this->smtp_host = $config['value'];break;
                    case 'smtp_port' : $this->smtp_port = intval($config['value']);break;
                    case 'smtp_user' : $this->smtp_user = $config['value'];break;
                    case 'smtp_pass' : $this->smtp_pass = base64_decode($config['value']);break;
                    case 'smtp_secure' : $this->smtp_secure = !empty($config['value']) ? intval($config['value']) : '';break;
                    case 'smtp_from' : $this->smtp_from = $config['value'];break;
                    case 'smtp_name' : $this->smtp_name = $config['value'];break;
                    case 'smtp_bcc' :
                        $aux = explode(";",$config['value']);
                        $this->smtp_bcc = $aux;
                        break;

                }
            }
        }
    }

    public function setCC($to)
    {
        $this->smtp_cc = $to;
    }
    public function setBCC($to)
    {
        $this->smtp_bcc_propio = $to;
    }

    public function sendMail($mailsTo,$subject,$message,$debug=false)
    {
        $success = false;
        $return_msg = '';
        $SMTP_OPTIONS = array(
            'bcc' => '',
            'debug' => false,
        );
        $_OPTIONS = array();
        $SMTP_OPTIONS['smtp_host'] = $this->smtp_host;
        $SMTP_OPTIONS['smtp_user'] = $this->smtp_user;
        $SMTP_OPTIONS['smtp_pass'] = $this->smtp_pass;
        $SMTP_OPTIONS['smtp_port'] = $this->smtp_port;
        $SMTP_OPTIONS['smtp_secure'] = $this->smtp_secure;
        $SMTP_OPTIONS['smtp_from'] = $this->smtp_from;
        $SMTP_OPTIONS['smtp_name'] = $this->smtp_name;
        if(!empty($this->smtp_cc))
            $SMTP_OPTIONS['smtp_cc'] = $this->smtp_cc;
        if(!empty($this->smtp_bcc_propio))
            $SMTP_OPTIONS['smtp_bcc'] = $this->smtp_bcc_propio;
        $SMTP_OPTIONS['smtp_debug'] = $debug;

        try {
            $smtp_host = $SMTP_OPTIONS['smtp_host'];
            $smtp_user = $SMTP_OPTIONS['smtp_user'];
            $smtp_pass = $SMTP_OPTIONS['smtp_pass'];
            $smtp_port = $SMTP_OPTIONS['smtp_port'];
            $smtp_secure = $SMTP_OPTIONS['smtp_secure'];
            $from = $SMTP_OPTIONS['smtp_from'];
            $from_name = $SMTP_OPTIONS['smtp_name'];
            $mail = new PHPMailer;
            $mail->Timeout = 15;
            if(!empty($SMTP_OPTIONS['smtp_debug']) || !empty($SMTP_OPTIONS['debug']))
                $mail->SMTPDebug=2;
            $mail->IsHTML(true);
            $mail->CharSet = 'UTF-8';

            switch($smtp_secure)
            {
                case 1 : $mail->SMTPSecure = 'tls'; break;
                case 2 : $mail->SMTPSecure = 'ssl'; break;
                default : $mail->SMTPSecure = false; $mail->SMTPAutoTLS = false; break;
            }

            $require_auth = !empty($smtp_user) && !empty($smtp_pass);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;

            if($require_auth)
            {
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
            }
            else
            {
                $mail->SMTPAuth = false;
            }

            $mail->setFrom($from, $from_name);
            $mail->addReplyTo($from, $from_name);

            foreach($mailsTo as $mailTo)
            {
                if(is_array($mailTo))
                {
                    $to = $mailTo[0];
                    $to_name = $mailTo[1];
                    $mail->addAddress($to, $to_name);
                }
                else
                {
                    $to = $mailTo;
                    $to_name = '';
                    $mail->addAddress($to, $to_name);
                }
            }
            if(!empty($SMTP_OPTIONS['smtp_cc'])) {
                foreach($SMTP_OPTIONS['smtp_cc'] as $bc) {
                    $mail->addCC($bc);
                }
            }
            if(!empty($SMTP_OPTIONS['smtp_bcc'])) {
                foreach($SMTP_OPTIONS['smtp_bcc'] as $bcc) {
                    $mail->addBCC($bcc);
                }
            }
            $mail->Subject = $subject;
            $mail->Body = $message;

            if (!$mail->send())
                throw new Exception($mail->ErrorInfo);

            $success = true;
            $return_msg = 'Mensaje enviado correctamente';
        }catch(Exception $e)
        {
            $return_msg = 'Se produjo el siguiente error enviando el mensaje: '.$e->getMessage();
        }
        $this->smtp_bc = array();
        $this->smtp_bcc = array();
        return array(
            'success' => $success,
            'message' => $return_msg
        );
    }

    public function setBaseTemplate($name)
    {
        $this->basetemplate = $name;
    }

    public function sendTemplate($mailsTo,$templatePath,$asunto,$params,$debug=false){
        $success = false;
        $return_msg = '';
        $SMTP_OPTIONS = array(
            'bcc' => '',
            'debug' => false,
        );
        $_OPTIONS = array();
        $SMTP_OPTIONS['smtp_host'] = $this->smtp_host;
        $SMTP_OPTIONS['smtp_user'] = $this->smtp_user;
        $SMTP_OPTIONS['smtp_pass'] = $this->smtp_pass;
        $SMTP_OPTIONS['smtp_port'] = $this->smtp_port;
        $SMTP_OPTIONS['smtp_secure'] = $this->smtp_secure;
        $SMTP_OPTIONS['smtp_from'] = $this->smtp_from;
        $SMTP_OPTIONS['smtp_name'] = $this->smtp_name;
        if(!empty($this->smtp_cc))
            $SMTP_OPTIONS['smtp_cc'] = $this->smtp_cc;
        if(!empty($this->smtp_bcc_propio))
            $SMTP_OPTIONS['smtp_bcc'] = $this->smtp_bcc_propio;
        $SMTP_OPTIONS['smtp_debug'] = $debug;

        try {
            $smtp_host = $SMTP_OPTIONS['smtp_host'];
            $smtp_user = $SMTP_OPTIONS['smtp_user'];
            $smtp_pass = $SMTP_OPTIONS['smtp_pass'];
            $smtp_port = $SMTP_OPTIONS['smtp_port'];
            $smtp_secure = $SMTP_OPTIONS['smtp_secure'];
            $from = $SMTP_OPTIONS['smtp_from'];
            $from_name = $SMTP_OPTIONS['smtp_name'];
            $mail = new PHPMailer;
            $mail->Timeout = 15;
            if(!empty($SMTP_OPTIONS['smtp_debug']) || !empty($SMTP_OPTIONS['debug']))
                $mail->SMTPDebug = 2;
            $mail->IsHTML(true);
            $mail->CharSet = 'UTF-8';

            switch($smtp_secure)
            {
                case 1 : $mail->SMTPSecure = 'tls'; break;
                case 2 : $mail->SMTPSecure = 'ssl'; break;
                default :
                    $mail->SMTPSecure = false;
                    $mail->SMTPAutoTLS = false;
                    break;

            }

            $require_auth = !empty($smtp_user) && !empty($smtp_pass);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;

            if($require_auth)
            {
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
            }
            else
            {
                $mail->SMTPAuth = false;
            }

            $mail->setFrom($from, $from_name);
            $mail->addReplyTo($from, $from_name);

            foreach($mailsTo as $mailTo)
            {
                $to = '';
                if(is_array($mailTo))
                {
                    $to = $mailTo[0];
                    $to_name = $mailTo[1];
                    $mail->addAddress($to, $to_name);
                }
                else
                {
                    $to = $mailTo;
                    $to_name = '';
                    $mail->addAddress($to, $to_name);
                }
            }

            if(!empty($SMTP_OPTIONS['smtp_cc'])) {
                foreach($SMTP_OPTIONS['smtp_cc'] as $bc) {
                    $mail->addCC($bc);
                }
            }
            if(!empty($SMTP_OPTIONS['smtp_bcc'])) {
                foreach($SMTP_OPTIONS['smtp_bcc'] as $bcc) {
                    $mail->addBCC($bcc);
                }
            }

            $mail->Subject = $asunto;
            $fullPath = __DIR__.'/../mail/'.$templatePath;
            $basePath = $this->basetemplate;
            $baseFullPath = __DIR__.'/../mail/'.$basePath;
            if(!file_exists($baseFullPath))
            {
                throw new Exception(EntityLib::__('No se encontró la plantilla $1',array($basePath)));
            }
            if(!file_exists($fullPath))
            {
                throw new Exception(EntityLib::__('No se encontró la plantilla $1',array($templatePath)));
            }
            $htmlEmail = include($baseFullPath);
            ob_start();
            include($fullPath);
            $templateContent = ob_get_contents();
            ob_end_clean();

            $htmlEmail = str_replace('{CONTENT}',$templateContent,$htmlEmail);

            $title = EntityLib::getConfig('app_name');
            $url = EntityLib::getConfig('app_url');
            if(empty($title)) $title = '';
            if(empty($url)) $url = '#';
            $htmlEmail = str_replace('{TITLE}',$title,$htmlEmail);
            $htmlEmail = str_replace('{BASEURL}',$url,$htmlEmail);

            if(!empty($params))
            {
                foreach($params as $key => $value)
                {
                    if($key != 'attachments')
                        $htmlEmail = str_replace($key,$value,$htmlEmail);
                }
            }
            $mail->Body = $htmlEmail;

            if(!empty($params['attachments']))
            {

                foreach($params['attachments'] as $attachment)
                {
                    if(!empty($attachment['relative_path']))
                        $attachmentPath = __DIR__.'/../'.$attachment['relative_path'];
                    else if(!empty($attachment['absolute_path']))
                        $attachmentPath = $attachment['absolute_path'];

                    if(!empty($attachmentPath)) {
                        if (file_exists($attachmentPath)) {
                            $mail->addAttachment($attachmentPath, $attachment['name']);
                        }
                        else
                            throw new Exception('No existe el fichero adjunto '.$attachmentPath);
                    }
                    else
                        throw new Exception('Se ha indicado para subir un fichero sin especificar su path absoluto o relativo');
                }
            }
            if (!$mail->send()) {
                if(strpos($mail->ErrorInfo,'SMTP connect() failed') !== false) {
                    throw new Exception($mail->ErrorInfo);
                }
                else
                    throw new Exception('');
            }

            $success = true;
            $return_msg = 'Mensaje enviado correctamente';
        }catch(Exception $e)
        {
            $return_msg = 'Error de envío: '.$e->getMessage();
        }

        return array(
            'success' => $success,
            'message' => $return_msg
        );
    }
}
?>