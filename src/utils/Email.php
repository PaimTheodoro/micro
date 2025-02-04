<?php

namespace Psf\Utils;

use \Psf\Enumerators\Protocol;

use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\SMTP;
use \PHPMailer\PHPMailer\Exception;

class Email{
	private Protocol $protocol;
	private array $server;
	private array $options;
	private bool $debug = false;
	private $error;

	public function __construct($server = null){
		if(empty($server)){
			if(\PSF::getConfig()->smtp && !empty(\PSF::getConfig()->smtp['hostname'])){
				$this->server = \PSF::getConfig()->smtp;
			}else{
				$this->error = "Não foi possível localizar a configuração do servidor SMTP...";
				
				if($this->debug == true){
					echo $this->error;
				}

				return false;
			}
		}else{
			$this->server = $server;
		}
	}

	public static function define(int|Protocol $protocol, array $server = [], array $options = [], bool $debug = FALSE) : Email{
		$email = new Email;

		$email->protocol 	= $protocol;
		$email->server 		= $server;
		$email->options 	= $options;

		$email->debug = $debug;

		return $email;
	}

	public function send(array $from, array $to, $subject, array $content, array $attachs = []) : string|bool{
		$mail = new PHPMailer(true);

		try {
			if($this->debug){
				$mail->SMTPDebug = SMTP::DEBUG_SERVER;
			}

			$mail->isSMTP();              
			$mail->Host = $this->server['host'];
			$mail->SMTPAuth = true;                   
			$mail->Username = $this->server['user'];
			$mail->Password = $this->server['password'];
			// $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;  
			$mail->SMTPSecure = 'tls';  
			$mail->Port = (int) $this->server['port'];

			if(!empty($this->options)){
				$mail->SMTPOptions = $this->options;
			}

			if($this->server['host'] == "smtp.mailtrap.io"){
				$mail->setFrom("teste@gmail.com", $this->server['name']); // De
			}else{
				$mail->setFrom($from[1], $from[0]); // De
			}			

			$mail->addAddress($to[1], $to[0]); // Para
			$mail->CharSet = 'UTF-8';
		    $mail->isHTML(true);
		    $mail->Subject = $subject;
		    $mail->Body = $content['html'];
		    $mail->AltBody = !empty($content['alt']) ? $content['alt'] : "O seu provedor de e-mail não aceitou o e-mail enviado por nosso sistema, por favor, entre em contato com nossa equipe.";

		    if(!empty($attachs) && is_array($attachs)){
		    	foreach($attachs as $file){
		    		if(!is_array($file)){
		    			$mail->addAttachment($file);
		    		}else{
		    			$mail->addStringAttachment(file_get_contents($file['file']), $file['name'] . (isset($file['extension']) ? ('.' . $file['extension']) : ''));
		    		}
		    	}
		    }

		    if($mail->send()){
		    	return TRUE;
		    }else{
		    	$this->error = 'Error to processs. No more data.';
		    	return $this->error;
		    }
		}catch(phpmailerException $e){
			$this->error = $e->errorMessage();
			
			if($this->debug){
				echo $e->errorMessage();
			}

			return $this->error;
		}catch(Exception $e){
			$this->error = $mail->ErrorInfo;
			
			if($this->debug){
				echo $mail->ErrorInfo;
			}

			return $this->error;
		}
	}

	public function getError(){
		return $this->error;
	}
}