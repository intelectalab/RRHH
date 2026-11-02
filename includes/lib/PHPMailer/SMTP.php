<?php
namespace PHPMailer\PHPMailer;

/**
 * Cliente SMTP simple y autocontenido para envío seguro de correos.
 */
class SMTP {
    const VERSION = '6.9.1';
    const CRLF = "\r\n";
    protected $smtp_conn = null;
    protected $error = [];
    public $Debugoutput = 'echo';
    public $SMTPDebug = 0;
    public $Timeout = 15;

    public function connect(string $host, int $port = 25, int $timeout = 15): bool {
        $errno = 0;
        $errstr = '';
        $this->smtp_conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$this->smtp_conn) {
            $this->error = ['error' => "Failed to connect to server: $errstr ($errno)"];
            return false;
        }
        stream_set_timeout($this->smtp_conn, $timeout);
        $reply = $this->get_lines();
        return substr($reply, 0, 3) === '220';
    }

    public function startTLS(): bool {
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) {
            return false;
        }
        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        return stream_socket_enable_crypto($this->smtp_conn, true, $crypto_method) === true;
    }

    public function authenticate(string $user, string $pass): bool {
        if (!$this->sendCommand('AUTH LOGIN', 'AUTH LOGIN', 334)) {
            return false;
        }
        if (!$this->sendCommand('User', base64_encode($user), 334)) {
            return false;
        }
        if (!$this->sendCommand('Password', base64_encode($pass), 235)) {
            return false;
        }
        return true;
    }

    public function sendCommand(string $cmdName, string $command, $expectCode): bool {
        if (!is_resource($this->smtp_conn)) return false;
        fwrite($this->smtp_conn, $command . self::CRLF);
        $reply = $this->get_lines();
        $code = (int)substr($reply, 0, 3);
        $expected = is_array($expectCode) ? $expectCode : [$expectCode];
        return in_array($code, $expected, true);
    }

    public function mail(string $from): bool {
        return $this->sendCommand('MAIL FROM', "MAIL FROM:<$from>", 250);
    }

    public function recipient(string $to): bool {
        return $this->sendCommand('RCPT TO', "RCPT TO:<$to>", [250, 251]);
    }

    public function data(string $msg): bool {
        if (!$this->sendCommand('DATA', 'DATA', 354)) {
            return false;
        }
        $msg = str_replace(["\r\n", "\r"], "\n", $msg);
        $msg = str_replace("\n", self::CRLF, $msg);
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($this->smtp_conn, $msg . self::CRLF . '.' . self::CRLF);
        $reply = $this->get_lines();
        return substr($reply, 0, 3) === '250';
    }

    public function quit(): void {
        if (is_resource($this->smtp_conn)) {
            $this->sendCommand('QUIT', 'QUIT', 221);
            fclose($this->smtp_conn);
            $this->smtp_conn = null;
        }
    }

    public function close(): void {
        if (is_resource($this->smtp_conn)) {
            fclose($this->smtp_conn);
            $this->smtp_conn = null;
        }
    }

    protected function get_lines(): string {
        $data = '';
        if (!is_resource($this->smtp_conn)) return '';
        while (!feof($this->smtp_conn)) {
            $str = @fgets($this->smtp_conn, 515);
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    }
}

