<?php
require __DIR__ . '/../app/core/DB.php';
$pdo = DB::pdo();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES:\n";
foreach($tables as $t){ echo $t, "\n"; }
$check = ['doc_sequences','audit_logs','migrations','sales_invoices','purchase_invoices','payments','stock'];
foreach($check as $tbl){
  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$tbl]);
  $exists = (bool)$stmt->fetchColumn();
  echo "\n[$tbl] ".($exists?'exists':'missing')."\n";
  if($exists){
    $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c){
      echo " - {$c['Field']} {$c['Type']}\n";
    }
  }
}