#!/usr/bin/php
<?php

// (c) 2025 Granade Technology Solutions. Updated for new Azure Speech API 2025.
// Permissions given according to MIT license.

$subscriptionKey = "#APIKEY#";
$region = "eastus2";
$apiVersion = "2024-11-15";
$endpoint = "https://$region.api.cognitive.microsoft.com/speechtotext/transcriptions:transcribe?api-version=$apiVersion";

$maildata = file_get_contents("php://stdin");
$msg = preg_split("/\r\n|\n|\r/", $maildata);
$att = '';
for($i = 0; $i < count($msg); $i++) {
        if (preg_match('/Content-Disposition: attachment/', $msg[$i])) {
                $i++; // skip to next line
                while (! preg_match('/----/', $msg[$i])) {
                        $att .= $msg[$i] . "\n";
                        $i++;
                }
        }
}

if ($att) {
        // Decode the audio data
        $audioData = base64_decode($att);
        
        // Create multipart form data manually
        $boundary = '----FormBoundary' . uniqid();
        $multipartData = '';
        
        // Add definition part
        $definitionJson = '{"locales":["en-US"]}';
        $multipartData .= "--$boundary\r\n";
        $multipartData .= "Content-Disposition: form-data; name=\"definition\"\r\n";
        $multipartData .= "Content-Type: application/json\r\n\r\n";
        $multipartData .= $definitionJson . "\r\n";
        
        // Add audio part
        $multipartData .= "--$boundary\r\n";
        $multipartData .= "Content-Disposition: form-data; name=\"audio\"; filename=\"voicemail.wav\"\r\n";
        $multipartData .= "Content-Type: audio/wav\r\n\r\n";
        $multipartData .= $audioData . "\r\n";
        $multipartData .= "--$boundary--\r\n";
        
        // Use the same stream context approach
        $opts = array('http' =>
                array(
                        'method' => 'POST',
                        'header' => array(
                                "Ocp-Apim-Subscription-Key: $subscriptionKey",
                                "Content-Type: multipart/form-data; boundary=$boundary",
                                'Accept: application/json'
                        ),
                        'content' => $multipartData
                )
        );
        $context = stream_context_create($opts);
        $contents = file_get_contents($endpoint, false, $context);
        $result = json_decode($contents);
        
        // Debug logging 
        file_put_contents('/var/log/asterisk/transcribe-log.txt', $contents);
}

// Extract transcription from API
if (!empty($result)) {
    // Try new API format first
    if (isset($result->combinedPhrases) && count($result->combinedPhrases) > 0) {
        $maildata = str_replace('(TRANSCRIPTION)', $result->combinedPhrases[0]->text, $maildata);
    } elseif (isset($result->phrases) && count($result->phrases) > 0) {
        $maildata = str_replace('(TRANSCRIPTION)', $result->phrases[0]->text, $maildata);
    } else {
        // Log only when no transcription found but we got a response
        file_put_contents('/var/log/asterisk/transcribe-error.log', 
            date('Y-m-d H:i:s') . " - No transcription in response: " . $contents . "\n", FILE_APPEND);
        $maildata = str_replace('(TRANSCRIPTION)', 'No transcription available.', $maildata);
    }
} else {
    // Log only when API call failed completely
    file_put_contents('/var/log/asterisk/transcribe-error.log', 
        date('Y-m-d H:i:s') . " - API call failed: " . $contents . "\n", FILE_APPEND);
    $maildata = str_replace('(TRANSCRIPTION)', 'No transcription available.', $maildata);
}

// Send email 
$mailproc = popen('/usr/sbin/sendmail -t', 'w');
fwrite($mailproc, $maildata);
pclose($mailproc);

exit;
?>
