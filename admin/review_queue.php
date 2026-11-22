<?php
/**
 * CM Relay
 * File: admin/review_queue.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Renamed from admin/review_queue.php 2026-11-20 (nothing public depended on
 * the old name - no invite ever sent, no operator secret created yet) and
 * extended into a single, unified operator review surface. Owner
 * correction: NO Channex-affecting action should be automatic, not even
 * for an already-connected CM-software installation - so this page now
 * shows TWO kinds of pending work in one combined, type-tagged list (one
 * glance = "did I review everything pending"), not two separate panels:
 *   - 'external_signup': the existing non-CM invite-first candidate flow
 *     (unchanged - relay_approve_external_signup(), issues a
 *     public/confirmation.php link since there's a real new secret to
 *     disclose to an unauthenticated party).
 *   - 'cm_installation': an already-connected CM-software installation's
 *     properties/request.php submission (relay_approve_channex_request()
 *     in src/signup/signup_lib.php) - no link to send, the installation
 *     polls its own result via properties/request_status.php using the
 *     bearer token it already holds.
 *
 * Auth: session-gated by secrets/relay_operator_secret.json (same secret
 * api/v1/signup/approve.php uses). In-app check only - production
 * deployment should ALSO put admin/ behind vhost-level HTTP Basic Auth as
 * defense-in-depth, matching every other CM admin panel's convention.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/signup/signup_lib.php';

function relay_operator_secret(): ?string
{
    return (json_decode((string)@file_get_contents(relay_secrets_root() . '/relay_operator_secret.json'), true))['secret'] ?? null;
}

$configuredSecret = relay_operator_secret();
$loginError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['operator_secret'])) {
    if ($configuredSecret !== null && hash_equals($configuredSecret, (string)$_POST['operator_secret'])) {
        $_SESSION['relay_operator_authed'] = true;
    } else {
        $loginError = 'Wrong secret.';
    }
}

$authed = !empty($_SESSION['relay_operator_authed']);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$notice = null;
$error = null;
$lastInviteLink = null;
$lastConfirmationLink = null;

$baseUrl = (($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_invite') {
        $candidateName = trim((string)($_POST['candidate_name'] ?? ''));
        $candidateEmail = trim((string)($_POST['candidate_email'] ?? ''));
        if ($candidateName === '') {
            $error = 'Candidate name required.';
        } else {
            $invite = relay_create_signup_invite($candidateName, $candidateEmail);
            $lastInviteLink = $baseUrl . '/public/signup.php?invite=' . urlencode($invite['invite_code']);
            $notice = 'Invite created for ' . $candidateName . '.';
        }
    } elseif ($action === 'approve') {
        $installationId = (string)($_POST['installation_id'] ?? '');
        $result = relay_approve_external_signup($installationId);
        if (!empty($result['ok'])) {
            $lastConfirmationLink = $baseUrl . '/public/confirmation.php?token=' . urlencode($result['confirmation_token']);
            $notice = 'Approved. Send the confirmation link below to the candidate.';
        } else {
            $error = 'Could not approve: ' . (string)($result['error'] ?? '?');
        }
    } elseif ($action === 'approve_channex_request') {
        $requestId = (string)($_POST['request_id'] ?? '');
        $result = relay_approve_channex_request($requestId);
        if (!empty($result['ok'])) {
            $notice = 'Approved - the installation will see it next time it checks request status.';
        } else {
            $error = 'Could not approve: ' . (string)($result['error'] ?? '?');
        }
    } elseif ($action === 'reject_channex_request') {
        $requestId = (string)($_POST['request_id'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = relay_reject_channex_request($requestId, $reason);
        if (!empty($result['ok'])) {
            $notice = 'Rejected.';
        } else {
            $error = 'Could not reject: ' . (string)($result['error'] ?? '?');
        }
    }
}

$outstandingInvites = $authed ? relay_list_outstanding_invites() : [];

$pendingQueue = [];
if ($authed) {
    foreach (relay_list_pending_signups() as $row) {
        $row['type'] = 'external_signup';
        $pendingQueue[] = $row;
    }
    foreach (relay_list_pending_channex_requests() as $row) {
        $row['type'] = 'cm_installation';
        $row['payload'] = json_decode($row['payload_json'], true);
        $pendingQueue[] = $row;
    }
    usort($pendingQueue, static fn($a, $b) => $a['created_at'] <=> $b['created_at']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CM Relay - Review queue</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #0f1115; color: #e6e8eb; margin: 0; padding: 24px; }
  .wrap { max-width: 860px; margin: 0 auto; }
  h1 { font-size: 1.3rem; }
  fieldset { border: 1px solid #2a2f3a; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; }
  legend { padding: 0 8px; font-weight: 600; color: #cdd3db; }
  label { display: block; font-size: .82rem; color: #9aa1ab; margin: 10px 0 4px; }
  input[type=text], input[type=email], input[type=password] { width: 100%; box-sizing: border-box; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; color: #e6e8eb; padding: 8px 10px; font-size: .92rem; }
  .btn { background: #2f6fed; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: .92rem; cursor: pointer; }
  .btn:hover { background: #2a63d4; }
  .btn.danger { background: #6e2a2a; }
  .btn.danger:hover { background: #7f3232; }
  .notice { background: #1a2e1a; border: 1px solid #2c5c2c; color: #b7e6b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .warn { background: #2e1a1a; border: 1px solid #5c2c2c; color: #e6b7b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .mono { font-family: ui-monospace, monospace; font-size: .85rem; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; padding: 8px; word-break: break-all; user-select: all; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #222; font-size: .88rem; }
  pre { white-space: pre-wrap; font-size: .8rem; color: #9aa1ab; }
  .type-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; margin-left: 8px; }
  .type-badge.external { background: #332b1a; color: #e6cf9e; }
  .type-badge.cm { background: #1a2e2e; color: #9ee3d6; }
</style>
</head>
<body>
<div class="wrap">
  <h1>CM Relay - Review queue</h1>

  <?php if (!$authed): ?>
    <?php if ($loginError): ?><div class="warn"><?= h($loginError) ?></div><?php endif; ?>
    <?php if ($configuredSecret === null): ?>
      <div class="warn">No operator secret configured - create secrets/relay_operator_secret.json ({"secret": "..."}) first.</div>
    <?php else: ?>
      <form method="post">
        <label for="operator_secret">Operator secret</label>
        <input type="password" id="operator_secret" name="operator_secret" autofocus>
        <button class="btn" type="submit" style="margin-top:10px;">Sign in</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="warn"><?= h($error) ?></div><?php endif; ?>

    <?php if ($lastInviteLink): ?>
      <div class="notice">
        Invite link (send this to the candidate):
        <div class="mono" style="margin-top:6px;"><?= h($lastInviteLink) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($lastConfirmationLink): ?>
      <div class="notice">
        Confirmation link (send this to the candidate):
        <div class="mono" style="margin-top:6px;"><?= h($lastConfirmationLink) ?></div>
      </div>
    <?php endif; ?>

    <fieldset>
      <legend>Invite a candidate</legend>
      <form method="post">
        <input type="hidden" name="action" value="create_invite">
        <label for="candidate_name">Name</label>
        <input type="text" id="candidate_name" name="candidate_name" required>
        <label for="candidate_email">Email (optional, for your own notes)</label>
        <input type="email" id="candidate_email" name="candidate_email">
        <button class="btn" type="submit" style="margin-top:10px;">Create invite</button>
      </form>

      <?php if ($outstandingInvites): ?>
        <table>
          <thead><tr><th>Name</th><th>Email</th><th>Created</th></tr></thead>
          <tbody>
            <?php foreach ($outstandingInvites as $inv): ?>
              <tr>
                <td><?= h($inv['candidate_name']) ?></td>
                <td><?= h($inv['candidate_email']) ?></td>
                <td><?= h($inv['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </fieldset>

    <fieldset>
      <legend>Pending review (external signups + CM-installation requests)</legend>
      <?php if (empty($pendingQueue)): ?>
        <p>Nothing pending.</p>
      <?php endif; ?>

      <?php foreach ($pendingQueue as $row): ?>
        <div style="border:1px solid #2a2f3a; border-radius:8px; padding:12px; margin-bottom:10px;">

          <?php if ($row['type'] === 'external_signup'):
            $pricing = relay_calculate_connection_pricing((int)$row['unit_count']);
          ?>
            <strong><?= h($row['payload']['property']['title'] ?? '(no title)') ?></strong>
            <span class="type-badge external">external signup</span>
            - <?= h($row['signer_name']) ?> &lt;<?= h($row['signer_email']) ?>&gt;
            <br><span style="color:#9aa1ab; font-size:.8rem;">submitted <?= h($row['created_at']) ?>, IP <?= h($row['ip_address']) ?>, <?= (int)$row['unit_count'] ?> unit(s)</span>
            <br><span class="mono" style="display:inline-block; margin-top:6px;">One-time: &euro;<?= number_format($pricing['one_time_eur'], 2) ?> &middot; Monthly: &euro;<?= number_format($pricing['monthly_eur'], 2) ?></span>
            <pre><?= h(json_encode($row['payload'], JSON_PRETTY_PRINT)) ?></pre>
            <form method="post" onsubmit="return confirm('Approve and provision this on Channex?');">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="installation_id" value="<?= h($row['id']) ?>">
              <button class="btn" type="submit">Approve</button>
            </form>

          <?php else: ?>
            <strong>CM installation - <?= h($row['cm_property_id']) ?></strong>
            <span class="type-badge cm">cm installation - <?= h($row['kind']) ?></span>
            <br><span style="color:#9aa1ab; font-size:.8rem;">edition <?= h($row['edition']) ?> <?= h($row['cm_version']) ?>, locale <?= h($row['locale']) ?>, submitted <?= h($row['created_at']) ?></span>
            <pre><?= h(json_encode($row['payload'], JSON_PRETTY_PRINT)) ?></pre>
            <form method="post" style="display:inline-block; margin-right:8px;" onsubmit="return confirm('Approve and provision this on Channex?');">
              <input type="hidden" name="action" value="approve_channex_request">
              <input type="hidden" name="request_id" value="<?= h($row['id']) ?>">
              <button class="btn" type="submit">Approve</button>
            </form>
            <form method="post" style="display:inline-block;" onsubmit="return confirm('Reject this request?');">
              <input type="hidden" name="action" value="reject_channex_request">
              <input type="hidden" name="request_id" value="<?= h($row['id']) ?>">
              <button class="btn danger" type="submit">Reject</button>
            </form>
          <?php endif; ?>

        </div>
      <?php endforeach; ?>
    </fieldset>
  <?php endif; ?>
</div>
</body>
</html>
