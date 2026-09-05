<?php

declare(strict_types=1);

namespace app\service;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

class NotificationTransport
{
    public const PUSHPLUS_ENDPOINT = 'https://www.pushplus.plus/send';
    public const WXPUSHER_ENDPOINT = 'https://wxpusher.zjiecode.com/api/send/message';
    private ?ClientInterface $client;
    private ?Closure $mailSender;
    private ?PHPMailer $mail = null;
    private string $mailIdentity = '';

    public function __construct(?ClientInterface $client = null, ?Closure $mailSender = null)
    {
        $this->client = $client;
        $this->mailSender = $mailSender;
    }

    public function send(string $channel, array $settings, array $site, string $title, string $body, string $url = ''): array
    {
        $title = NotificationText::clean($title, 200);
        $body = NotificationText::clean($body);
        $url = NotificationSite::safeUrl($url);
        if ($channel === 'email') {
            if (empty($site['email_available']) || !NotificationSite::emailAvailable($site['smtp'] ?? [])) {
                return $this->result(false, '管理员未开启可用的邮件推送');
            }
            try {
                $smtp = $site['smtp'];
                // Only settings used by this connection participate in its identity.
                // Unrelated site options must not cause reconnects or JSON failures.
                $identity = hash('sha256', serialize([
                    (string)$smtp['mail_smtp'], (int)$smtp['mail_port'],
                    (string)$smtp['mail_name'], (string)$smtp['mail_pwd'], (string)$site['name'],
                ]));
                if ($this->mail === null || $this->mailIdentity !== $identity) {
                    $this->closeMail();
                    $this->mail = self::mailer($site['smtp'], (string)$settings['email_address'], $title, $body, (string)$site['name']);
                    $this->mail->SMTPKeepAlive = true;
                    $this->mailIdentity = $identity;
                }
                $mail = $this->mail;
                $mail->clearAllRecipients();
                $mail->clearAttachments();
                $mail->clearCustomHeaders();
                $mail->addAddress((string)$settings['email_address']);
                $mail->Subject = $title;
                $mail->Body = $body;
                $sent = $this->mailSender !== null ? ($this->mailSender)($mail) : $mail->send();
                if (!$sent) {
                    $this->closeMail();
                }
                return $this->result((bool)$sent, $sent ? '邮件已提交' : '邮件发送失败');
            } catch (Throwable $exception) {
                $this->closeMail();
                return $this->result(false, '邮件发送失败，请检查邮箱与管理员 SMTP 配置');
            }
        }
        if ($channel === 'bark') {
            return (new BarkClient($this->client ??= new Client()))->send((string)$settings['bark_token'], $title, $body, (string)$site['name'], $url);
        }
        if ($channel === 'pushplus') {
            $endpoint = self::PUSHPLUS_ENDPOINT;
            $payload = ['token' => (string)$settings['pushplus_token'], 'title' => $title,
                'content' => $body, 'template' => 'txt', 'channel' => 'wechat'];
        } elseif ($channel === 'wxpusher') {
            $endpoint = self::WXPUSHER_ENDPOINT;
            $payload = ['appToken' => (string)$settings['wxpusher_app_token'], 'uids' => [(string)$settings['wxpusher_uid']],
                'content' => $title . "\n\n" . $body, 'summary' => mb_substr($title, 0, 100, 'UTF-8'),
                'contentType' => 1, 'verifyPayType' => 0];
            if ($url !== '') {
                $payload['url'] = $url;
            }
        } else {
            return $this->result(false, '不支持的推送渠道');
        }
        try {
            $response = ($this->client ??= new Client())->request('POST', $endpoint, [
                'json' => $payload, 'timeout' => 8.0, 'connect_timeout' => 3.0,
                'http_errors' => false, 'allow_redirects' => false,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'LoopDeck/' . ApplicationVersion::current()],
            ]);
            $data = json_decode((string)$response->getBody(), true);
            $success = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
                && is_array($data) && (int)($data['code'] ?? 0) === ($channel === 'pushplus' ? 200 : 1000);
            if ($success && $channel === 'wxpusher') {
                $recipients = array_filter(is_array($data['data'] ?? null) ? $data['data'] : [],
                    static fn($row): bool => is_array($row) && ($row['uid'] ?? '') === $settings['wxpusher_uid']);
                $success = count($recipients) === 1 && (int)(reset($recipients)['code'] ?? 0) === 1000;
            }
            return $this->result($success, $success ? '推送请求已受理' : '推送未被受理，请检查凭据、订阅状态或渠道额度');
        } catch (Throwable $exception) {
            return $this->result(false, '推送服务器连接失败，请稍后重试');
        }
    }

    public static function mailer(array $config, string $recipient, string $title, string $body, string $siteName): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string)$config['mail_smtp'];
        $mail->SMTPAuth = true;
        $mail->Username = (string)$config['mail_name'];
        $mail->Password = (string)$config['mail_pwd'];
        $mail->Port = (int)$config['mail_port'];
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 8;
        $mail->getSMTPInstance()->Timelimit = 8;
        $mail->setFrom($mail->Username, $siteName);
        $mail->addAddress($recipient);
        $mail->isHTML(false);
        $mail->Subject = $title;
        $mail->Body = $body;
        return $mail;
    }

    public function closeMail(): void
    {
        if ($this->mail !== null) {
            $this->mail->smtpClose();
        }
        $this->mail = null;
        $this->mailIdentity = '';
    }

    public function __destruct()
    {
        $this->closeMail();
    }

    private function result(bool $success, string $message): array
    {
        return ['success' => $success, 'message' => $message];
    }
}
