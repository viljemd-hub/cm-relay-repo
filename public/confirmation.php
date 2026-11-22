<?php
/**
 * CM Relay
 * File: public/confirmation.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * The SECOND tokenized link in the external-signup flow (see
 * src/signup/signup_lib.php's top doc comment) - sent by the operator only
 * after approving a reviewed, pending signup in admin/review_queue.php. A bare
 * GET (?token=...) never issues the real bearer token by itself (so an
 * email client's link-preview/crawler can't burn a one-time reveal) - it
 * only shows a "Reveal my token" button. Only that button's POST actually
 * calls relay_confirm_signup(), which is refused a second time once used.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/signup/signup_lib.php';

$lang = ($_GET['lang'] ?? '') === 'sl' ? 'sl' : 'en';

$T = [
    'title' => ['en' => 'CM Relay - confirm your connection', 'sl' => 'CM Relay - potrdi povezavo'],
    'invalid_title' => ['en' => 'Invalid or expired link', 'sl' => 'Neveljavna ali potekla povezava'],
    'invalid_body' => ['en' => 'This confirmation link is not valid. Contact the operator who sent it to you.', 'sl' => 'Ta potrditvena povezava ni veljavna. Obrni se na osebo, ki ti jo je poslala.'],
    'already_used_title' => ['en' => 'Already confirmed', 'sl' => 'Že potrjeno'],
    'already_used_body' => ['en' => 'This link has already been used to reveal a token. If it was lost, contact the operator - this link cannot issue a second one.', 'sl' => 'Ta povezava je bila že uporabljena za prevzem žetona. Če si ga izgubil/a, se obrni na operaterja - ta povezava ne more izdati drugega.'],
    'intro' => [
        'en' => 'Your property has been reviewed and approved. Clicking below issues your real access token - it is shown exactly once, so save it immediately (password manager, secure note).',
        'sl' => 'Tvoja namestitev je bila pregledana in odobrena. Klik spodaj izda tvoj pravi dostopni žeton - prikazan je natanko enkrat, zato ga takoj shrani (upravljalnik gesel, varna beležka).',
    ],
    'btn_reveal' => ['en' => 'Reveal my access token', 'sl' => 'Prikaži moj dostopni žeton'],
    'err_confirm' => ['en' => 'Could not confirm.', 'sl' => 'Potrditev ni uspela.'],
    'token_title' => ['en' => 'Your access token (shown once - copy it now)', 'sl' => 'Tvoj dostopni žeton (prikazan enkrat - takoj ga kopiraj)'],
    'token_hint' => [
        'en' => 'Use this as a Bearer token against the CM Relay API: push availability/price via /api/v1/commands.php, pull reservations via /api/v1/events.php + /api/v1/events_ack.php. Full reference in the Relay API contract the operator shared with you.',
        'sl' => 'Uporabi kot Bearer žeton proti CM Relay API-ju: potiskanje razpoložljivosti/cene prek /api/v1/commands.php, prevzem rezervacij prek /api/v1/events.php + /api/v1/events_ack.php. Celotna specifikacija je v Relay API pogodbi, ki ti jo je delil operater.',
    ],
];

function t(string $key, string $lang, array $T): string
{
    return $T[$key][$lang] ?? $T[$key]['en'] ?? $key;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$confirmationToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$confirmation = relay_lookup_signup_confirmation($confirmationToken);

$revealedToken = null;
$error = null;

if ($confirmation !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $result = relay_confirm_signup($confirmationToken);
    if (!empty($result['ok'])) {
        $revealedToken = $result['token'];
        $confirmation['viewed_at'] = 'now'; // local render state only, re-query not needed
    } else {
        $error = $result['error'];
    }
}
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<title><?= h(t('title', $lang, $T)) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #0f1115; color: #e6e8eb; margin: 0; padding: 24px; }
  .wrap { max-width: 640px; margin: 0 auto; }
  h1 { font-size: 1.3rem; }
  .sub { color: #9aa1ab; margin-bottom: 20px; font-size: .92rem; line-height: 1.4; }
  .btn { background: #2f6fed; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: .92rem; cursor: pointer; }
  .btn:hover { background: #2a63d4; }
  .notice { background: #1a2e1a; border: 1px solid #2c5c2c; color: #b7e6b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .warn { background: #2e1a1a; border: 1px solid #5c2c2c; color: #e6b7b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .hint { color: #6f7784; font-size: .78rem; margin-top: 8px; }
  .mono { font-family: ui-monospace, monospace; font-size: .92rem; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; padding: 10px; word-break: break-all; user-select: all; }
</style>
</head>
<body>
<div class="wrap">
  <h1><?= h(t('title', $lang, $T)) ?></h1>

  <?php if ($confirmation === null): ?>
    <div class="warn"><strong><?= h(t('invalid_title', $lang, $T)) ?></strong><br><?= h(t('invalid_body', $lang, $T)) ?></div>
  <?php elseif ($revealedToken !== null): ?>
    <div class="notice">
      <strong><?= h(t('token_title', $lang, $T)) ?></strong>
      <div class="mono" style="margin-top:8px;"><?= h($revealedToken) ?></div>
    </div>
    <p class="hint"><?= h(t('token_hint', $lang, $T)) ?></p>
  <?php elseif ($confirmation['viewed_at'] !== null): ?>
    <div class="warn"><strong><?= h(t('already_used_title', $lang, $T)) ?></strong><br><?= h(t('already_used_body', $lang, $T)) ?></div>
  <?php else: ?>
    <?php if ($error !== null): ?>
      <div class="warn"><?= h(t('err_confirm', $lang, $T)) ?> (<?= h($error) ?>)</div>
    <?php endif; ?>
    <p class="sub"><?= h(t('intro', $lang, $T)) ?></p>
    <form method="post">
      <input type="hidden" name="token" value="<?= h($confirmationToken) ?>">
      <button class="btn" type="submit"><?= h(t('btn_reveal', $lang, $T)) ?></button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
