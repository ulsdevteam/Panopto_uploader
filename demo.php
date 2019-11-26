<?php
require 'vendor/autoload.php';
include('config.php');

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

/*
// This fails with "HTTP Error 411. The request must be chunked or have a content length.", so is unlikely to be salvagable
try {
        $oauth = new OAuth($client_id, $client_secret);
        $oauth->setToken($client_id, $client_secret);
        $access_token = $oauth->getAccessToken($oauthendpoint, null, null, 'POST');
        if ($access_token) {
                print_r($access_token);
        } else {
                error_log($oauth->getLastResponse());
        }
} catch (OAuthException $ex) {
        error_log($ex->lastResponse);
}
*/
// Manual OAuth Token Request
// See: https://support.panopto.com/s/article/oauth2-for-services 1.2, 2.1.c
$authorization = base64_encode("$client_id:$client_secret");
$headers = array("Authorization: Basic {$authorization}", "Content-Type: application/x-www-form-urlencoded");
$content = 'grant_type=password&username='.urlencode(strtolower($username)).'&password='.urlencode($password).'&scope=api';
$response = request_curl($oauthendpoint, $headers, $content, 'POST', array(200 => 'access_token'));

$token = null;
if ($response) {
        $token = $response['access_token'];
}

$session = null;
if ($token) {
        // Manual API submission for new session
        // See: https://support.panopto.com/s/article/Upload-API 2.2
        $headers = array("Authorization: Bearer {$token}", "Content-Type: application/json");
        $content = json_encode(array(
                'FolderId' => $folder_id,
        ));
        $response = request_curl($uploadendpoint, $headers, $content, 'POST', array(201 => 'ID'));
        if ($response) {
                $session = $response;
        }
}
print_r($session); print "\n";  //exit;

$success = false;
if ($session) {
// TODO Use an S3 uploader toolkit to upload files
// See: https://support.panopto.com/s/article/Upload-API 2.3
// Maybe https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-multipart-upload.html , if the S3Client can be overridden (or configured to use a custom endpoint).
  $upload_id = $session['ID'];
  $UploadTarget =  $session['UploadTarget'];
  $FILENAME = "earth.mp4";

  // step 2 - upload the video file
  multipart_upload_single_file($UploadTarget, $FILENAME);
  //
  // step 3 - create manifest file and uplaod it
  create_manifest_for_video($FILENAME, MANIFEST_FILE_NAME);
  multipart_upload_single_file($UploadTarget, MANIFEST_FILE_NAME);
  $uploadendpoint = 'https://pitt.hosted.panopto.com/Panopto/PublicAPI/Rest/sessionUpload';
  // step 4 - finish the upload
  finish_upload($uploadendpoint, $session, $token);

  // step 5 - monitor the progress of processing
  monitor_progress($uploadendpoint, $upload_id, $token);
  // $success = true;
}

// if ($success) {
//         //Finish the upload
//         // See: https://support.panopto.com/s/article/Upload-API 2.4
//         $session['State'] = 1;
//
//         $headers = array("Authorization: Bearer {$token}");
//         $content = json_encode($session);
//         $response = request_curl($uploadendpoint.'/'.$session['ID'], $headers, $content, 'PUT', array(200 => 'State'));
//         if ($response) {
//                 $session = $response;
//         }
// }

// /**
// * Create an upload session. Return sessionUpload object.
// * @param $folder_id
// */
// function create_session($folder_id) {
//   echo "\n";
//   while(1) {
//     echo('Calling POST PublicAPI/REST/sessionUpload endpoint');
//     $url = $uploadendpoint;
//     $payload = array("FolderId: $folder_id");//['FolderId' => $folder_id];
//     $headers = array("Content-Type: application/json");//['content-type' => 'application/json'];
//     $response = request_curl($url, $headers, '', 'POST', array(200 => 'access_token'));
//
//     if(inspect_response_is_retry_needed($response)) {
//       break;
//     }
//   }
//   $session_upload = $response;
//   echo 'create seesion  ID: '.$session_upload['ID'];
//   echo 'create session UploadTarget:'.$session_upload['UploadTarget'];
//   return $session_upload;
// }

/**
 * Function to upload single file
 * https://docs.aws.amazon.com/code-samples/latest/catalog/php-s3-MultipartUpload.php.html
 * @param $file_path string The URL which is the target of the request
 * @param $manifest_file_name array A list of HTTP headers to send
 */
function multipart_upload_single_file($UploadTarget, $file_path) {
  $element = explode("/", $UploadTarget);
  $prefix = array_pop($element);
  $service_endpoint = implode("/", $element);
  $bucket = array_pop($element);
  $object_key = $prefix."/".$file_path;
  echo "service_endpoint: ".$service_endpoint."\n";
  echo "prefix: ".$prefix."\n";
  echo "bucket: ".$bucket."\n";
  echo "object_key: ".$object_key."\n";

  // Create an S3Client
  $s3Client = new S3Client([
      'endpoint' => $service_endpoint,
      'region'  => 'us-east-1',
      'version' => '2006-03-01',
      'credentials' => [
        'key'    => 'dummy',
        'secret' => 'dummy'
    ]
  ]);

  // Use multipart upload
  $source = fopen($file_path, 'rb');//$file_path;//'earth.mp4';//'/path/to/large/file.zip';
  $uploader = new ObjectUploader(
    $s3Client,
    $bucket,
    $object_key,
    $source
  );

  do {
      try {
          $result = $uploader->upload();
          if ($result["@metadata"]["statusCode"] == '200') {
              print('<p>File successfully uploaded to ' . $result["ObjectURL"] . '.</p>');
              echo "\n\n\n\n".$result['ObjectURL'];
          }
          print($result["@metadata"]["statusCode"]);
      } catch (MultipartUploadException $e) {
          rewind($source);
          $uploader = new MultipartUploader($s3Client, $source, [
              'state' => $e->getState(),
          ]);
      }
  } while (!isset($result));

  // $uploader = new MultipartUploader($s3Client, $source, [
  //     'bucket' => $bucket,
  //     'key' => $object_key,
  //     'params' => [
  //       '@http' => [
  //         'progress' => function ($expectedDl, $dl, $expectedUl, $ul) {
  //           // This gets called (get progress here)
  //         }
  //       ]
  //     ]
  // ]);
  // try {
  //     echo "==========try to upload=========\n";
  //     $result = $uploader->upload();
  //     echo "========== Multipart upload finished ==========\n";
  //     echo "{$result}\n";
  //     echo "Upload complete: {$result['ObjectURL']}\n";
  //     // echo "{$result}\n\n";
  //     echo "{$result['@metadata']['effectiveUri']}\n";
  // } catch (MultipartUploadException $e) {
  //     echo $e->getMessage() . "\n";
  // }
}

/**
 * Create manifest XML file for a single video file, based on template.
 * @param $file_path string
 * @param $manifest_file_name string
 */
function create_manifest_for_video($file_path = null, $manifest_file_name=null) {
    $file_name = basename($file_path);

    echo('\n');
    echo('Writing manifest file: '.$manifest_file_name);

    $template = file_get_contents(MANIFEST_FILE_TEMPLATE);
    $template = str_replace("{Title}", $file_name, $template);
    $template = str_replace("{Description}", 'This is a video session with the uploaded video file '.$file_name, $template);
    $template = str_replace("{Filename}", $file_path, $template);
    $template = str_replace("{Date}", date('Y-m-d H:i:s'), $template);
    echo 'Finished generated .xml file';
    file_put_contents($manifest_file_name, $template);
}

/**
* Finish upload.
* @param $session_upload
*/
function finish_upload($baseUrl, $session_upload, $token) {
  echo "========= Finish_upload ========\n";
  echo json_encode($session_upload)."\n";
  $upload_id = $session_upload['ID'];
  $upload_target = $session_upload['UploadTarget'];
  echo "\n";

  echo "Calling PUT PublicAPI/REST/sessionUpload/$upload_id endpoint\n";
  $url = $baseUrl."/".$upload_id;
  $payload = $session_upload;
  $payload['State'] = 1;
  $headers = array("Authorization: Bearer {$token}", "Content-Type: application/json");
  echo "token: $token\n";
  echo "url: $url\n";
  echo "payload: ".json_encode($payload)."\n";
  echo "headers: $headers\n";

  $resp = request_curl($url, $headers, json_encode($payload), 'PUT');
  echo "Response for finished upload: \n".json_encode($resp)."\n";
  echo "  done\n";
}

/**
* Polling status API until process completes.
* @param $upload_id
*/
function monitor_progress($baseUrl, $upload_id, $token) {
  echo "\n";
  while(1){
      sleep(5);
      echo "Calling PUT PublicAPI/REST/sessionUpload/$upload_id endpoint";
      $url = $baseUrl."/".$upload_id;
      $headers = array("Authorization: Bearer {$token}", "Content-Type: application/json");
      $resp = request_curl($url, $headers);
      echo "=======Response======\n";
      if (inspect_response_is_retry_needed($resp)){
          # If we get Unauthorized and token is refreshed, ignore the response at this time and wait for next time.
          print_r($resp);
          $a = json_encode($resp);
          echo("ewqeq: ".$a['State']);
          continue;
      }
      echo("\n\n  State: ".$resp['State']);

      if ($session_upload['State'] == 4) { # Complete
        break;
      } else if ($session_upload['State'] == 5) {
        echo "There was an error during transfer.";
        break;
      }
  }
}

function inspect_response_is_retry_needed($response) {
  // if ($response['status_code'] >= 200 && $response['status_code'] < 300) { // 100 == 2:
  //   # Success on 2xx response.
  //   return false;
  // }
  return true;
}


/**
 * Function to send a CURL request
 * @param $endpoint string The URL which is the target of the request
 * @param $headers array A list of HTTP headers to send
 * @param $content string The optional body of the request, if applicable
 * @param $type string The HTTP method to use (defaults to GET)
 * @param $expect array An optional keyed array of HTTP response codes mapped to an (optional) expected JSON identifier
 *                      If provided, this is required for the function to return data
 * @return mixed A string or array.
 *               If no $expect parameter is given, returns the response body.
 *               If an $expect key matches with no array value, returns the response body only on a match
 *               If an $expect key matches and has a value, returns the decoded JSON as an array if that matched value is present as a key
 */
function request_curl($endpoint, $headers, $content = '', $type = 'GET', $expect = array()) {
  $cURL = curl_init();
  curl_setopt($cURL, CURLOPT_URL, $endpoint);
  curl_setopt($cURL, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($cURL, CURLOPT_HEADER, 1);
  curl_setopt($cURL, CURLINFO_HEADER_OUT, true);
  switch (strtoupper($type)) {
    case 'POST':
    curl_setopt($cURL, CURLOPT_POST, true);
    break;
    case 'PUT':
    curl_setopt($cURL, CURLOPT_CUSTOMREQUEST, 'PUT');
    break;
  }
  if ($content) {
    curl_setopt($cURL, CURLOPT_POSTFIELDS, $content);
  }
  curl_setopt($cURL, CURLOPT_HTTPHEADER, $headers);
  $response = curl_exec($cURL);
  $header_size = curl_getinfo($cURL, CURLINFO_HEADER_SIZE);
  $response_code = curl_getinfo($cURL, CURLINFO_RESPONSE_CODE);
  $headerSent = curl_getinfo($cURL, CURLINFO_HEADER_OUT );
  error_log('Sent:'."\n".$headerSent."\n".$content."\n");
  curl_close($cURL);
  if ($response === false) {
    error_log('curl for '.$endpoint.' failed: '.curl_error($cURL));
  } else {
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    if (empty($expect) || (isset($expect[$response_code]) && empty($expect[$response_code]))) {
      return $body;
    } else if (isset($expect[$response_code])) {
      $expectation = $expect[$response_code];
      $json = json_decode($body, true);
      if (is_array($json)) {
        if (isset($json[$expectation])) {
          return $json;
        } else {
          error_log('JSON key from '.$endpoint.' ('.$expectation.') missing: '.$response);
        }
      } else {
        error_log('Expected JSON from '.$endpoint.', but could not decode: '.$response);
      }
    } else {
      error_log('Expected one of '.implode(',', array_keys($expect)).'; got a '.$response_code);
    }
  }
  return;
}



?>
