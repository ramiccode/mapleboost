<?php
/**
 * MapleBoost site configuration.
 * Centralized constants, affiliate IDs, and link routing.
 * Edit affiliate URLs here only - never inline in pages.
 */

// ---------- Site identity ----------
define('SITE_NAME',        'MapleBoost');
define('SITE_TAGLINE',     'Start and grow your business in Canada');
define('SITE_URL',         'https://mapleboost.ca');
define('SITE_DESCRIPTION', 'Independent guides, calculators, and reviews to help you start, incorporate, and grow a business in Canada. Federal and provincial coverage for ON, QC, BC, and AB.');
define('SITE_AUTHOR',      'MapleBoost');
define('SITE_EMAIL',       'hello@mapleboost.ca');
define('SITE_ADDRESS',     'PMB #869 6D - 7398 Yonge St, Thornhill, ON L4J 8J2, Canada');
define('SITE_LOCALE',      'en_CA');

// ---------- Affiliate registry ----------
// Map of affiliate id => [destination URL, display name, vertical, network].
// Update URLs here to rotate offers without touching content pages.
$AFFILIATES = [
    // Incorporation
    'ownr'             => ['url' => 'https://partners.ownr.co/mb',                       'name' => 'Ownr',             'vertical' => 'incorporation',  'network' => 'direct'],
    'opstart'          => ['url' => 'https://www.opstart.ca/?ref=mapleboost',             'name' => 'Opstart',          'vertical' => 'incorporation',  'network' => 'direct'],
    'mycorporation'    => ['url' => 'https://www.mycorporation.com/business-formations/canada-incorporate.jsp', 'name' => 'MyCorporation', 'vertical' => 'incorporation', 'network' => 'direct'],
    'lawdepot'         => ['url' => 'https://www.lawdepot.ca/contracts/incorporation/?pid=pg-V0U2FRZPWL-incorporationtextlink&loc=CA', 'name' => 'LawDepot', 'vertical' => 'incorporation', 'network' => 'direct'],

    // Accounting
    'zohobooks'        => ['url' => 'https://go.zoho.com/XWO',                             'name' => 'Zoho Books',       'vertical' => 'accounting',     'network' => 'direct'],
    'quickbooks'       => ['url' => 'https://go.zoho.com/XWO',                             'name' => 'Zoho Books',       'vertical' => 'accounting',     'network' => 'direct'], // legacy alias -> Zoho Books (keeps old /go?id=quickbooks links working)
    'xero'             => ['url' => 'https://www.xero.com/ca/?ref=mapleboost',            'name' => 'Xero',             'vertical' => 'accounting',     'network' => 'partnerstack'],
    'freshbooks'       => ['url' => 'https://www.freshbooks.com/?ref=mapleboost',         'name' => 'FreshBooks',       'vertical' => 'accounting',     'network' => 'shareasale'],
    'wave'             => ['url' => 'https://www.waveapps.com/?ref=mapleboost',           'name' => 'Wave',             'vertical' => 'accounting',     'network' => 'direct'],

    // Banking + cards
    'wealthsimple_biz' => ['url' => 'https://wealthsimple.com/invite/PBCGPN','name' => 'Wealthsimple',     'vertical' => 'banking',        'network' => 'direct'],
    'wise_biz'         => ['url' => 'https://wise.com/business?ref=mapleboost',           'name' => 'Wise Business',    'vertical' => 'banking',        'network' => 'direct'],
    'float'            => ['url' => 'https://floatfinancial.com/?ref=mapleboost',         'name' => 'Float',            'vertical' => 'cards',          'network' => 'direct'],
    'amex_biz'         => ['url' => 'https://www.americanexpress.com/ca/business?ref=mapleboost','name'=>'Amex Business','vertical' => 'cards',          'network' => 'direct'],

    // Payroll / HR / Legal
    'wagepoint'        => ['url' => 'https://wagepoint.com/?ref=mapleboost',              'name' => 'Wagepoint',        'vertical' => 'payroll',        'network' => 'partnerstack'],
    'humi'             => ['url' => 'https://humi.ca/?ref=mapleboost',                    'name' => 'Humi',             'vertical' => 'hr',             'network' => 'partnerstack'],
    // CRM (PartnerStack-heavy)
    'hubspot'          => ['url' => 'https://www.hubspot.com/?ref=mapleboost',            'name' => 'HubSpot',          'vertical' => 'crm',            'network' => 'partnerstack'],
    'pipedrive'        => ['url' => 'https://www.pipedrive.com/?ref=mapleboost',          'name' => 'Pipedrive',        'vertical' => 'crm',            'network' => 'partnerstack'],
    'monday'           => ['url' => 'https://try.monday.com/mapleboost',                  'name' => 'monday.com',       'vertical' => 'crm',            'network' => 'partnerstack'],

    // The Maple Stack (single-pick toolkit)
    'hopp'             => ['url' => 'https://get.business.gethopp.com/mb',                'name' => 'Hopp Business',    'vertical' => 'travel',         'network' => 'direct'],
    'navan'            => ['url' => 'https://get.navan.com/mb',                           'name' => 'Navan',            'vertical' => 'travel-expense', 'network' => 'direct'],
    'kit'              => ['url' => 'https://partners.kit.com/mb',                        'name' => 'Kit',              'vertical' => 'email-marketing','network' => 'partnerstack'],
    'jane'             => ['url' => 'https://janesoftware.partnerlinks.io/mb',            'name' => 'Jane',             'vertical' => 'practice-mgmt',  'network' => 'partnerlinks'],
    'keeper'           => ['url' => 'https://keepersecurity.partnerlinks.io/yo7wjv6b3u6g', 'name' => 'Keeper Security',  'vertical' => 'security',       'network' => 'partnerlinks'],
    'payoneer'         => ['url' => 'https://payoneer557.partnerlinks.io/mb',             'name' => 'Payoneer',         'vertical' => 'eor',            'network' => 'partnerlinks'],
];

// ---------- Helpers ----------

/** Build a cloaked affiliate link. */
function aff_link($id, $anchor = null) {
    global $AFFILIATES;
    $anchor = $anchor ?: ($AFFILIATES[$id]['name'] ?? $id);
    return '<a href="/go?id=' . htmlspecialchars($id) . '" rel="sponsored nofollow noopener" target="_blank">' . htmlspecialchars($anchor) . '</a>';
}

/** Get raw destination URL (used by go.php). */
function aff_destination($id) {
    global $AFFILIATES;
    return $AFFILIATES[$id]['url'] ?? null;
}

/** Page-level metadata: each page sets $page = [...] before including header.php. */
function page_meta($key, $default = '') {
    global $page;
    return $page[$key] ?? $default;
}

/** Render absolute canonical URL for current request. */
function canonical_url() {
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return SITE_URL . rtrim($path, '/');
}
