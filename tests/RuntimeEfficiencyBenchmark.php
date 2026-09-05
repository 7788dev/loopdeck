<?php

declare(strict_types=1);

// Explicit, offline benchmark. Run each case in a fresh PHP process against
// either checkout using LOOPDECK_BENCHMARK_ROOT; never contacts an SMTP server.
$root = realpath(getenv('LOOPDECK_BENCHMARK_ROOT') ?: dirname(__DIR__));
if ($root === false || !is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException('The benchmark checkout needs locked Composer dependencies');
}
require $root . '/vendor/autoload.php';
require_once $root . '/app/common.php';

use app\service\ApplicationVersion;
use app\service\NotificationTransport;

$case = $argv[1] ?? 'transport';
$iterations = max(1, min(10000, (int)($argv[2] ?? 2000)));
$details = [];
$initialMemory = memory_get_usage();
$caseStart = hrtime(true);
$samples = [];

if ($case === 'transport') {
    for ($index = 0; $index < $iterations; $index++) {
        $start = hrtime(true);
        $transport = new NotificationTransport();
        unset($transport);
        $samples[] = (hrtime(true) - $start) / 1000;
    }
    $details['http_client_loaded'] = class_exists(\GuzzleHttp\Client::class, false);
} elseif ($case === 'version') {
    for ($index = 0; $index < $iterations; $index++) {
        $start = hrtime(true);
        ApplicationVersion::current();
        $samples[] = (hrtime(true) - $start) / 1000;
    }
} elseif (in_array($case, ['html_cold', 'html_hot'], true)) {
    $content = '<div><h4>测试公告</h4>' . str_repeat(
        '<p><strong>任务通知</strong>正常内容 <a href="/index/console">控制台</a></p>', 15
    ) . '</div>';
    $details['input_bytes'] = strlen($content);
    $nonce = bin2hex(random_bytes(8));
    if ($case === 'html_hot') {
        safe_html($content);
    }
    for ($index = 0; $index < $iterations; $index++) {
        $input = $case === 'html_cold' ? $content . '<p>' . $nonce . $index . '</p>' : $content;
        $start = hrtime(true);
        $output = safe_html($input);
        $samples[] = (hrtime(true) - $start) / 1000;
        if (!str_contains($output, '任务通知')) {
            throw new RuntimeException('The rich text workload lost its content');
        }
    }
} elseif ($case === 'smtp') {
    // Both revisions run their actual PHPMailer MIME/send code through the
    // same deterministic fixture, with no network delay added to the timings.
    $smtpClass = class_exists(\PHPMailer\PHPMailer\SMTP::class)
        ? \PHPMailer\PHPMailer\SMTP::class : \mail\PHPMailer\SMTP::class;
    class_alias($smtpClass, 'BenchmarkSmtpBase');
    final class BenchmarkSmtp extends BenchmarkSmtpBase
    {
        public int $connections = 0;
        public int $authentications = 0;
        public int $messages = 0;
        private bool $open = false;
        private array $recipients = [];

        public function connect($host, $port = null, $timeout = 30, $options = [])
        {
            $this->connections++;
            return $this->open = true;
        }
        public function connected() { return $this->open; }
        public function close() { $this->open = false; }
        public function hello($host = '')
        {
            $this->server_caps = ['STARTTLS' => true, 'AUTH' => ['LOGIN']];
            return true;
        }
        public function startTLS() { return true; }
        public function authenticate($username, $password, $authtype = null, $OAuth = null)
        {
            $this->authentications++;
            return true;
        }
        public function mail($from) { $this->recipients = []; return true; }
        public function recipient($address, $dsn = '') { $this->recipients[] = $address; return true; }
        public function data($message)
        {
            if (count($this->recipients) !== 1 || !str_contains($message, 'Benchmark body')) {
                throw new RuntimeException('A batch mixed recipients or lost message content');
            }
            $this->messages++;
            return true;
        }
        public function reset() { $this->recipients = []; return true; }
        public function quit($close_on_error = true) { $this->close(); return true; }
    }

    $smtp = new BenchmarkSmtp();
    $transport = new NotificationTransport(null, static function ($mail) use ($smtp): bool {
        $mail->setSMTPInstance($smtp);
        return $mail->send();
    });
    $site = ['name' => 'Benchmark', 'email_available' => true, 'smtp' => [
        'mail_enabled' => 1, 'mail_smtp' => 'smtp.example.com', 'mail_port' => 587,
        'mail_name' => 'sender@example.com', 'mail_pwd' => 'fixture-password',
    ]];
    for ($index = 0; $index < $iterations; $index++) {
        $start = hrtime(true);
        $result = $transport->send('email', ['email_address' => 'recipient' . $index . '@example.com'],
            $site, 'Benchmark ' . $index, 'Benchmark body ' . $index);
        $samples[] = (hrtime(true) - $start) / 1000;
        if (!$result['success']) {
            throw new RuntimeException('The offline SMTP workload failed');
        }
    }
    unset($transport);
    $details += ['messages' => $smtp->messages, 'connections' => $smtp->connections,
        'authentications' => $smtp->authentications, 'network' => 'offline fixture; no simulated latency'];
} else {
    throw new InvalidArgumentException('Cases: transport, version, html_cold, html_hot, smtp');
}

$first = $samples[0];
$total = array_sum($samples);
sort($samples);
echo json_encode([
    'php' => PHP_VERSION, 'case' => $case, 'iterations' => $iterations,
    'first_us' => round($first, 3), 'median_us' => round($samples[(int)floor(count($samples) / 2)], 3),
    'p95_us' => round($samples[(int)floor((count($samples) - 1) * 0.95)], 3),
    'total_ms' => round($total / 1000, 3),
    'wall_ms' => round((hrtime(true) - $caseStart) / 1000000, 3),
    'retained_bytes' => memory_get_usage() - $initialMemory,
    'peak_php_bytes' => memory_get_peak_usage(true),
] + $details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
