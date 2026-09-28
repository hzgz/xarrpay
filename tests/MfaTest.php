<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use XArrPay\Support\Database;
use XArrPay\Support\QrCode;
use XArrPay\Support\SecurityTicket;
use XArrPay\Support\Totp;

function mfaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'xarr-mfa-');
if ($path === false) {
    throw new RuntimeException('cannot create test database');
}
putenv('XARR_DB_DSN=sqlite:' . $path);
$db = Database::connection();
$db->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
$now = time();
$secret = 'JBSWY3DPEHPK3PXP';

$db->prepare('INSERT INTO user (id,username,password,merchant_name,app_secret,status,balance,created_at,mfa_enabled,mfa_secret) VALUES (1,:username,:password,:merchant_name,:app_secret,1,0,:created_at,0,"")')
    ->execute([':username' => 'mfa-user', ':password' => md5('123456'), ':merchant_name' => 'MFA User', ':app_secret' => 'mfa-key', ':created_at' => $now]);

mfaAssert(Totp::verify($secret, Totp::code($secret), time()), 'generated TOTP code must verify');
mfaAssert(!Totp::verify($secret, '000000', 1111111111), 'wrong TOTP code must not verify');
$uri = Totp::uri($secret, 'mfa-user', 'MFA 测试站');
$qr = QrCode::svgDataUri($uri);
mfaAssert(str_starts_with($qr, 'data:image/svg+xml;base64,'), 'QR code must be a data SVG');
$svg = base64_decode(substr($qr, strlen('data:image/svg+xml;base64,')), true);
mfaAssert(is_string($svg) && str_contains($svg, '<svg') && str_contains($svg, '<path'), 'QR SVG payload invalid');

$ticket = SecurityTicket::create($db, SecurityTicket::KIND_STEP_UP, 1, 'mfa.bind', 300);
$row = SecurityTicket::requirePending($db, $ticket['ticket'], SecurityTicket::KIND_STEP_UP, 1);
SecurityTicket::mark($db, (int) $row['id'], SecurityTicket::STATUS_VERIFIED);

$bind = $db->prepare('UPDATE user SET mfa_enabled=1,mfa_secret=:secret WHERE id=1 AND mfa_enabled=0');
$bind->execute([':secret' => $secret]);
mfaAssert($bind->rowCount() === 1, 'MFA bind update failed');
mfaAssert((int) $db->query('SELECT mfa_enabled FROM user WHERE id=1')->fetchColumn() === 1, 'MFA enabled flag not saved');
$bind->execute([':secret' => Totp::generateSecret()]);
mfaAssert($bind->rowCount() === 0, 'repeat bind must be rejected by state condition');

$loginTicket = SecurityTicket::create($db, SecurityTicket::KIND_LOGIN_MFA, 1, 'merchant.login', 300);
$loginRow = SecurityTicket::requirePending($db, $loginTicket['ticket'], SecurityTicket::KIND_LOGIN_MFA);
mfaAssert((int) $loginRow['uid'] === 1, 'login MFA ticket uid mismatch');
SecurityTicket::mark($db, (int) $loginRow['id'], SecurityTicket::STATUS_CONSUMED);
$reused = false;
try {
    SecurityTicket::requirePending($db, $loginTicket['ticket'], SecurityTicket::KIND_LOGIN_MFA);
} catch (RuntimeException $exception) {
    $reused = str_contains($exception->getMessage(), '已使用');
}
mfaAssert($reused, 'consumed login ticket must not be reusable');

$expired = SecurityTicket::create($db, SecurityTicket::KIND_LOGIN_MFA, 1, 'merchant.login', -1);
$expiredRejected = false;
try {
    SecurityTicket::requirePending($db, $expired['ticket'], SecurityTicket::KIND_LOGIN_MFA);
} catch (RuntimeException $exception) {
    $expiredRejected = str_contains($exception->getMessage(), '已过期');
}
mfaAssert($expiredRejected, 'expired login ticket must be rejected');

$unbindTicket = SecurityTicket::create($db, SecurityTicket::KIND_STEP_UP, 1, 'mfa.unbind', 300);
$unbindRow = SecurityTicket::requirePending($db, $unbindTicket['ticket'], SecurityTicket::KIND_STEP_UP, 1);
SecurityTicket::mark($db, (int) $unbindRow['id'], SecurityTicket::STATUS_VERIFIED);
$unbind = $db->prepare('UPDATE user SET mfa_enabled=0,mfa_secret="" WHERE id=1 AND mfa_enabled=1');
$unbind->execute();
mfaAssert($unbind->rowCount() === 1, 'MFA unbind update failed');
mfaAssert((string) $db->query('SELECT mfa_secret FROM user WHERE id=1')->fetchColumn() === '', 'MFA secret was not cleared');

echo "MfaTest: OK\n";
@unlink($path);
