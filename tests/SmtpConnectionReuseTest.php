<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\NotificationTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// Exercise PHPMailer's real send/keepalive code without opening network sockets.
final class ReuseSmtpFixture extends SMTP
{
    public int $connections = 0;
    public int $authentications = 0;
    public array $deliveries = [];
    public bool $failNextData = false;
    private bool $open = false;
    private array $recipients = [];

    public function connect($host, $port = null, $timeout = 30, $options = [])
    {
        $this->connections++;
        return $this->open = true;
    }
    public function connected() { return $this->open; }
    public function close() { $this->open = false; }
    public function hello($host = '') { $this->server_caps = ['STARTTLS' => true, 'AUTH' => ['LOGIN']]; return true; }
    public function startTLS() { return true; }
    public function authenticate($username, $password, $authtype = null, $OAuth = null) { $this->authentications++; return true; }
    public function mail($from) { $this->recipients = []; return true; }
    public function recipient($address, $dsn = '') { $this->recipients[] = $address; return true; }
    public function data($msg_data)
    {
        if ($this->failNextData) {
            $this->failNextData = false;
            $this->setError('Fixture SMTP data rejection');
            return false;
        }
        $this->deliveries[] = ['recipients' => $this->recipients, 'mime' => $msg_data];
        return true;
    }
    public function reset() { $this->recipients = []; return true; }
    public function quit($close_on_error = true) { $this->close(); return true; }
}

$smtp = new ReuseSmtpFixture();
$transport = new NotificationTransport(null, static function (PHPMailer $mail) use ($smtp): bool {
    $mail->setSMTPInstance($smtp);
    return $mail->send();
});
$site = ['name' => 'QA', 'email_available' => true, 'smtp' => ['mail_enabled' => 1,
    'mail_smtp' => 'smtp.example.com', 'mail_port' => 587, 'mail_name' => 'sender@example.com', 'mail_pwd' => 'fixture-password']];
foreach (['one@example.com', 'two@example.com', 'three@example.com'] as $index => $recipient) {
    $result = $transport->send('email', ['email_address' => $recipient], $site, 'Message ' . $index, 'Body ' . $index);
    if (!$result['success'] || $smtp->deliveries[$index]['recipients'] !== [$recipient]
        || !str_contains($smtp->deliveries[$index]['mime'], 'Body ' . $index)) {
        throw new RuntimeException('A reused SMTP connection mixed recipients or content');
    }
}
if ($smtp->connections !== 1 || $smtp->authentications !== 1) {
    throw new RuntimeException('A three-message batch did not reuse one SMTP connection');
}
$site['smtp']['unrelated_legacy_setting'] = "\xFF";
$result = $transport->send('email', ['email_address' => 'four@example.com'], $site, 'Fourth', 'Fourth body');
if (!$result['success'] || $smtp->connections !== 1) {
    throw new RuntimeException('An unrelated site setting reconnected or blocked SMTP');
}
$site['smtp']['mail_pwd'] = 'rotated-fixture-password';
$result = $transport->send('email', ['email_address' => 'five@example.com'], $site, 'Fifth', 'Fifth body');
if (!$result['success'] || $smtp->connections !== 2) {
    throw new RuntimeException('SMTP credential rotation retained the old authenticated session');
}
$site['name'] = 'Second site';
$transport->send('email', ['email_address' => 'other@example.com'], $site, 'Other', 'Other site body');
if ($smtp->connections !== 3 || $smtp->deliveries[5]['recipients'] !== ['other@example.com']) {
    throw new RuntimeException('Changing site identity reused another site\'s SMTP session');
}
$before = count($smtp->deliveries);
$invalid = $transport->send('email', ['email_address' => "valid@example.com\r\nBcc: other@example.com"],
    $site, 'Invalid', 'Invalid');
if ($invalid['success'] || count($smtp->deliveries) !== $before || $smtp->connected()) {
    throw new RuntimeException('An invalid recipient sent mail or retained the failed connection');
}
$result = $transport->send('email', ['email_address' => 'recover@example.com'], $site, 'Recover', 'Recovery body');
if (!$result['success'] || $smtp->connections !== 4
    || $smtp->deliveries[$before]['recipients'] !== ['recover@example.com']) {
    throw new RuntimeException('A failed recipient contaminated the next delivery');
}
$before = count($smtp->deliveries);
$smtp->failNextData = true;
$result = $transport->send('email', ['email_address' => 'retry@example.com'], $site, 'Retry', 'Retry body');
if ($result['success'] || count($smtp->deliveries) !== $before || $smtp->connected()) {
    throw new RuntimeException('An SMTP rejection was accepted or left its connection open');
}
$result = $transport->send('email', ['email_address' => 'retry@example.com'], $site, 'Retry', 'Retry body');
if (!$result['success'] || $smtp->connections !== 5 || count($smtp->deliveries) !== $before + 1) {
    throw new RuntimeException('A rejected SMTP delivery did not reconnect for the next attempt');
}
$smtp->close();
$result = $transport->send('email', ['email_address' => 'after-idle@example.com'], $site, 'Idle', 'Idle body');
if (!$result['success'] || $smtp->connections !== 6) {
    throw new RuntimeException('An SMTP server disconnect could not be recovered');
}
$transport->closeMail();
if ($smtp->connected()) {
    throw new RuntimeException('The completed batch retained its SMTP connection');
}

echo "SMTP reuse tests passed: isolated recipients, credential rotation, rejection and disconnect recovery\n";
