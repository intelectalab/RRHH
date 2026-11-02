<?php
/**
 * correo.php — Envío opcional de correos electrónicos mediante SMTP y PHPMailer.
 * Si SMTP_HOST no está configurado, el sistema continúa funcionando normalmente sin correo.
 */

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envía una notificación por correo electrónico si SMTP está configurado.
 * Retorna true si se envió con éxito, false si el correo está desactivado o falló.
 */
function enviar_correo(string $destinatario_email, string $destinatario_nombre, string $asunto, string $mensaje): bool {
    // Si no está configurado el servidor SMTP, salir silenciosamente
    if (!defined('SMTP_HOST') || empty(trim((string)SMTP_HOST))) {
        return false;
    }

    $destinatario_email = trim($destinatario_email);
    if (!filter_var($destinatario_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = defined('SMTP_PUERTO') ? (int)SMTP_PUERTO : 587;
        $mail->SMTPSecure = defined('SMTP_SEGURIDAD') ? (string)SMTP_SEGURIDAD : 'tls';
        $mail->CharSet    = 'utf-8';

        if (defined('SMTP_USUARIO') && !empty(SMTP_USUARIO)) {
            $mail->SMTPAuth = true;
            $mail->Username = (string)SMTP_USUARIO;
            $mail->Password = defined('SMTP_CLAVE') ? (string)SMTP_CLAVE : '';
        } else {
            $mail->SMTPAuth = false;
        }

        $de_email  = (defined('SMTP_DE_EMAIL') && !empty(SMTP_DE_EMAIL)) ? SMTP_DE_EMAIL : 'no-reply@aynihr.pe';
        $de_nombre = defined('SMTP_DE_NOMBRE') ? SMTP_DE_NOMBRE : 'AyniHR';
        $mail->setFrom($de_email, $de_nombre);

        $mail->addAddress($destinatario_email, $destinatario_nombre);
        $mail->Subject = '[AyniHR] ' . $asunto;

        // Plantilla HTML sobria, consistente con el sistema
        $mail->isHTML(true);
        $mail->Body = '
        <!doctype html>
        <html lang="es">
        <head><meta charset="utf-8"></head>
        <body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;background-color:#F5F7FA;color:#2C3E50;margin:0;padding:24px;">
          <div style="max-width:560px;margin:0 auto;background:#FFFFFF;border-radius:8px;border:1px solid #E2E8F0;padding:32px;">
            <div style="font-size:18px;font-weight:bold;color:#125F6E;margin-bottom:20px;">Ayni<span style="color:#C98A1B;">HR</span></div>
            <h2 style="font-size:16px;color:#1A202C;margin-top:0;margin-bottom:12px;">' . htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8') . '</h2>
            <p style="font-size:14px;line-height:1.6;color:#4A5568;margin-bottom:24px;">' . nl2br(htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')) . '</p>
            <hr style="border:none;border-top:1px solid #EDF2F7;margin:24px 0;">
            <p style="font-size:12px;color:#718096;margin:0;">Este es un mensaje automático emitido por AyniHR. Por favor no responda a este correo.</p>
          </div>
        </body>
        </html>';
        $mail->AltBody = strip_tags($mensaje);

        return $mail->send();
    } catch (\Exception $e) {
        error_log('Error al enviar correo en AyniHR: ' . $e->getMessage());
        return false;
    }
}

