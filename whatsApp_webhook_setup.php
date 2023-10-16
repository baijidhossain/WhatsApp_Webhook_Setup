<?php
function WhatsApp($verifyToken = "")
{

  if (empty($verifyToken)  ||  $verifyToken !==  $this->key) {
    http_response_code(400);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get the JSON data from the request body
    $json_data = file_get_contents('php://input');

    $responseData = json_decode($json_data, true);

    $webhook_message = $responseData['entry'][0]['changes'][0]['value']['messages'][0];

    if (empty($webhook_message['type']) || !in_array($webhook_message['type'], ["text", "image", "document"])) {
      return false;
    }

    $messageForm = "+" . $webhook_message['from'];
    $messageTo = "+" . $responseData['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'];
    $message = "empty message";
    $responseMessageId = $responseData['entry'][0]['id'];
    $service_type = $this->db->query("SELECT id FROM service_type WHERE LOWER(name) = ? ", [strtolower('whatsApp')])->fetchArray();
    $webhook_msg_media_id = "";
    $message_type = 'sms';
    $filename = "";

    $webhook_message_type = $webhook_message['type'];

    if ($webhook_message_type == "text") {
      $message  = $webhook_message['text']['body'];
      $webhook_msg_media_id = $responseData['entry'][0]['changes'][0]['value']['messages'][1]['id'];
    }

    if ($webhook_message_type == "image") {
      $image_file_info = $webhook_message['image'];
      $message  = $image_file_info['caption'] ?? "Image file";
      $message_type = 'mms';
      $webhook_msg_media_id = $image_file_info['id'] ?? "";
    }

    if ($webhook_message_type == "document") {
      $document_info =  $webhook_message['document'];
      $message_type = 'mms';
      $webhook_msg_media_id =  $document_info['id'] ?? "";
      $filename =  $document_info['filename'] ?? "";
      $message = "Document file";
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

    if (in_array($webhook_message_type, ["image", "document"])) {
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

function addWhatsAppAttachment($webhook_msg_media_id, $msg_id, $filename = "")
{

  $gateway =  $this->db->Query("SELECT * FROM gateway WHERE status = '1' AND LOWER(name) = 'whatsapp' ")->fetchArray();

  $header_fields = json_decode($gateway['header_fields'], true);

  $bearer_token = $header_fields['Authorization'];

  // Split the URL by '/'
  $urlParts = explode('/', $gateway['api_url']);

  // Reconstruct the main URL
  $mainUrl = $urlParts[0] . '//' . $urlParts[2] . '/' . $urlParts[3] . '/' . $webhook_msg_media_id;

  $media_url = $this->getMediaFileURL($mainUrl, $bearer_token);

  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_URL => $media_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'curl/7.64.1',
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

function getMediaFileURL($mainUrl, $bearer_token)
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
