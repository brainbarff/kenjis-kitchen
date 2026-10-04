<?php
/** Send customer mail through the local Nodemailer SMTP service. */
function send_app_email(string $to, string $subject, string $text, string $html = ''): array
{
    $failureMessage = static function (?string $code = null): string {
        return match ($code) {
            'smtp_auth' => 'The email provider rejected the SMTP login. Check EMAIL_USER and EMAIL_PASS in .env. Gmail requires an App Password.',
            'smtp_connection' => 'The app could not connect to your SMTP server. Check SMTP_HOST, SMTP_PORT, and SMTP_SECURE in .env.',
            'smtp_unconfigured' => 'Email is not configured. Add your SMTP settings in .env and restart the Node.js service.',
            'smtp_rejected' => 'The SMTP server could not send the message. Check EMAIL_USER, EMAIL_PASS, and EMAIL_FROM in .env. Gmail senders need an App Password. Restart Node.js after changing them.',
            default => 'The email service could not be reached. Start or restart the Node.js service, then try again.'
        };
    };

    $secret = getenv('REALTIME_SHARED_SECRET') ?: '';
    if ($secret === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => $failureMessage('smtp_unconfigured')];
    }

    $payload = json_encode([
        'to' => $to,
        'subject' => $subject,
        'text' => $text,
        'html' => $html
    ]);
    $url = 'http://127.0.0.1:' . (int)(getenv('REALTIME_PORT') ?: 8787) . '/internal/email/send';

    $response = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Realtime-Secret: ' . $secret
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12
        ]);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        curl_close($curl);
        if (!is_string($response)) {
            error_log('Local email bridge cURL request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'));
            $response = false;
        }
    }

    if (!is_string($response)) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Realtime-Secret: {$secret}\r\n",
            'content' => $payload,
            'timeout' => 12,
            'ignore_errors' => true
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . (int)(getenv('REALTIME_PORT') ?: 8787), $socketErrno, $socketError, 3, STREAM_CLIENT_CONNECT);
            if (is_resource($socket)) {
                stream_set_timeout($socket, 12);
                $request = "POST /internal/email/send HTTP/1.0\r\n" .
                    "Host: 127.0.0.1\r\n" .
                    "Connection: close\r\n" .
                    "Content-Type: application/json\r\n" .
                    "X-Realtime-Secret: {$secret}\r\n" .
                    'Content-Length: ' . strlen($payload) . "\r\n\r\n" .
                    $payload;
                $written = 0;
                while ($written < strlen($request)) {
                    $count = @fwrite($socket, substr($request, $written));
                    if (!$count) break;
                    $written += $count;
                }
                $rawResponse = $written === strlen($request) ? @stream_get_contents($socket) : false;
                fclose($socket);

                if (is_string($rawResponse) && strpos($rawResponse, "\r\n\r\n") !== false) {
                    [, $response] = explode("\r\n\r\n", $rawResponse, 2);
                    if (preg_match('/Transfer-Encoding:\s*chunked/i', $rawResponse)) {
                        $decoded = '';
                        while (preg_match('/^([0-9a-fA-F]+)\r\n/', $response, $match)) {
                            $chunkLength = hexdec($match[1]);
                            $response = substr($response, strlen($match[0]));
                            if ($chunkLength === 0 || strlen($response) < $chunkLength + 2) break;
                            $decoded .= substr($response, 0, $chunkLength);
                            $response = substr($response, $chunkLength + 2);
                        }
                        $response = $decoded;
                    }
                } else {
                    $response = false;
                }
            }

            if (!is_string($response)) {
                error_log('Local email bridge connection failed (' . (int)($socketErrno ?? 0) . ').');
                return ['ok' => false, 'message' => $failureMessage()];
            }
        }
    }

    $result = json_decode($response, true);
    if (is_array($result) && !empty($result['ok'])) {
        return ['ok' => true];
    }
    $failureCode = is_array($result) ? ($result['code'] ?? 'smtp_rejected') : null;
    error_log('Customer email delivery failed (' . ($failureCode ?: 'service_unavailable') . ').');
    return ['ok' => false, 'message' => $failureMessage($failureCode)];
}
