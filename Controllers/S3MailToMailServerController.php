<?php

namespace Botble\S3MailToMailServer\Http\Controllers;

use Botble\S3MailToMailServer\Http\Requests\SNSRequest;
use Botble\Base\Http\Controllers\BaseController;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Botble\Base\Widgets\Card;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use PhpMimeMailParser\Parser;

class S3MailToMailServerController extends BaseController
{
    protected $s3;
    protected $rawEmail;
    protected $forward = 'forward'; //without @ use current domain
    protected $pleskUrl = null;
    protected $allow_domains =[
        'mydomains.com',
    ];

    public function __construct( )
    {   
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => [
                'key'    => "keyaws",
                'secret' => "secretaws",
            ],
        ]); 

    }


    public function handleSNS(SNSRequest $request)
    {
       // Obtener el contenido JSON del mensaje
       $message = json_decode($request->getContent(), true);

       if (!isset($message['Type'])) {
           return response()->json(['message' => 'Invalid SNS message'], 400);
       }

        switch ($message['Type']) {
            case 'SubscriptionConfirmation':
                // Confirmar la suscripción automáticamente
                $this->confirmSubscription($message);
                break;

            case 'Notification':
                // Manejar notificaciones normales
                $this->handleNotification($message);
                break;

            default:
                return response()->json(['message' => 'Unknown message type'], 400);
        }

        return response()->json(['message' => 'OK'], 200);
    }

    protected function confirmSubscription($message)
    {
        if (isset($message['SubscribeURL'])) {
            // Enviar una petición GET al SubscribeURL para confirmar la suscripción
            file_get_contents($message['SubscribeURL']);
        }
    }

    protected function handleNotification($message)
    {
       
        // Realiza las acciones necesarias con el contenido del mensaje SNS
        if (isset($message['Message'])) {

            $sesNotification = $message['Message'];
            $sesData = json_decode($sesNotification, true);

            if (isset($sesData['mail']['messageId'])) {
                $messageId = $sesData['mail']['messageId'];
                $destinations  = $this->getNotificationMails($sesData['mail']['headers'])['all'];
                // Obtener dominio del destinatario
                $domain = $this->extractDomainFromEmail($destinations[0]);

                return $this->processEmailFromS3($domain, $messageId, $sesData['receipt'], $destinations);
            }

        }
    }

    protected function extractDomainFromEmail($email)
    {
        $parts = explode('@', $email);
        return count($parts) > 1 ? $parts[1] : null;
    }

    // Función para procesar el correo desde S3
    public function processEmailFromS3($bucket, $messageId, $receipt = null, $destinations)
    {   
			if(count($destinations) == 0){
                Log::info('////No hay correos permitidos/////');
                return response()->json(['message' => 'No hay correos permitidos'], 200);
            }
		
            $preparationKey = "{$messageId}";

            // Obtener el correo desde S3
            Log::info("Obteniendo correo de S3: " . $preparationKey);
		
            $bucket = strtolower($bucket);
		
            $result = $this->s3->getObject([
                'Bucket' => $bucket,
                'Key'    => $preparationKey,
            ]);

            $rawEmail = $result['Body'];
            $this->rawEmail = $rawEmail;

            //$destinations = $this->getAllowedEmails($this->allow_domains);
            //$destinations = $destinations['all'];

           

            $domain = $this->extractDomainFromEmail($destinations[0]);
            $domain = strtolower($domain);
            // Crear bucket si no existe
            if (!$this->doesBucketExist($domain)) {
                $this->createBucket($domain);
            }

             // Analizar encabezados y decidir si es spam o virus
             $resultado = $this->analizarVeredictos($receipt);
            Log::info('Veredictos');
             // Mover y modificar el correo según el resultado
            if ($resultado === 'SPAM' || $resultado === 'VIRUS') {
                
                $this->moverCorreo($bucket, $destinations, $messageId, $rawEmail,  $resultado);
                //end the process here
                return response()->json(['message' => 'Email processed successfully'], 200);
            } else {
                //save temp email on disk
                Log::info('save temp email');
                $path = storage_path('app/emails/' . $messageId );
                file_put_contents($path, $rawEmail);
                 $from = $this->getFrom($rawEmail);

                if(str_contains($from,",")){
                    $from = explode(", ", $from)[1];
                }
                //send email to local mail server postfix with sendmail
               
                $this->sendMail($from, $path, 'Send');

                Log::info('Mover a INBOX');
                $this->moverCorreo($bucket, $destinations, $messageId, $rawEmail, 'INBOX');

                $domain = $bucket;
                // Verificar si el destinatario tiene reenvíos
                Log::info('check fordwards for mail');
                $forwardingAddresses = $this->checkForwards($domain, $destinations);
                
                if (!empty($forwardingAddresses)) {
                    Log::info('send forwards');
                    $this->sendForward($domain, $forwardingAddresses, $rawEmail);
                }

            }
            

            return response()->json(['message' => 'Email processed successfully'], 200);


    }

    protected function checkForwards($domain, $emails)
    {
        $forwardingAddresses = [];
        $client = new Client();
        $pleskUrl = $this->pleskUrl ?? 'https://'.$domain.':8443';
        $url = $pleskUrl . '/api/v2/cli/mail/call'; // if u want use current domain or use default
        $auth = ['pleskusername', 'pleskpass']; // plesk user for run cli commands, basic auth user:pass 

        foreach ($emails as $email) {

            $params = ["params" => ["--info", $email]];

            try {
                $response = $client->post($url, [
                    'auth' => $auth,
                    'json' => $params,
                    'verify' => false,
                ]);

                $data = json_decode($response->getBody(), true);

                if ($data['code'] === 0 && isset($data['stdout'])) {
                    preg_match('/Group member\(s\):\s+(.*)\n/', $data['stdout'], $matches);
                    $forwards = isset($matches[1]) ? explode(', ', trim($matches[1])) : [];
                    $forwardingAddresses = array_merge($forwardingAddresses, $forwards);
                }

            } catch (\Exception $e) {
                Log::error('Error checking forwards: ' . $e->getMessage());
            }
        }

        return $forwardingAddresses;
    }

    protected function sendMail($from, $file, $type = 'Send'){
        //remove "
		
		try {
        // Dirección del servidor que escucha en el puerto 1500
        $host = '127.0.0.1'; // Cambia si el servidor está en otra IP
        $port = 1500;

        // Verifica si el archivo existe antes de enviarlo
        if (!file_exists($file)) {
            Log::error("El archivo no existe: {$file}");
            return false;
        }

        // Establece conexión con el servidor en el puerto 1500
        $socket = fsockopen($host, $port, $errno, $errstr, 30);

        if (!$socket) {
            Log::error("No se pudo conectar al servidor en {$host}:{$port} - Error {$errno}: {$errstr}");
            return false;
        }

        // Envía el path del archivo al servidor
        fwrite($socket, $file . "\n");

        // Lee la respuesta del servidor
        $response = '';
        while (!feof($socket)) {
            $response .= fgets($socket, 1024);
        }

        // Cierra la conexión
        fclose($socket);

        // Log de la respuesta del servidor
        Log::info("Respuesta del servidor: {$response}");

        return true;
    } catch (Exception $e) {
        // Log de errores
        Log::error("Error al enviar correo: " . $e->getMessage());
        return false;
    }
		
		return;
        Log::info('/////////SEND MAIL : '.$type.' /////////');
        $from = str_replace('"', '', $from);
        $from = trim($from);
        $command = "/usr/sbin/sendmail -t < {$file}";

        $result = exec($command);
        Log::info('command: '. $command. '\n\n sendmail result: ' . $result);
    }

    public function sendForward($domain, $forwardingAddresses, $rawEmail)
    {
        Log::info('/////////sendForward/////////');

        $rawEmail =  $this->addHeader('X-Forwarded-From', $this->getHeader('From'), $rawEmail);

        $From = $this->getHeader('From');
        $rawSubject = $this->getHeader('Subject');
        $subject = "Forward: {$rawSubject} | $From";
        $rawEmail = preg_replace('/^Subject: (.+)$/mi', "Subject: $subject", $rawEmail);
        
        $newFrom = $this->forward;
        if(str_contains($this->forward, '@') == false){
            $newFrom = "{$this->forward}@{$domain}";
        }

        $rawEmail = preg_replace('/^From: (.+)$/mi', "From: {$newFrom}", $rawEmail);
		
		$forwardingAddresses = array_unique($forwardingAddresses);
		
        foreach ($forwardingAddresses as $forwardTo) {
            
            $forwardTo = trim($forwardTo);
            
            $rawEmail = preg_replace('/^To: (.+)$/mi', "To: {$forwardTo}", $rawEmail);
            
            // Envía el correo a cada dirección de reenvío
            $time  = time();
            $path = storage_path("app/emails/forward_{$time}.eml");
            
            file_put_contents($path, $rawEmail);

            $this->sendMail($newFrom, $path, "Forward");
        }
    }

    protected function doesBucketExist($bucket)
    {
        return $this->s3->doesBucketExistV2($bucket);
    }

    protected function createBucket($bucket)
    {
        $this->s3->createBucket([
            'Bucket' => $bucket,
            'ACL' => 'private',
        ]);
        $this->s3->waitUntil('BucketExists', ['Bucket' => $bucket]);
    }

    // Función para analizar los veredictos de SES
    public function analizarVeredictos($headers)
    {
        $veredictos = [
            $headers['spfVerdict']['status'],
            $headers['dkimVerdict']['status'],
            $headers['spamVerdict']['status'],
            $headers['virusVerdict']['status']
        ];

        if (in_array('FAIL', $veredictos)) {
            if ($headers['spamVerdict']['status'] === 'FAIL') {
                return 'SPAM';
            } elseif ($headers['virusVerdict']['status'] === 'FAIL') {
                return 'VIRUS';
            }
        }

        return 'OK';
    }

    // Función para mover el correo en S3
    public function moverCorreo($bucket, $emails, $objecId, $rawEmail, $carpeta)
    {
        Log::info('Mover a:' );
        $from = $this->getFrom($rawEmail);
        preg_match('/<([^>]+)>/', $from, $matches);
        
        if (isset($matches[1])) {
            $from = $matches[1];
        } else {
            $from = 'unknown';
        }

        try{
            foreach ($emails as $email) {
                $email = strtolower($email);
                $key = "{$email}/{$carpeta}/{$from}_{$objecId}".'.eml';
                Log::info(">>>{$bucket}/{$key}");
                $this->s3->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => $rawEmail,
                ]);
            }
        }catch(\Exception $e){
            Log::error('Error moving email: ' . $e->getMessage());
        }
        
        try{
            // Eliminar el mensaje original
            $this->s3->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $objecId,
            ]);
        }catch(\Exception $e){
            Log::error('Error deleting email: ' . $e->getMessage());
        }
       
    }

    protected function getFrom(string $RawMail){
        
        $from = preg_match('/^From: (.+)$/mi', $RawMail, $matches) ? $matches[1] : null;
        return $from;
    }

    protected function getHeader(string $header, $default = null){
        $pattern = '/^' . preg_quote($header, '/') . ': (.+)$/mi';
        $from = preg_match($pattern, $this->rawEmail, $matches) ? $matches[1] : $default;
        return $from;
    }

    protected function addHeader(string $header, string $value, $rawEmail){
        return preg_replace('/^' . preg_quote($header, '/') . ': (.+)$/mi', $header . ': ' . $value, $rawEmail);
    }

    protected function getEmailsFromHeader(string $header): array
    {
        // Obtener el texto del encabezado
        $headerValue = $this->getHeader($header);

        if (!$headerValue) {
            return []; // Retornar vacío si no existe el encabezado
        }

        // Expresión regular para extraer direcciones de correo
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $headerValue, $matches);

        return $matches[0] ?? []; // Retornar las direcciones encontradas
    }

    // Método para procesar con dominios permitidos
    protected function filterEmailsByDomain(array $emails, array $allowDomains): array
    {
        return array_filter($emails, function ($email) use ($allowDomains) {
            $domain = substr(strrchr($email, "@"), 1); // Extraer dominio
            return in_array($domain, $allowDomains);
        });
    }

    // Uso del método para extraer destinatarios válidos
    public function getAllowedEmails(array $allowDomains): array
    {
        $toEmails = $this->getEmailsFromHeader('To');
        $ccEmails = $this->getEmailsFromHeader('CC');
        $bccEmails = $this->getEmailsFromHeader('BCC');

        // Filtrar por dominios permitidos
        $toAllowed = $this->filterEmailsByDomain($toEmails, $allowDomains);
        $ccAllowed = $this->filterEmailsByDomain($ccEmails, $allowDomains);
        $bccAllowed = $this->filterEmailsByDomain($bccEmails, $allowDomains);

        // Consolidar resultados
        return [
            'to' => $toAllowed,
            'cc' => $ccAllowed,
            'bcc' => $bccAllowed,
            'all' => array_merge($toAllowed, $ccAllowed, $bccAllowed),
        ];
    }
	
	 public function getNotificationMails($headers): array
    {
		$toEmails = [];
        $ccEmails = [];
        $bccEmails = [];
        foreach ($headers as $header) {

            if (isset($header['name'])) {
                $headerName = strtolower($header['name']);
    
                if ($headerName === 'to') {
                    preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $header['value'], $matches);
                    $toEmails =  $matches[0] ?? []; 
                }
    
                if ($headerName === 'cc') {
                    preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $header['value'], $matches);
                    $ccEmails =  $matches[0] ?? []; 
                }
    
                if ($headerName === 'bcc') {
                    preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $header['value'], $matches);
                    $bccEmails =  $matches[0] ?? []; 
                }
            }

        }

        // Filtrar por dominios permitidos
        $allowDomains = $this->allow_domains;
        $toAllowed = $this->filterEmailsByDomain($toEmails, $allowDomains);
        $ccAllowed = $this->filterEmailsByDomain($ccEmails, $allowDomains);
        $bccAllowed = $this->filterEmailsByDomain($bccEmails, $allowDomains);

        // Consolidar resultados
        return [
            'to' => $toAllowed,
            'cc' => $ccAllowed,
            'bcc' => $bccAllowed,
            'all' => array_merge($toAllowed, $ccAllowed, $bccAllowed),
        ];
    }
}
