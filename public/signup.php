<?php
/**
 * CM Relay
 * File: public/signup.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Standalone entry point for NON-CM-software prospects (an independent
 * property with its own PMS/in-house system that only wants Channex
 * connectivity through CM Relay's reseller agreement) - deliberately
 * separate from any CM-customer admin page, own content, own liability
 * consent language. Posts to api/v1/signup/request.php, which only records
 * a pending_review installation - nothing here ever touches Channex
 * directly; an operator reviews and approves via admin/review_queue.php before
 * any real property is created.
 *
 * NOT open/self-serve - requires ?invite=<code> from an operator-issued
 * signup_invites row (admin/review_queue.php "Invite a candidate"). Access to
 * CM Relay is invitation-first by design (owner's explicit correction
 * 2026-11-18 - the same principle Channex itself applied before granting
 * the reseller agreement) - without a valid, unconsumed code this page
 * shows nothing but a notice, never the form.
 *
 * Same bilingual convention as pro-dev-repo/admin/cm_connector.php: one $T
 * dictionary, en default, ?lang=sl switches - but this is Relay's own
 * product surface, so it is its own file, not shared with any CM repo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/signup/signup_lib.php';

$lang = ($_GET['lang'] ?? '') === 'sl' ? 'sl' : 'en';
$inviteCode = trim((string)($_GET['invite'] ?? ''));
$invite = relay_validate_signup_invite($inviteCode);

$T = [
    'title' => ['en' => 'CM Relay - connect your own PMS to Booking.com/Airbnb', 'sl' => 'CM Relay - poveži svoj PMS z Booking.com/Airbnb'],
    'intro' => [
        'en' => 'For properties that already run their own system and only need the OTA connectivity layer (Booking.com/Airbnb via Channex) - no CM software required. This page is by invitation only. Submitting this form does NOT create anything yet: a human reviews it, then follows up with the agreed terms and a confirmation link to receive your access token.',
        'sl' => 'Za namestitve, ki že imajo svoj sistem in potrebujejo samo OTA connectivity plast (Booking.com/Airbnb prek Channexa) - brez CM programske opreme. Ta stran je dostopna samo na povabilo. Oddaja tega obrazca še NIČ ne ustvari: človek ga pregleda, nato se oglasi z dogovorjenimi pogoji in potrditveno povezavo za prevzem dostopnega žetona.',
    ],
    'invalid_invite_title' => ['en' => 'Invitation required', 'sl' => 'Potrebno je povabilo'],
    'invalid_invite_body' => [
        'en' => 'This page is only accessible via a valid invitation link from CM Relay. If you believe you should have one, contact whoever referred you.',
        'sl' => 'Ta stran je dostopna samo prek veljavne povabilne povezave s strani CM Relay. Če meniš, da bi jo moral/a imeti, se obrni na osebo, ki te je napotila.',
    ],
    'sent_title' => ['en' => 'Received - pending review', 'sl' => 'Prejeto - čaka na pregled'],
    'sent_body' => ['en' => 'Thank you. We will contact you once this is reviewed, with a confirmation link to receive your access token.', 'sl' => 'Hvala. Oglasili se bomo, ko bo to pregledano, s potrditveno povezavo za prevzem dostopnega žetona.'],
    'err_generic' => ['en' => 'Could not submit - please check the fields and try again.', 'sl' => 'Oddaja ni uspela - preveri polja in poskusi znova.'],
    'property_legend' => ['en' => 'Property', 'sl' => 'Namestitev'],
    'title_label' => ['en' => 'Property title', 'sl' => 'Naziv namestitve'],
    'currency_label' => ['en' => 'Currency (e.g. EUR)', 'sl' => 'Valuta (npr. EUR)'],
    'country_label' => ['en' => 'Country (ISO 2-letter, e.g. SK)', 'sl' => 'Država (ISO, 2 črki, npr. SK)'],
    'city_label' => ['en' => 'City', 'sl' => 'Kraj'],
    'units_legend' => ['en' => 'Room types (leave extra rows blank)', 'sl' => 'Tipi sob (dodatne vrstice pusti prazne)'],
    'photos_legend' => ['en' => 'Photos (leave extra rows blank)', 'sl' => 'Fotografije (dodatne vrstice pusti prazne)'],
    'photos_hint' => ['en' => 'Add photo URLs already public on your own site. Some channels (e.g. Google Vacation Rentals) require at least 8 photos, with under 5 blocking the listing outright - more is better.', 'sl' => 'Dodaj URL-je fotografij, ki so že javno dostopne na tvoji strani. Nekateri kanali (npr. Google Vacation Rentals) zahtevajo vsaj 8 fotografij, manj kot 5 ponudbo popolnoma blokira - več je bolje.'],
    'photo_url_label' => ['en' => 'Photo URL', 'sl' => 'URL fotografije'],
    'google_legend' => ['en' => 'Google Vacation Rentals (optional)', 'sl' => 'Google Vacation Rentals (opcijsko)'],
    'google_hint' => [
        'en' => 'Only needed if you also want your property connected to Google Vacation Rentals (confirmed via Channex/Google docs: needs phone, website, location, timezone, a cancellation policy, and at least one facility, on top of the 8+ photos above). Leave blank if you only want Booking.com/Airbnb - this is not required for those.',
        'sl' => 'Potrebno samo, če želiš namestitev povezati tudi z Google Vacation Rentals (potrjeno prek Channex/Google dokumentacije: poleg 8+ fotografij zgoraj potrebuje še telefon, spletno stran, lokacijo, časovni pas, pravila odpovedi in vsaj eno ugodnost). Pusti prazno, če želiš samo Booking.com/Airbnb - za to ni potrebno.',
    ],
    'google_want_checkbox' => ['en' => 'I also want Google Vacation Rentals', 'sl' => 'Želim tudi Google Vacation Rentals'],
    'google_phone_label' => ['en' => 'Phone', 'sl' => 'Telefon'],
    'google_website_label' => ['en' => 'Website URL', 'sl' => 'Spletna stran'],
    'google_lat_label' => ['en' => 'Latitude', 'sl' => 'Zemljepisna širina'],
    'google_lng_label' => ['en' => 'Longitude', 'sl' => 'Zemljepisna dolžina'],
    'google_timezone_label' => ['en' => 'Timezone (e.g. Europe/Bratislava)', 'sl' => 'Časovni pas (npr. Europe/Ljubljana)'],
    'google_facilities_label' => ['en' => 'Facilities (comma-separated, e.g. WiFi, Parking)', 'sl' => 'Ugodnosti (ločene z vejico, npr. WiFi, Parkirišče)'],
    'google_cancellation_policy_label' => ['en' => 'Cancellation policy (plain text)', 'sl' => 'Pravila odpovedi (prosto besedilo)'],
    'unit_title_label' => ['en' => 'Title', 'sl' => 'Naziv'],
    'unit_occ_label' => ['en' => 'Max adults', 'sl' => 'Maks. odraslih'],
    'unit_rate_label' => ['en' => 'Starting rate / night', 'sl' => 'Začetna cena / noč'],
    'pricing_legend' => ['en' => 'Pricing (same for everyone, computed automatically)', 'sl' => 'Cena (enaka za vse, izračunana samodejno)'],
    'pricing_hint' => ['en' => 'Same policy every CM Relay participant pays - updates as you fill in room types above.', 'sl' => 'Enaka politika kot za vsakega udeleženca CM Relay - se posodobi, ko zgoraj izpolniš tipe sob.'],
    'pricing_one_time_label' => ['en' => 'One-time connection fee', 'sl' => 'Enkratni priklopni strošek'],
    'pricing_monthly_label' => ['en' => 'Monthly (per unit, degressive)', 'sl' => 'Mesečno (po enoti, degresivno)'],
    'agreement_legend' => ['en' => 'Other terms', 'sl' => 'Ostali pogoji'],
    'agreement_plan_label' => ['en' => 'Plan (optional note)', 'sl' => 'Plan / paket (opcijska opomba)'],
    'agreement_contract_ref_label' => ['en' => 'Contract reference (if any)', 'sl' => 'Referenca pogodbe (če obstaja)'],
    'agreement_notes_label' => ['en' => 'Notes', 'sl' => 'Opombe'],
    'liability_legend' => ['en' => 'Liability', 'sl' => 'Odgovornost'],
    'liability_text' => [
        'en' => 'I understand CM Relay acts only as a connectivity reseller between my own system and Channex/the OTA channels. I am solely responsible for my own listing content, pricing accuracy, guest communication, and compliance with each OTA\'s own policies (including their zero-tolerance spam/abuse rules). CM Relay gives no uptime or booking-volume guarantee (SLA).',
        'sl' => 'Razumem, da CM Relay deluje samo kot reseller connectivityja med mojim sistemom in Channexom/OTA kanali. Sam/a sem izključno odgovoren/na za vsebino svoje ponudbe, pravilnost cen, komunikacijo z gosti in skladnost s pravili posameznega OTA kanala (vključno z njihovimi pravili ničelne tolerance za spam/zlorabo). CM Relay ne daje nobene garancije glede razpoložljivosti ali obsega rezervacij (SLA).',
    ],
    'liability_checkbox' => ['en' => 'I accept the above.', 'sl' => 'Sprejemam zgoraj navedeno.'],
    'signer_name_label' => ['en' => 'Your name', 'sl' => 'Ime in priimek'],
    'signer_email_label' => ['en' => 'Your email', 'sl' => 'E-mail'],
    'submit_btn' => ['en' => 'Submit for review', 'sl' => 'Oddaj v pregled'],
    'honeypot_label' => ['en' => 'Leave this field empty', 'sl' => 'Pusti to polje prazno'],
];

function t(string $key, string $lang, array $T): string
{
    return $T[$key][$lang] ?? $T[$key]['en'] ?? $key;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$submitted = false;

$sticky = ['property' => [], 'units' => [], 'agreement' => [], 'liability' => []];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $invite !== null) {
    $sticky['property'] = [
        'title' => (string)($_POST['property_title'] ?? ''),
        'currency' => (string)($_POST['property_currency'] ?? ''),
        'country' => (string)($_POST['property_country'] ?? ''),
        'city' => (string)($_POST['property_city'] ?? ''),
    ];

    $units = [];
    for ($i = 0; $i < 10; $i++) {
        $unitTitle = trim((string)($_POST['unit_title'][$i] ?? ''));
        $unitRate = trim((string)($_POST['unit_rate'][$i] ?? ''));
        if ($unitTitle === '' || $unitRate === '') {
            continue;
        }
        $units[] = [
            'title' => $unitTitle,
            'rate' => (float)$unitRate,
            'occ_adults' => (int)($_POST['unit_occ_adults'][$i] ?? 2),
        ];
    }
    $sticky['units'] = $units;

    $photos = [];
    for ($i = 0; $i < 10; $i++) {
        $photoUrl = trim((string)($_POST['photo_url'][$i] ?? ''));
        if ($photoUrl === '' || !filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            continue;
        }
        $photos[] = ['url' => $photoUrl, 'position' => count($photos)];
    }
    $sticky['photos'] = $photos;

    // Collect-only for now (2026-11-20 plan) - NOT wired into any Channex
    // call yet. See ChannexAdapter.php's doc comment: the real create-API
    // shape for hotel_policy/cancellation_policy/facility is unverified,
    // so this data is stored for a human operator's manual follow-up on
    // Channex's own dashboard during review, not auto-provisioned.
    $sticky['google'] = [
        'wanted' => !empty($_POST['google_wanted']),
        'phone' => (string)($_POST['google_phone'] ?? ''),
        'website' => (string)($_POST['google_website'] ?? ''),
        'latitude' => (string)($_POST['google_lat'] ?? ''),
        'longitude' => (string)($_POST['google_lng'] ?? ''),
        'timezone' => (string)($_POST['google_timezone'] ?? ''),
        'facilities' => (string)($_POST['google_facilities'] ?? ''),
        'cancellation_policy' => (string)($_POST['google_cancellation_policy'] ?? ''),
    ];

    $sticky['agreement'] = [
        'plan' => (string)($_POST['agreement_plan'] ?? ''),
        'contract_ref' => (string)($_POST['agreement_contract_ref'] ?? ''),
        'notes' => (string)($_POST['agreement_notes'] ?? ''),
    ];

    $signerEmail = trim((string)($_POST['signer_email'] ?? ''));
    $sticky['liability'] = [
        'accepted' => !empty($_POST['liability_accepted']),
        'signer_name' => (string)($_POST['signer_name'] ?? ''),
        'signer_email' => $signerEmail,
    ];
    $sticky['agreement']['relay_account_email'] = $signerEmail;

    $requestBody = [
        'invite_code' => $inviteCode,
        'property' => $sticky['property'],
        'units' => $units,
        'photos' => $photos,
        'google' => $sticky['google'],
        'agreement' => $sticky['agreement'],
        'liability' => $sticky['liability'],
        'form_loaded_at' => (int)($_POST['form_loaded_at'] ?? 0),
        'website' => (string)($_POST['website'] ?? ''),
        'locale' => $lang,
    ];

    $endpoint = (($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . '/api/v1/signup/request.php';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);

    if ($httpCode < 400 && !empty($decoded['ok'])) {
        $submitted = true;
    } else {
        $errors[] = t('err_generic', $lang, $T) . ' (' . (string)($decoded['error'] ?? "http_$httpCode") . ')';
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
  .wrap { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 1.3rem; }
  .sub { color: #9aa1ab; margin-bottom: 20px; font-size: .92rem; line-height: 1.4; }
  fieldset { border: 1px solid #2a2f3a; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; }
  legend { padding: 0 8px; font-weight: 600; color: #cdd3db; }
  label { display: block; font-size: .82rem; color: #9aa1ab; margin: 10px 0 4px; }
  label.chk { display: flex; align-items: flex-start; gap: 8px; color: #cdd3db; font-size: .88rem; margin: 10px 0; }
  label.chk input { margin-top: 3px; }
  input[type=text], input[type=email], input[type=number] { width: 100%; box-sizing: border-box; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; color: #e6e8eb; padding: 8px 10px; font-size: .92rem; }
  textarea { width: 100%; box-sizing: border-box; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; color: #e6e8eb; padding: 8px 10px; font-size: .92rem; min-height: 60px; }
  .btn { background: #2f6fed; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: .92rem; cursor: pointer; }
  .btn:hover { background: #2a63d4; }
  .notice { background: #1a2e1a; border: 1px solid #2c5c2c; color: #b7e6b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .warn { background: #2e1a1a; border: 1px solid #5c2c2c; color: #e6b7b7; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .hint { color: #6f7784; font-size: .78rem; margin-top: 4px; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
  .grid3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 0 10px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 4px 6px; text-align: left; }
  .honeypot { position: absolute; left: -9999px; top: -9999px; }
  .mono { font-family: ui-monospace, monospace; font-size: 1rem; background: #171a21; border: 1px solid #2a2f3a; border-radius: 6px; padding: 8px 10px; }
  .lang-switch a { color: #7aa2f7; text-decoration: none; font-size: .8rem; margin-left: 8px; }
  .lang-switch a.active { color: #e6e8eb; font-weight: 600; text-decoration: underline; }
</style>
</head>
<body>
<div class="wrap">
  <p class="lang-switch" style="text-align:right;">
    <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
    <a href="?lang=sl" class="<?= $lang === 'sl' ? 'active' : '' ?>">SL</a>
  </p>
  <h1><?= h(t('title', $lang, $T)) ?></h1>
  <p class="sub"><?= t('intro', $lang, $T) ?></p>

  <?php if ($invite === null): ?>
    <div class="warn">
      <strong><?= h(t('invalid_invite_title', $lang, $T)) ?></strong><br>
      <?= h(t('invalid_invite_body', $lang, $T)) ?>
    </div>
  <?php elseif ($submitted): ?>
    <div class="notice">
      <strong><?= h(t('sent_title', $lang, $T)) ?></strong><br>
      <?= h(t('sent_body', $lang, $T)) ?>
    </div>
  <?php else: ?>
    <?php foreach ($errors as $error): ?>
      <div class="warn"><?= h($error) ?></div>
    <?php endforeach; ?>

    <form method="post">
      <input type="hidden" name="form_loaded_at" value="">
      <div class="honeypot">
        <label for="website"><?= h(t('honeypot_label', $lang, $T)) ?></label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <fieldset>
        <legend><?= h(t('property_legend', $lang, $T)) ?></legend>
        <label for="property_title"><?= h(t('title_label', $lang, $T)) ?></label>
        <input type="text" id="property_title" name="property_title" value="<?= h($sticky['property']['title'] ?? '') ?>" required>
        <div class="grid2">
          <div>
            <label for="property_currency"><?= h(t('currency_label', $lang, $T)) ?></label>
            <input type="text" id="property_currency" name="property_currency" value="<?= h($sticky['property']['currency'] ?? 'EUR') ?>" maxlength="3" required>
          </div>
          <div>
            <label for="property_country"><?= h(t('country_label', $lang, $T)) ?></label>
            <input type="text" id="property_country" name="property_country" value="<?= h($sticky['property']['country'] ?? '') ?>" maxlength="2" required>
          </div>
        </div>
        <label for="property_city"><?= h(t('city_label', $lang, $T)) ?></label>
        <input type="text" id="property_city" name="property_city" value="<?= h($sticky['property']['city'] ?? '') ?>" required>
      </fieldset>

      <fieldset>
        <legend><?= h(t('units_legend', $lang, $T)) ?></legend>
        <table>
          <thead>
            <tr>
              <th><?= h(t('unit_title_label', $lang, $T)) ?></th>
              <th><?= h(t('unit_occ_label', $lang, $T)) ?></th>
              <th><?= h(t('unit_rate_label', $lang, $T)) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php for ($i = 0; $i < 10; $i++):
              $existingUnit = $sticky['units'][$i] ?? null;
            ?>
              <tr>
                <td><input type="text" name="unit_title[<?= $i ?>]" value="<?= h($existingUnit['title'] ?? '') ?>" style="width:160px;"></td>
                <td><input type="number" min="1" name="unit_occ_adults[<?= $i ?>]" value="<?= h((string)($existingUnit['occ_adults'] ?? 2)) ?>" style="width:70px;"></td>
                <td><input type="number" step="0.01" min="0" name="unit_rate[<?= $i ?>]" value="<?= h((string)($existingUnit['rate'] ?? '')) ?>" style="width:100px;"></td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </fieldset>

      <fieldset>
        <legend><?= h(t('photos_legend', $lang, $T)) ?></legend>
        <p class="hint"><?= h(t('photos_hint', $lang, $T)) ?></p>
        <?php for ($i = 0; $i < 10; $i++):
          $existingPhoto = $sticky['photos'][$i] ?? null;
        ?>
          <input type="url" name="photo_url[<?= $i ?>]" value="<?= h($existingPhoto['url'] ?? '') ?>" placeholder="https://..." style="margin-bottom:6px;">
        <?php endfor; ?>
      </fieldset>

      <fieldset>
        <legend><?= h(t('google_legend', $lang, $T)) ?></legend>
        <p class="hint"><?= h(t('google_hint', $lang, $T)) ?></p>
        <label class="chk">
          <input type="checkbox" name="google_wanted" <?= !empty($sticky['google']['wanted']) ? 'checked' : '' ?>>
          <?= h(t('google_want_checkbox', $lang, $T)) ?>
        </label>
        <div class="grid2">
          <div>
            <label for="google_phone"><?= h(t('google_phone_label', $lang, $T)) ?></label>
            <input type="text" id="google_phone" name="google_phone" value="<?= h($sticky['google']['phone'] ?? '') ?>">
          </div>
          <div>
            <label for="google_website"><?= h(t('google_website_label', $lang, $T)) ?></label>
            <input type="text" id="google_website" name="google_website" value="<?= h($sticky['google']['website'] ?? '') ?>">
          </div>
        </div>
        <div class="grid2">
          <div>
            <label for="google_lat"><?= h(t('google_lat_label', $lang, $T)) ?></label>
            <input type="text" id="google_lat" name="google_lat" value="<?= h($sticky['google']['latitude'] ?? '') ?>">
          </div>
          <div>
            <label for="google_lng"><?= h(t('google_lng_label', $lang, $T)) ?></label>
            <input type="text" id="google_lng" name="google_lng" value="<?= h($sticky['google']['longitude'] ?? '') ?>">
          </div>
        </div>
        <label for="google_timezone"><?= h(t('google_timezone_label', $lang, $T)) ?></label>
        <input type="text" id="google_timezone" name="google_timezone" value="<?= h($sticky['google']['timezone'] ?? '') ?>">
        <label for="google_facilities"><?= h(t('google_facilities_label', $lang, $T)) ?></label>
        <input type="text" id="google_facilities" name="google_facilities" value="<?= h($sticky['google']['facilities'] ?? '') ?>">
        <label for="google_cancellation_policy"><?= h(t('google_cancellation_policy_label', $lang, $T)) ?></label>
        <textarea id="google_cancellation_policy" name="google_cancellation_policy"><?= h($sticky['google']['cancellation_policy'] ?? '') ?></textarea>
      </fieldset>

      <fieldset>
        <legend><?= h(t('pricing_legend', $lang, $T)) ?></legend>
        <p class="hint"><?= h(t('pricing_hint', $lang, $T)) ?></p>
        <div class="grid2">
          <div>
            <label><?= h(t('pricing_one_time_label', $lang, $T)) ?></label>
            <div class="mono" id="pricing_one_time">€0.00</div>
          </div>
          <div>
            <label><?= h(t('pricing_monthly_label', $lang, $T)) ?></label>
            <div class="mono" id="pricing_monthly">€0.00</div>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><?= h(t('agreement_legend', $lang, $T)) ?></legend>
        <label for="agreement_plan"><?= h(t('agreement_plan_label', $lang, $T)) ?></label>
        <input type="text" id="agreement_plan" name="agreement_plan" value="<?= h($sticky['agreement']['plan'] ?? '') ?>">
        <label for="agreement_contract_ref"><?= h(t('agreement_contract_ref_label', $lang, $T)) ?></label>
        <input type="text" id="agreement_contract_ref" name="agreement_contract_ref" value="<?= h($sticky['agreement']['contract_ref'] ?? '') ?>">
        <label for="agreement_notes"><?= h(t('agreement_notes_label', $lang, $T)) ?></label>
        <textarea id="agreement_notes" name="agreement_notes"><?= h($sticky['agreement']['notes'] ?? '') ?></textarea>
      </fieldset>

      <fieldset>
        <legend><?= h(t('liability_legend', $lang, $T)) ?></legend>
        <p class="hint"><?= h(t('liability_text', $lang, $T)) ?></p>
        <label class="chk">
          <input type="checkbox" name="liability_accepted" <?= !empty($sticky['liability']['accepted']) ? 'checked' : '' ?> required>
          <?= h(t('liability_checkbox', $lang, $T)) ?>
        </label>
        <div class="grid2">
          <div>
            <label for="signer_name"><?= h(t('signer_name_label', $lang, $T)) ?></label>
            <input type="text" id="signer_name" name="signer_name" value="<?= h($sticky['liability']['signer_name'] ?? '') ?>" required>
          </div>
          <div>
            <label for="signer_email"><?= h(t('signer_email_label', $lang, $T)) ?></label>
            <input type="email" id="signer_email" name="signer_email" value="<?= h($sticky['liability']['signer_email'] ?? '') ?>" required>
          </div>
        </div>
      </fieldset>

      <button class="btn" type="submit"><?= h(t('submit_btn', $lang, $T)) ?></button>
    </form>
  <?php endif; ?>
</div>
<script>
  // Server-side min-fill-time check (api/v1/signup/request.php) compares
  // against this - set once, at load, not on submit.
  document.querySelector('input[name=form_loaded_at]').value = Date.now();

  // Live preview only - mirrors relay_calculate_connection_pricing() in
  // src/signup/signup_lib.php. The actual stored price is always
  // recomputed server-side from the submitted unit count in
  // api/v1/signup/request.php, never trusted from here.
  function calculatePricingPreview() {
    var rows = document.querySelectorAll('input[name^="unit_title"]');
    var unitCount = 0;
    rows.forEach(function (titleInput, i) {
      var rateInput = document.querySelector('input[name="unit_rate[' + i + ']"]');
      if (titleInput.value.trim() !== '' && rateInput && rateInput.value.trim() !== '') {
        unitCount++;
      }
    });

    var oneTime = unitCount > 0 ? 30 + Math.max(0, unitCount - 1) * 3 : 0;
    var tiers = [[3, 3.00], [3, 2.55], [4, 2.10], [Infinity, 1.65]];
    var remaining = unitCount;
    var monthly = 0;
    for (var t = 0; t < tiers.length && remaining > 0; t++) {
      var take = Math.min(remaining, tiers[t][0]);
      monthly += take * tiers[t][1];
      remaining -= take;
    }

    document.getElementById('pricing_one_time').textContent = '€' + oneTime.toFixed(2);
    document.getElementById('pricing_monthly').textContent = '€' + monthly.toFixed(2);
  }
  document.querySelectorAll('input[name^="unit_title"], input[name^="unit_rate"]').forEach(function (el) {
    el.addEventListener('input', calculatePricingPreview);
  });
  calculatePricingPreview();
</script>
</body>
</html>
