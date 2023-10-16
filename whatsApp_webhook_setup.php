<?php

class Webhooks extends Controller
{
  private $key;

  public function __construct()
  {
    $this->db = new Database;
    $this->key = 'FdFVb6jN28';
  }

  public function Index()
  {
    header('HTTP/1.1 401 Unauthorized');
  }

  public function Bandwidth($key = '')
  {
    if (empty($key) || $this->key != $key) {
      header('HTTP/1.1 401 Unauthorized');
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

      try {

        $json_data = file_get_contents('php://input');
        // save json data in file
        //file_put_contents('bandwidth_' . TIMESTAMP . '.json', $json_data);
        $responseData = json_decode($json_data, true);

        foreach ($responseData as $data) {

          $responseMessageId = $data['message']['id'];

          $media = (isset($data['message']['media']) && !empty($data['message']['media'])) ? $data['message']['media'] : [];

          // Check if the type is message-received
          if ($data['type'] == 'message-received') {

            $messageForm = $data['message']['from'];

            $messageTo = $data['message']['to'];

            // Get from number id 
            $fromNumberId = $this->getNumberId($messageForm);

            // To number ids listed and sorted
            $toNumberIds = [];
            $toFromNumberIds = [];

            foreach ($messageTo as $number) {
              $toNumberIds[] = $this->getNumberId($number);
            }
            $toFromNumberIds[] = $fromNumberId;
            $alltoFromNumberIds = array_merge($toFromNumberIds, $toNumberIds);

            sort($alltoFromNumberIds);

            $thread_type = count($alltoFromNumberIds) > 2 ? 'group' : 'single';

            // Query the database to retrieve thread information based on participants' phone IDs
            $threadInfo =  $this->db->query("SELECT id FROM thread WHERE participants = '" . implode(",", $alltoFromNumberIds) . "'")->fetchArray();

            if (!empty($threadInfo)) {

              // Check if thread information exists
              $getParticipantId =  $this->db->query("SELECT id FROM participant WHERE phone_id = ? AND thread_id = ?", [$fromNumberId, $threadInfo['id']])->fetchArray();
              $sender_participant_id = $getParticipantId['id'];
            } else {
              $threadInfo = [];
              // If thread doesn't exist, create a new thread and sender participant
              $threadInfo['id'] = $this->db->query(
                'INSERT INTO thread("participants","type","created","service_type_id","updated") VALUES(?,?,?,?,?)',
                [
                  implode(",", $alltoFromNumberIds),
                  $thread_type,
                  TIMESTAMP,
                  ServiceType::SMS->value,
                  TIMESTAMP
                ]
              )->lastInsertID();

              $sender_participant_id =  $this->db->query('INSERT INTO participant("thread_id","phone_id") VALUES(?,?)', [$threadInfo['id'], $fromNumberId])->lastInsertID();

              // Iterate through toNumberIds IDs and add them to the participant
              foreach ($toNumberIds as $id) {
                $this->db->query('INSERT INTO participant("thread_id","phone_id") VALUES(?,?)', [$threadInfo['id'], $id]);
              }
            }


            $type = 'sms';
            $attachments = [];

            if (!empty($media)) {

              foreach ($media as $mediaItem) {

                // Parse the URL and extract the path component
                $path = parse_url($mediaItem, PHP_URL_PATH);

                // Extract the filename from the path
                $filename = basename($path);

                // Use pathinfo to get the file extension
                $fileInfo = pathinfo($filename);

                // Access the file extension from the resulting array
                $fileExtension = $fileInfo['extension'];

                if (in_array($fileExtension, ["xml", "smil"])) {
                  continue;
                }

                $type = 'mms';
                $modifiedUrl = str_replace('u-ezcynf6rrielex2s7zo4zny', BANDWIDTH_ACCOUNT_ID, $mediaItem);
                [$mediaContent, $contentType] =  $this->getAttachment($modifiedUrl);

                $attachments[] = [
                  'filename' => $filename,
                  'mediaContent' => $mediaContent,
                  'contentType' => $contentType
                ];
              }
            }

            //insert message to the message table
            $msg_id = $this->db->query('INSERT INTO message("thread_id","sender","type","content","status","created","updated") VALUES(?,?,?,?,?,?,?)', [$threadInfo['id'], $sender_participant_id, $type, $data['message']['text'],  'Delivered', TIMESTAMP, TIMESTAMP])->lastInsertId();

            $gateway = $this->db->query("SELECT id FROM gateway WHERE name= ? AND service_type_id = ? AND status = '1'", [__FUNCTION__, ServiceType::SMS->value])->fetchArray();

            // //insert data to the gateway log table
            $this->db->query('INSERT INTO gateway_log("message_id","gateway_id","dlr","gateway_log_id","created","type") VALUES(?,?,?,?,?,?)', [$msg_id, $gateway['id'], 1, $responseMessageId, TIMESTAMP, 'inbound']);

            foreach ($attachments as $attachment) {

              $encodedMediaContent = bin2hex($attachment['mediaContent']);

              $query = "INSERT INTO attachment (message_id, type, file_name, content) VALUES (?, ?, ?, E'\\\\x$encodedMediaContent')";
              $attachment_id = $this->db->query($query, [$msg_id, $attachment['contentType'], $attachment['filename']])->lastInsertID();
            }

            $number = $this->db->query("SELECT is_internal FROM phone_number WHERE number = ? ", [$data['to']])->fetchArray();

            if (!empty($number) && $number['is_internal'] == 0) {

              $carrier = $this->db->query("SELECT id FROM carrier WHERE name = ?", [__FUNCTION__])->fetchArray();

              $carrier_id = $carrier['id'] ?? 0;

              $this->db->query("UPDATE phone_number SET is_internal = ?, carrier_id = ?, updated = ? WHERE number = ?", [1, $carrier_id, TIMESTAMP, $data['to']]);
            }
          }


          // Check if the type is message-delivered
          if ($data['type'] == 'message-delivered') {

            $this->db->query("UPDATE gateway_log SET dlr= ? WHERE gateway_log_id = ?", [1, $responseMessageId]);

            //find message id from gateway log table
            $msg = $this->db->query("SELECT glog.message_id, m.thread_id FROM gateway_log AS glog JOIN message AS m ON glog.message_id = m.id WHERE glog.gateway_log_id = ?", $responseMessageId)->fetchArray();

            //update message status when message is delivered
            if (!empty($msg)) {
              $this->db->query("UPDATE message SET status='Delivered', updated = ? WHERE id = ?", [TIMESTAMP, $msg['message_id']]);
            }

            // create an event to deliver data to the client in db
            $eventData = [
              'message_id' => $msg['message_id'],
              'thread_id' => $msg['thread_id'],
            ];

            // find users who are participating in the thread according to service of phone number
            $subscribers = $this->db->query("SELECT s.uid FROM participant AS p JOIN phone_service AS ps ON p.phone_id = ps.phone_id JOIN service AS s ON ps.service_id = s.id WHERE p.thread_id = ?", $msg['thread_id'])->fetchAll();

            $tokenTimeout = date("Y-m-d H:i:s", strtotime(TOKEN_EXPIRATION ?? ''));

            $subscriberTokenIDs = $this->db->query("SELECT id FROM token WHERE uid IN (" . implode(',', array_column($subscribers, 'uid')) . ") AND type IN ('login', 'admin_login') AND expire = 0 AND (created > ? OR last_activity > ?)", [$tokenTimeout, $tokenTimeout])->fetchAll();

            $this->sendEvent('message-delivered', $eventData, $subscriberTokenIDs);
          }

          // Check if the type is message-failed
          if ($data['type'] == 'message-failed') {

            //find message id from gateway log table
            $msg = $this->db->query("SELECT glog.message_id, m.thread_id, glog.gateway_id FROM gateway_log AS glog JOIN message AS m ON glog.message_id = m.id WHERE glog.gateway_log_id = ?", $responseMessageId)->fetchArray();

            //update message status when message is failed
            if (!empty($msg)) {
              $this->db->query("UPDATE message SET status='Failed', updated = ? WHERE id = ?", [TIMESTAMP, $msg['message_id']]);

              // add log to the gateway error log table
              $this->db->query("INSERT INTO gateway_error_log (gateway_id, message_id, error, created) VALUES (?, ?, ?, ?)", [$msg['gateway_id'], $msg['message_id'], json_encode($data), TIMESTAMP]);
            }

            // create an event to deliver data to the client in db
            $eventData = [
              'message_id' => $msg['message_id'],
              'thread_id' => $msg['thread_id'],
            ];

            // find users who are participating in the thread according to service of phone number
            $subscribers = $this->db->query("SELECT s.uid FROM participant AS p JOIN phone_service AS ps ON p.phone_id = ps.phone_id JOIN service AS s ON ps.service_id = s.id WHERE p.thread_id = ?", $msg['thread_id'])->fetchAll();

            $tokenTimeout = date("Y-m-d H:i:s", strtotime(TOKEN_EXPIRATION ?? ''));

            // admin login timeout is 5 minutes
            $subscriberTokenIDs = $this->db->query("SELECT id FROM token WHERE uid IN (" . implode(',', array_column($subscribers, 'uid')) . ") AND type IN ('login', 'admin_login') AND expire = 0 AND (created > ? OR last_activity > ?)", [$tokenTimeout, $tokenTimeout])->fetchAll();

            $this->sendEvent('message-failed', $eventData, $subscriberTokenIDs);
          }
        }
      } catch (Exception $e) {
        file_put_contents('error.log', $e->getMessage(), FILE_APPEND);
        print_r($e->getMessage());
      }
    } else {

      echo "webhook supports only post request";
    }

    echo "working";
  }

  public function WhatsApp($verifyToken = "")
  {


    if (empty($verifyToken)  ||  $verifyToken !==  $this->key) {
      http_response_code(400);
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

      try {

        // Get the JSON data from the request body
        $json_data = file_get_contents('php://input');

        if (empty($json_data)) {
          http_response_code(400);
          exit;
        }

        $responseData = json_decode($json_data, true);

        $webhook_message = $responseData['entry'][0]['changes'][0]['value']['messages'][0];

        if (empty($webhook_message['type']) || !in_array($webhook_message['type'], ["text", "image", "document", "audio", "video"])) {
          http_response_code(400);
          exit;
        }

        $messageForm = "+" . $webhook_message['from'];
        $messageTo = "+" . $responseData['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'];
        $message = " ";
        $responseMessageId = $responseData['entry'][0]['id'];
        $service_type = $this->db->query("SELECT id FROM service_type WHERE LOWER(name) = ? ", [strtolower('whatsApp')])->fetchArray();
        $webhook_msg_media_id = "";
        $message_type = 'sms';
        $filename = " ";

        $webhook_message_type = $webhook_message['type'];

        // Text and sticker
        if ($webhook_message_type == "text") {
          $message  = $webhook_message['text']['body'];
          $webhook_msg_media_id = $responseData['entry'][0]['changes'][0]['value']['messages'][1]['id'];
        }

        // Media attachment
        if (in_array($webhook_message_type, ["image", "audio", "video", "document"])) {
          $attachment = $webhook_message[$webhook_message_type];
          $message  = $attachment['caption'] ?? " ";
          $message_type = 'mms';
          $webhook_msg_media_id = $attachment['id'];
        }

        $fromNumberId = $this->getNumberId($messageForm);

        $toNumberId = $this->getNumberId($messageTo);

        $alltoFromNumberIds = $fromNumberId . "," . $toNumberId;

        $threadInfo =  $this->db->query("SELECT id FROM thread WHERE participants = ? ", [$alltoFromNumberIds])->fetchArray();

        $thread_type = 'single';

        if (!empty($threadInfo)) {
          $getParticipantId =  $this->db->query("SELECT id FROM participant WHERE phone_id = ? AND thread_id = ?", [$fromNumberId, $threadInfo['id']])->fetchArray();
          $sender_participant_id = $getParticipantId['id'];
        } else {
          $threadInfo = [];
          // If thread doesn't exist, create a new thread and sender participant
          $threadInfo['id'] = $this->db->query('INSERT INTO thread("participants","type","created","service_type_id","updated") VALUES(?,?,?,?,?)',  [$alltoFromNumberIds, $thread_type, TIMESTAMP,  $service_type['id'], TIMESTAMP])->lastInsertID();

          $sender_participant_id =  $this->db->query('INSERT INTO participant("thread_id","phone_id") VALUES(?,?)', [$threadInfo['id'], $fromNumberId])->lastInsertID();

          $this->db->query('INSERT INTO participant("thread_id","phone_id") VALUES(?,?)', [$threadInfo['id'], $toNumberId]);
        }

        //insert message to the message table
        $msg_id = $this->db->query('INSERT INTO message("thread_id","sender","type","content","status","created","updated") VALUES(?,?,?,?,?,?,?)', [$threadInfo['id'], $sender_participant_id, $message_type,  $message,  'Delivered', TIMESTAMP, TIMESTAMP])->lastInsertId();

        $gateway = $this->db->query("SELECT id FROM gateway WHERE name= ? AND service_type_id = ? AND status = '1'", ["WhatsApp", $service_type['id']])->fetchArray();

        if (in_array($webhook_message_type, ["image", "audio", "video", "document"])) {
          $this->addWhatsAppAttachment($webhook_msg_media_id, $msg_id, $filename);
        }

        // //insert data to the gateway log table
        $this->db->query('INSERT INTO gateway_log("message_id","gateway_id","dlr","gateway_log_id","created","type") VALUES(?,?,?,?,?,?)', [$msg_id, $gateway['id'], 1, $responseMessageId, TIMESTAMP, 'inbound']);

        $number = $this->db->query("SELECT is_internal FROM phone_number WHERE number = ? ", [$messageTo])->fetchArray();

        if (!empty($number) && $number['is_internal'] == 0) {

          $carrier_id = 0;

          $this->db->query("UPDATE phone_number SET is_internal = ?, carrier_id = ?, updated = ? WHERE number = ?", [1, $carrier_id, TIMESTAMP, $messageTo]);
        } else {

          $this->db->query("INSERT INTO phone_number (country_id, number, created, updated, carrier_id, is_internal) VALUES (?, ?, ?, ?, ?, ?)", [0, $messageTo, TIMESTAMP, TIMESTAMP, 0, 1])->lastInsertID();
        }

        echo json_encode(["error" => 0, "msg" => "Success!"]);

        exit;
      } catch (Exception $e) {
        http_response_code(431);
        exit;
      }
    }

    // Endpoint url for verify 
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode'])) {
      // Get the query parameters from the incoming request
      $mode = $_GET['hub_mode'];
      $challenge = $_GET['hub_challenge'];
      $verifyToken = $_GET['hub_verify_token'];

      // Replace 'your-verify-token' with your actual verify token
      $expectedVerifyToken =  $this->key;

      if ($mode === 'subscribe' && $verifyToken === $expectedVerifyToken) {
        // Verification successful, return the challenge as a response
        http_response_code(200); // OK
        echo $challenge;
        exit;
      } else {
        // Verification failed
        http_response_code(403); // Forbidden
        echo 'Verification failed.';
      }
    }

    exit;
  }

  private function getAttachment($url)
  {

    $gatewayInfo =  $this->db->query("SELECT * FROM gateway WHERE name='Bandwidth'")->fetchArray();
    $authorization = json_decode($gatewayInfo['header_fields'], true);

    $authorizationHeader = $authorization['Authorization'] ?? '';

    $curlOptions = [
      CURLOPT_HTTPHEADER => ["Authorization: $authorizationHeader"],
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_RETURNTRANSFER => true,
    ];

    $curlContext = curl_init($url);

    curl_setopt_array($curlContext, $curlOptions);

    $mediaContent = curl_exec($curlContext);
    $contentType = curl_getinfo($curlContext, CURLINFO_CONTENT_TYPE);

    return [$mediaContent, $contentType];
  }

  private function getCountry($phone_number)
  {
    $countries = $this->db->query("SELECT id, prefix, regex FROM country")->fetchAll();

    foreach ($countries as $country) {
      if (strpos($phone_number, $country['prefix']) === 0 && preg_match($country['regex'], $phone_number)) {
        return $country['id'];
      }
    }

    return false;
  }

  private function getNumberId($number)
  {

    $getNumber = $this->db->query("SELECT id FROM phone_number WHERE number = ?", [$number])->fetchArray();

    if (!empty($getNumber)) {
      return $getNumber['id'];
    }

    $countryID =  $this->getCountry($number) ?? 0;
    $lastId = $this->db->query('INSERT INTO phone_number ("country_id", "number", "created", "updated") VALUES (?, ?, ?, ?)', [$countryID, $number, TIMESTAMP, TIMESTAMP])->lastInsertID();
    return $lastId;
  }

  private function sendEvent($type, $data, $subscribers = [])
  {
    $eventId = $this->db->query("INSERT INTO user_event (type, metadata, created) VALUES (?, ?, ?)", [$type, json_encode($data), TIMESTAMP])->lastInsertID();

    foreach ($subscribers as $subscriber) {
      $this->db->query("INSERT INTO user_event_subscriber (event_id, device_token_id, created) VALUES (?, ?, ?)", [$eventId, $subscriber['id'], TIMESTAMP]);
    }
  }

  private function addWhatsAppAttachment($webhook_msg_media_id, $msg_id, $filename = "")
  {

    $gateway =  $this->db->Query("SELECT * FROM gateway WHERE status = '1' AND LOWER(name) = 'whatsapp' ")->fetchArray();

    $header_fields = json_decode($gateway['header_fields'], true);

    $bearer_token = $header_fields['Authorization'];

    // Split the URL by '/'
    $urlParts = explode('/', $gateway['api_url']);

    // Reconstruct the main URL
    $mainUrl = $urlParts[0] . '//' . $urlParts[2] . '/' . $urlParts[3] . '/' . $webhook_msg_media_id;

    $media_url = $this->getMediaFileURL($mainUrl, $bearer_token);

    $curl_version = curl_version()['version'];

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $media_url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_USERAGENT => 'curl/' . $curl_version,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HEADER => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => array(
        'Authorization: ' . $bearer_token
      ),
    ));

    $response = curl_exec($curl);

    // Separate headers from content
    list($responseHeaders, $content) = explode("\r\n\r\n", $response, 2);

    // Convert response headers to an array
    $responseHeadersArray = explode("\r\n", $responseHeaders);

    // Create an associative array from response headers
    $response_headers = [];

    foreach ($responseHeadersArray as $header) {
      $parts = explode(': ', $header, 2);
      if (count($parts) == 2) {
        $response_headers[$parts[0]] = $parts[1];
      }
    }

    $content_type  = $response_headers['Content-Type'];

    if (empty($filename)) {
      if (preg_match('/filename=([^;]+)/', $response_headers['Content-Disposition'], $matches)) {
        $filename = $matches[1];
      }
    }

    $encodedMediaContent = bin2hex($content);

    $this->db->query("INSERT INTO attachment (message_id, type, file_name, content) VALUES (?, ?, ?, E'\\\\x$encodedMediaContent')", [$msg_id, $content_type, $filename])->lastInsertID();

    curl_close($curl);

    exit;
  }

  private function getMediaFileURL($mainUrl, $bearer_token)
  {

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $mainUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => array(
        'Authorization: ' . $bearer_token
      ),
    ));
    $response = curl_exec($curl);

    $media_info = json_decode($response, true);

    return $media_info['url'];
  }
}
