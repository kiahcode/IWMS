<?php
require_once "database.php";
require_login();

$item = (int)($_GET["item_id"] ?? 0);
$wh = (int)($_GET["warehouse_id"] ?? 0);
$from = $_GET["date_from"] ?? "";
$to = $_GET["date_to"] ?? "";
$sql = "SELECT sm.id,sm.item_id,sm.warehouse_id,sm.date,sm.reference_number,sm.movement_type,sm.quantity,sm.remarks,u.username,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name FROM stock_movements sm JOIN items i ON i.id=sm.item_id JOIN warehouses w ON w.id=sm.warehouse_id LEFT JOIN users u ON u.id=sm.user_id WHERE 1=1";
$p = [];
if ($item) {
  $sql .= " AND sm.item_id=?";
  $p[] = $item;
}
if ($wh) {
  $sql .= " AND sm.warehouse_id=?";
  $p[] = $wh;
}
if ($from) {
  $sql .= " AND sm.date>=?";
  $p[] = $from;
}
if ($to) {
  $sql .= " AND sm.date<=?";
  $p[] = $to;
}
$sql .= " ORDER BY sm.date ASC,sm.id ASC";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll();


$groups = [];
$out = [];
foreach ($rows as $r) {
  $key = $r["item_id"] . ":" . $r["warehouse_id"];
  if (!isset($groups[$key])) $groups[$key] = 0;
  $in = in_array($r["movement_type"], ["STOCK IN", "STOCK TRANSFER IN"], true) ? (float)$r["quantity"] : 0;
  $outq = in_array($r["movement_type"], ["STOCK OUT", "STOCK TRANSFER OUT"], true) ? (float)$r["quantity"] : 0;
  if ($r["movement_type"] === "STOCK ADJUSTMENT") $groups[$key] += (float)$r["quantity"];
  else $groups[$key] += $in - $outq;
  $out[] = ["id" => $r["id"], "date" => $r["date"], "item_code" => $r["item_code"], "item_name" => $r["item_name"], "item_type" => $r["item_type"], "size" => $r["size"], "warehouse_name" => $r["warehouse_name"], "reference_number" => $r["reference_number"], "movement_type" => $r["movement_type"], "stock_in" => $in, "stock_out" => $outq, "balance" => $groups[$key], "username" => $r["username"]];
}

if ($from) {
  $q = "SELECT item_id,warehouse_id,movement_type,quantity FROM stock_movements WHERE date<?";
  $pp = [$from];
  if ($item) {
    $q .= " AND item_id=?";
    $pp[] = $item;
  }
  if ($wh) {
    $q .= " AND warehouse_id=?";
    $pp[] = $wh;
  }
  $q .= " ORDER BY date ASC,id ASC";
  $st = $pdo->prepare($q);
  $st->execute($pp);
  $opening = [];
  foreach ($st->fetchAll() as $m) {
    $k = $m["item_id"] . ":" . $m["warehouse_id"];
    if (!isset($opening[$k])) $opening[$k] = 0;
    if ($m["movement_type"] === "STOCK ADJUSTMENT") $opening[$k] += (float)$m["quantity"];
    elseif (in_array($m["movement_type"], ["STOCK IN", "STOCK TRANSFER IN"], true)) $opening[$k] += (float)$m["quantity"];
    else $opening[$k] -= (float)$m["quantity"];
  }
  foreach ($out as &$r) {
    $k = null;
    foreach ($rows as $rr) {
      if ((int)$rr["id"] === (int)$r["id"]) {
        $k = $rr["item_id"] . ":" . $rr["warehouse_id"];
        break;
      }
    }
    if ($k !== null) $r["balance"] += (float)($opening[$k] ?? 0);
  }
  unset($r);
}
usort($out, function ($a, $b) {
  return strcmp((string)$b["date"], (string)$a["date"]) ?: ((int)$b["id"] - (int)$a["id"]);
});
json_response(["success" => true, "rows" => $out]);
