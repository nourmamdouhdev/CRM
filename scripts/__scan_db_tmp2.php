<?php
require __DIR__ . '/../app/core/DB.php';
$pdo = DB::pdo();
$check = ['doc_sequences','audit_logs','migrations','sales_invoices','purchase_invoices','payments','stock'];
foreach($check as $tbl){
  $q = $pdo->quote($tbl);
  $exists = (bool)$pdo->query("SHOW TABLES LIKE $q")->fetchColumn();
  echo "[$tbl] ".($exists?'exists':'missing')."\n";
  if($exists){
    $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c){
      echo " - {$c['Field']} {$c['Type']}\n";
    }
  }
  echo "\n";
}