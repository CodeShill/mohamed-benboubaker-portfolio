<?php
// Copy to amazon_sp_config.php and store it OUTSIDE the web root (never commit the real file).
return [
  // Seller Central > Apps & Services > Develop Apps (SP-API app)
  'client_id'     => 'FILL_ME',
  'client_secret' => 'FILL_ME',
  'refresh_token' => 'FILL_ME',
  // Seller Central > Settings > Account Info > Merchant Token
  'seller_id'     => 'FILL_ME',

  // Dolibarr database
  'db_host' => 'localhost',
  'db_user' => 'FILL_ME',
  'db_pass' => 'FILL_ME',
  'db_name' => 'FILL_ME',

  // Optional handling time (days) per ERP reference; other SKUs use the account default
  'lead_time_days' => [
    'PX-A100+PX-B200+PX-C300' => 3,
  ],
];
