<?php
namespace PHPMailer\PHPMailer;

/**
 * Implementación compatible de PHPMailer para envío de correos por SMTP.
 */
class PHPMailer {
    const CHARSET_UTF8 = 'utf-8';
    const ENCRYPTION_STARTTLS = 'tls';
    const ENCRYPTION_SMTPS = 'ssl';

    public $CharSet = self::CHARSET_UTF8;
    public $ContentType = 'text/plain';
    public $Encoding = '8bit';
    public $From = '';
    public $FromName = '';
    public $Sender = '';
    public $Subject = '';
    public $Body = '';
    public $AltBody = '';
    public $Host = 'localhost';
    public $Port = 25;
    public $SMTPSecure = '';
    public $SMTPAuth = false;
    public $Username = '';
    public $Password = '';
    public $Timeout = 15;
    public $ErrorInfo = '';

    protected $smtp = null;
    protected $to = [];
    protected $isSMTP = false;
    protected $isHTML = false;

    public function __construct(bool $exceptions = null) {
        $this->smtp = new SMTP();
    }

    public function isSMTP(): void {
        $this->isSMTP = true;
    }

    public function isHTML(bool $isHtml = true): void {
        $this->isHTML = $isHtml;
        $this->ContentType = $isHtml ? 'text/html' : 'text/plain';
    }

    public function setFrom(string $address, string $name = ''): bool {
        $this->From = trim($address);
        $this->FromName = trim($name);
        return true;
    }

    public function addAddress(string $address, string $name = ''): bool {
        $this->to[] = [trim($address), trim($name)];
        return true;
    }

    public function clearAddresses(): void {
        $this->to = [];
    }

    public function send(): bool {
        if (empty($this->to)) {
            $this->ErrorInfo = 'No recipients specified.';
            return false;
        }

        try {
            $host = $this->Host;
            if ($this->SMTPSecure === 'ssl') {
                $host = 'ssl://' . $host;
            }

            if (!$this->smtp->connect($host, $this->Port, $this->Timeout)) {
                $this->ErrorInfo = 'Could not connect to SMTP host.';
                return false;
            }

            $this->smtp->sendCommand('EHLO', 'EHLO ' . gethostname(), 250);

            if ($this->SMTPSecure === 'tls') {
                if (!$this->smtp->startTLS()) {
                    $this->ErrorInfo = 'Could not start TLS encryption.';
                    $this->smtp->close();
                    return false;
                }
                $this->smtp->sendCommand('EHLO', 'EHLO ' . gethostname(), 250);
            }

            if ($this->SMTPAuth) {
                if (!$this->smtp->authenticate($this->Username, $this->Password)) {
                    $this->ErrorInfo = 'SMTP authentication failed.';
                    $this->smtp->close();
                    return false;
                }
            }

            $from = $this->From ?: 'no-reply@localhost';
            if (!$this->smtp->mail($from)) {
                $this->ErrorInfo = 'MAIL FROM command rejected.';
                $this->smtp->close();
                return false;
            }

            foreach ($this->to as [$addr, $name]) {
                if (!$this->smtp->recipient($addr)) {
                    $this->ErrorInfo = "RCPT TO command rejected for $addr.";
                    $this->smtp->close();
                    return false;
                }
            }

            $headers = [];
            $fromName = $this->FromName ? "=?UTF-8?B?" . base64_encode($this->FromName) . "?=" : "";
            $headers[] = 'From: ' . ($fromName ? "$fromName <{$this->From}>" : $this->From);
            
            $toHeaders = [];
            foreach ($this->to as [$addr, $name]) {
                $toName = $name ? "=?UTF-8?B?" . base64_encode($name) . "?=" : "";
                $toHeaders[] = $toName ? "$toName <$addr>" : $addr;
            }
            $headers[] = 'To: ' . implode(', ', $toHeaders);
            $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($this->Subject) . '?=';
            $headers[] = 'Date: ' . date('r');
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: ' . $this->ContentType . '; charset=' . $this->CharSet;
            $headers[] = 'Content-Transfer-Encoding: base64';

            $message = implode(SMTP::CRLF, $headers) . SMTP::CRLF . SMTP::CRLF . chunk_split(base64_encode($this->Body));

            if (!$this->smtp->data($message)) {
                $this->ErrorInfo = 'DATA command rejected.';
                $this->smtp->close();
                return false;
            }

            $this->smtp->quit();
            return true;
        } catch (\Exception $e) {
            $this->ErrorInfo = $e->getMessage();
            $this->smtp->close();
            return false;
        }
    }
}

