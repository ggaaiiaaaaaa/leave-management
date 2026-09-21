<?php
// services/smtp_client.php - Pure PHP RFC-compliant SMTP Client with STARTTLS & SSL support

class SmtpClient {
    private $host;
    private $port;
    private $secure;
    private $username;
    private $password;
    private $timeout;
    private $debugLog = [];

    public function __construct(array $config) {
        $this->host = $config['host'] ?? 'smtp.gmail.com';
        $this->port = (int)($config['port'] ?? 587);
        $this->secure = strtolower($config['secure'] ?? 'tls');
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->timeout = (int)($config['timeout'] ?? 15);
    }

    public function getDebugLog(): array {
        return $this->debugLog;
    }

    private function log($msg) {
        $this->debugLog[] = $msg;
    }

    public function send($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody): array {
        $socket = null;
        $connectionPrefix = ($this->secure === 'ssl') ? "ssl://" : "tcp://";
        $target = $connectionPrefix . $this->host . ":" . $this->port;

        $this->log("Connecting to {$target} (timeout {$this->timeout}s)...");

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $err = "Connection failed: [{$errno}] {$errstr}";
            $this->log("ERROR: {$err}");
            return ['success' => false, 'error' => $err, 'log' => $this->debugLog];
        }

        stream_set_timeout($socket, $this->timeout);

        // 1. Read Server Greeting
        $response = $this->readResponse($socket);
        if (!$this->isCode($response, 220)) {
            return $this->abort($socket, "Server greeting failed: {$response}");
        }

        // 2. Initial EHLO
        $this->write($socket, "EHLO localhost");
        $response = $this->readResponse($socket);
        if (!$this->isCode($response, 250)) {
            $this->write($socket, "HELO localhost");
            $response = $this->readResponse($socket);
            if (!$this->isCode($response, 250)) {
                return $this->abort($socket, "EHLO/HELO rejected: {$response}");
            }
        }

        // 3. STARTTLS Negotiation if port 587 / tls
        if ($this->secure === 'tls') {
            $this->write($socket, "STARTTLS");
            $response = $this->readResponse($socket);
            if (!$this->isCode($response, 220)) {
                return $this->abort($socket, "STARTTLS rejected: {$response}");
            }

            $cryptoSuccess = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$cryptoSuccess) {
                return $this->abort($socket, "TLS encryption handshake failed.");
            }
            $this->log("TLS encryption established successfully.");

            // Re-send EHLO after TLS negotiation
            $this->write($socket, "EHLO localhost");
            $response = $this->readResponse($socket);
            if (!$this->isCode($response, 250)) {
                return $this->abort($socket, "Post-TLS EHLO rejected: {$response}");
            }
        }

        // 4. Authenticate if credentials provided
        if (!empty($this->username)) {
            $this->write($socket, "AUTH LOGIN");
            $response = $this->readResponse($socket);
            if (!$this->isCode($response, 334)) {
                return $this->abort($socket, "AUTH LOGIN rejected: {$response}");
            }

            // Send base64 username
            $this->write($socket, base64_encode($this->username));
            $response = $this->readResponse($socket);
            if (!$this->isCode($response, 334)) {
                return $this->abort($socket, "Username rejected: {$response}");
            }

            // Send base64 password
            $this->write($socket, base64_encode($this->password));
            $response = $this->readResponse($socket);
            if (!$this->isCode($response, 235)) {
                return $this->abort($socket, "Authentication failed. Check your email & 16-character App Password: {$response}");
            }
            $this->log("SMTP Authentication successful for user: {$this->username}");
        }

        // 5. MAIL FROM
        $this->write($socket, "MAIL FROM:<{$fromEmail}>");
        $response = $this->readResponse($socket);
        if (!$this->isCode($response, 250)) {
            return $this->abort($socket, "MAIL FROM rejected: {$response}");
        }

        // 6. RCPT TO
        $this->write($socket, "RCPT TO:<{$toEmail}>");
        $response = $this->readResponse($socket);
        if (!$this->isCode($response, 250)) {
            return $this->abort($socket, "RCPT TO rejected for <{$toEmail}>: {$response}");
        }

        // 7. DATA
        $this->write($socket, "DATA");
        $response = $this->readResponse($socket);
        if (!$this->isCode($response, 354)) {
            return $this->abort($socket, "DATA command rejected: {$response}");
        }

        // 8. Build message with RFC-compliant headers
        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $encodedFromName = !empty($fromName) ? "=?UTF-8?B?" . base64_encode($fromName) . "?=" : "";
        $encodedToName = !empty($toName) ? "=?UTF-8?B?" . base64_encode($toName) . "?=" : "";

        $headers = [];
        $headers[] = "Date: " . date('r');
        $headers[] = "From: {$encodedFromName} <{$fromEmail}>";
        $headers[] = "To: {$encodedToName} <{$toEmail}>";
        $headers[] = "Subject: {$encodedSubject}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: base64";
        $headers[] = "X-Mailer: J.T. Yeo CPA Accounting Office";

        $messageContent = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";

        $this->write($socket, $messageContent);
        $response = $this->readResponse($socket);
        if (!$this->isCode($response, 250)) {
            return $this->abort($socket, "Message delivery rejected by server: {$response}");
        }

        // 9. QUIT
        $this->write($socket, "QUIT");
        @fclose($socket);

        $this->log("Email successfully dispatched to <{$toEmail}>!");
        return ['success' => true, 'log' => $this->debugLog];
    }

    private function write($socket, $cmd) {
        $cleanCmd = (strpos($cmd, 'AUTH') === false && strlen($cmd) < 100) ? $cmd : substr($cmd, 0, 40) . '...';
        $this->log("CLIENT: " . $cleanCmd);
        fwrite($socket, $cmd . "\r\n");
    }

    private function readResponse($socket) {
        $response = "";
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // If 4th char is space or newline, it's the final line of multi-line response
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $this->log("SERVER: " . trim($response));
        return trim($response);
    }

    private function isCode($response, $expectedCode) {
        return (substr($response, 0, 3) === strval($expectedCode));
    }

    private function abort($socket, $errMsg) {
        $this->log("ABORT: {$errMsg}");
        if ($socket) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
        return ['success' => false, 'error' => $errMsg, 'log' => $this->debugLog];
    }
}
